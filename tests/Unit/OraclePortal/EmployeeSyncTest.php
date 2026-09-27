<?php

use App\Models\AllowedDomain;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\OraclePortal\PortalSetting;
use App\Services\Identity\OracleHrImportService;
use App\Services\OraclePortal\EmployeeSync;
use App\Services\OraclePortal\PortalApiClient;
use App\Services\OraclePortal\PortalBook;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oracle's employee view into the NOC.
 *
 * Everything here is about one property: **the sync never terminates anybody.**
 * `EmployeeObserver` turns a transition to terminated into
 * `GraphService::disableUser()`, so a scheduled job that got this wrong would
 * take away working colleagues' mailboxes. The two ways it could go wrong are
 * both tested: reading an absence as a departure, and believing an implausible
 * number of INACTIVE rows at once.
 *
 * Built by hand without RefreshDatabase, like ServiceEmployeeTest — several
 * migrations in this repo are MySQL-only and cannot run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['oracle_portal.book.branches' => ['JED', 'RYD']]);
    AllowedDomain::clearCache();

    foreach (['hr_import_rows', 'hr_import_batches', 'allowed_domains', 'azure_branch_mappings',
        'activity_logs', 'identity_users', 'employees', 'departments', 'branches', 'users',
        'oracle_portal_settings'] as $table) {
        Schema::dropIfExists($table);
    }

    // matchEmployee() also looks an address up against Entra's own UPN and mail.
    Schema::create('identity_users', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id')->nullable();
        $t->string('user_principal_name')->nullable();
        $t->string('mail')->nullable();
        $t->boolean('account_enabled')->default(true);
        $t->timestamps();
    });

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('departments', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('allowed_domains', function (Blueprint $t) {
        $t->id();
        $t->string('domain', 100)->unique();
        $t->string('description', 200)->nullable();
        $t->boolean('is_primary')->default(false);
        $t->timestamps();
    });
    Schema::create('azure_branch_mappings', function (Blueprint $t) {
        $t->id();
        $t->string('keyword');
        $t->unsignedInteger('branch_id');
        $t->timestamps();
    });
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('model_type')->nullable();
        $t->unsignedBigInteger('model_id')->nullable();
        $t->string('model_label')->nullable();
        $t->string('action')->nullable();
        $t->json('changes')->nullable();
        $t->string('actor_label')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('ip_address')->nullable();
        $t->string('user_agent')->nullable();
        $t->timestamps();
    });
    Schema::create('oracle_portal_settings', function (Blueprint $t) {
        $t->id();
        $t->boolean('enabled')->default(false);
        $t->text('base_url')->nullable();
        $t->text('api_key')->nullable();
        $t->boolean('sync_announcements')->default(false);
        $t->boolean('sync_employees')->default(false);
        $t->boolean('sync_vacations')->default(false);
        $t->timestamp('last_announcements_sync_at')->nullable();
        $t->timestamp('last_employees_sync_at')->nullable();
        $t->timestamp('last_vacations_sync_at')->nullable();
        $t->unsignedInteger('last_announcements_count')->nullable();
        $t->unsignedInteger('last_employees_count')->nullable();
        $t->unsignedInteger('last_vacation_balances_count')->nullable();
        $t->unsignedInteger('last_vacation_records_count')->nullable();
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id')->nullable();
        $t->string('name');
        $t->string('name_ar')->nullable();
        $t->string('email')->nullable();
        $t->string('employee_type', 20)->default(Employee::TYPE_STANDARD);
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('department_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('job_title')->nullable();
        $t->string('gender')->nullable();
        $t->string('mobile_phone')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('oracle_dept_no')->nullable();
        $t->string('oracle_department')->nullable();
        $t->string('oracle_location')->nullable();
        $t->string('oracle_employee_category', 50)->nullable();
        $t->string('oracle_person_id', 30)->nullable();
        $t->string('oracle_assignment_status', 30)->nullable();
        $t->string('oracle_person_type', 50)->nullable();
        $t->timestamp('oracle_leaver_ignored_at')->nullable();
        $t->timestamp('oracle_synced_at')->nullable();
        $t->string('status')->default('active');
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->timestamps();
    });

    foreach (['create_hr_import_batches', 'create_hr_import_rows', 'add_gender_to_hr_import_rows',
        'add_mailbox_columns_to_hr_import_rows', 'add_oracle_portal_fields_to_hr_import_tables'] as $name) {
        foreach (glob(database_path('migrations/*'.$name.'*.php')) as $migration) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert([
        ['id' => 1, 'name' => 'JED'],
        ['id' => 2, 'name' => 'RYD'],
        // Cairo: in the NOC, never in this feed, and its EMP_NO series collides.
        ['id' => 3, 'name' => 'CAI'],
    ]);
    DB::table('azure_branch_mappings')->insert([
        ['keyword' => 'Jeddah', 'branch_id' => 1],
        ['keyword' => 'Riyadh', 'branch_id' => 2],
    ]);
    AllowedDomain::create(['domain' => 'samirgroup.com', 'is_primary' => true]);

    PortalSetting::create([
        'enabled' => true,
        'base_url' => 'https://example.invalid/api',
        'api_key' => 'test-key',
        'sync_employees' => true,
    ]);
});

/** A client answering /attendance with whatever the test hands it. */
function portalAttendanceApi(array $rows): PortalApiClient
{
    return new class($rows) extends PortalApiClient
    {
        public function __construct(private array $rows) {}

        public function attendance(?string $personNumber = null, ?string $personId = null, ?PortalSetting $settings = null): array
        {
            return $this->rows;
        }
    };
}

function employeeSyncWith(array $rows): EmployeeSync
{
    return new EmployeeSync(
        portalAttendanceApi($rows),
        app(OracleHrImportService::class),
        new PortalBook,
    );
}

function oracleEmployee(string $number, array $overrides = []): array
{
    return array_merge([
        'personNumber' => $number,
        'personName' => 'Person '.$number,
        'personEmail' => 'person'.$number.'@samirgroup.com',
        'employeeCategory' => 'SALES',
        'orgName' => 'Some Division - Jeddah',
        'locationName' => 'Jeddah',
        'jobName' => 'Technician',
        'gender' => 'M',
        'personId' => '10000000000'.$number,
        'assignmentId' => '30000000000'.$number,
        'assignmentStatusType' => 'ACTIVE',
        'personType' => 'Permanent Employee',
        'startDate' => '17-OCT-09',
    ], $overrides);
}

/** Enough rows to clear the first-run floor. */
function oracleWorkforce(int $count = 450, array $extra = []): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = oracleEmployee((string) (5000 + $i));
    }

    return array_merge($rows, $extra);
}

it('stages a batch without writing anything to employee records', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'job_title' => 'Old title']);

    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['jobName' => 'New title from Oracle']),
    ]))->sync();

    expect($result['batch'])->toBeInstanceOf(HrImportBatch::class)
        ->and($result['batch']->source)->toBe('api')
        ->and($result['batch']->total_rows)->toBe(451);

    // The job title waits for somebody to apply the batch.
    expect($employee->refresh()->job_title)->toBe('Old title');
});

it('records what Oracle says about an assignment but never terminates', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active']);

    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['assignmentStatusType' => 'INACTIVE']),
    ]))->sync();

    $employee->refresh();

    expect($employee->oracle_assignment_status)->toBe('INACTIVE')
        // The whole point.
        ->and($employee->status)->toBe('active')
        ->and($employee->terminated_date)->toBeNull()
        ->and($result['inactive'])->toBe(1);
});

it('does not terminate somebody the feed simply does not mention', function () {
    // The feed is the Saudi book. Every SSS Egypt employee is permanently
    // absent from it, and so is anyone Oracle has not onboarded yet.
    $cairo = Employee::create(['name' => 'Cairo Person', 'email' => 'cairo@samirgroup.com',
        'oracle_emp_no' => '9001', 'branch_id' => 3, 'status' => 'active']);

    employeeSyncWith(oracleWorkforce())->sync();

    $cairo->refresh();

    expect($cairo->status)->toBe('active')
        ->and($cairo->oracle_assignment_status)->toBeNull()
        ->and($cairo->terminated_date)->toBeNull();
});

it('never matches a row onto an employee in another book\'s branches', function () {
    // The same Oracle number names a Saudi employee in this feed and a
    // different Egyptian one in the NOC. Matching them would apply one
    // person's job and department to another.
    $cairo = Employee::create(['name' => 'Person 1001', 'email' => 'cairo1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 3, 'status' => 'active', 'job_title' => 'Cairo job']);

    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['jobName' => 'Saudi job']),
    ]))->sync();

    $row = $result['batch']->rows()->where('emp_no', '1001')->sole();

    expect($row->status)->toBe('unmatched')
        ->and($row->matched_employee_id)->toBeNull()
        ->and($row->match_method)->toBe('out_of_book')
        ->and($row->error_note)->toContain('outside this feed');

    expect($cairo->refresh()->job_title)->toBe('Cairo job');
});

it('refuses to record statuses when Oracle calls too many people inactive', function () {
    // 30 of 30 matched people at once is a broken response, not a redundancy.
    $employees = [];
    for ($i = 1; $i <= 30; $i++) {
        $employees[] = Employee::create(['name' => 'Person '.(6000 + $i),
            'email' => 'person'.(6000 + $i).'@samirgroup.com',
            'oracle_emp_no' => (string) (6000 + $i), 'branch_id' => 1, 'status' => 'active']);
    }

    $rows = [];
    for ($i = 1; $i <= 30; $i++) {
        $rows[] = oracleEmployee((string) (6000 + $i), ['assignmentStatusType' => 'INACTIVE']);
    }

    $result = employeeSyncWith(oracleWorkforce(450, $rows))->sync();

    expect($result['leavers_refused'])->toBeTrue()
        ->and($result['recorded'])->toBe(0);

    foreach ($employees as $employee) {
        expect($employee->refresh()->oracle_assignment_status)->toBeNull()
            ->and($employee->status)->toBe('active');
    }
});

it('records a believable number of leavers', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active']);

    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['assignmentStatusType' => 'INACTIVE']),
    ]))->sync();

    expect($result['leavers_refused'])->toBeFalse();
    expect($employee->refresh()->oracle_assignment_status)->toBe('INACTIVE');
});

it('clears an ignore decision once Oracle says the person is active again', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active',
        'oracle_assignment_status' => 'INACTIVE', 'oracle_leaver_ignored_at' => now()]);

    employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001')]))->sync();

    $employee->refresh();

    expect($employee->oracle_assignment_status)->toBe('ACTIVE')
        ->and($employee->oracle_leaver_ignored_at)->toBeNull();
});

it('matches an Oracle number held with leading zeros', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '01001', 'branch_id' => 1, 'status' => 'active']);

    employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['assignmentStatusType' => 'INACTIVE']),
    ]))->sync();

    expect($employee->refresh()->oracle_assignment_status)->toBe('INACTIVE');
});

it('changes nothing when the response is too small to believe', function () {
    expect(fn () => employeeSyncWith([oracleEmployee('1001')])->sync())
        ->toThrow(RuntimeException::class);

    expect(HrImportBatch::count())->toBe(0);
});

it('creates no second batch when Oracle has not changed', function () {
    $rows = oracleWorkforce();

    employeeSyncWith($rows)->sync();
    $again = employeeSyncWith($rows)->sync();

    expect($again['unchanged'])->toBeTrue()
        ->and($again['batch'])->toBeNull()
        ->and(HrImportBatch::count())->toBe(1);
});

it('creates a batch when Oracle does change', function () {
    employeeSyncWith(oracleWorkforce())->sync();

    $changed = employeeSyncWith(oracleWorkforce(450, [oracleEmployee('7777')]))->sync();

    expect($changed['unchanged'])->toBeFalse()
        ->and(HrImportBatch::count())->toBe(2);
});

it('ignores a reordered response, which is not a change', function () {
    $rows = oracleWorkforce();
    employeeSyncWith($rows)->sync();

    $again = employeeSyncWith(array_reverse($rows))->sync();

    expect($again['unchanged'])->toBeTrue();
});

it('refuses to run at all when the book names no real branch', function () {
    // Without a branch list there is no way to tell a SamirGroup employee from
    // an SSS Egypt one holding the same Oracle number, and this feed decides
    // who gets listed as having left.
    config(['oracle_portal.book.branches' => ['NOPE']]);

    expect(fn () => employeeSyncWith(oracleWorkforce())->sync())
        ->toThrow(RuntimeException::class);
});

it('rolls a dry run back completely', function () {
    $result = employeeSyncWith(oracleWorkforce())->sync(dryRun: true);

    expect($result['dry_run'])->toBeTrue()
        ->and(HrImportBatch::count())->toBe(0);
});

it('writes one audit row for the run', function () {
    DB::table('activity_logs')->delete();

    employeeSyncWith(oracleWorkforce())->sync();

    $logs = DB::table('activity_logs')->where('action', 'portal_employees_staged')->get();

    expect($logs)->toHaveCount(1);
});
