<?php

use App\Models\AllowedDomain;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
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
        $t->string('oracle_job_category')->nullable();
        $t->string('oracle_profession')->nullable();
        $t->date('oracle_contract_end_date')->nullable();
        $t->string('oracle_manager_name')->nullable();
        $t->string('oracle_manager_email')->nullable();
        $t->string('oracle_supervisor_name')->nullable();
        $t->string('oracle_supervisor_email')->nullable();
        $t->unsignedBigInteger('manager_id')->nullable();
        $t->unsignedBigInteger('supervisor_id')->nullable();
        $t->timestamp('oracle_synced_at')->nullable();
        $t->string('status')->default('active');
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->timestamps();
    });

    foreach (['create_hr_import_batches', 'create_hr_import_rows', 'add_gender_to_hr_import_rows',
        'add_mailbox_columns_to_hr_import_rows', 'add_oracle_portal_fields_to_hr_import_tables',
        'add_oracle_profile_fields_to_hr_import_rows'] as $name) {
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

/**
 * A client answering /employees with whatever the test hands it, and
 * /vacationDetails with leave that — like Oracle's since 2026-09-27 — is dated
 * a day early, which is what the sync judges the date offset from.
 */
function portalEmployeesApi(array $rows, ?array $leave = null): PortalApiClient
{
    return new class($rows, $leave ?? oracleLeaveDayEarly()) extends PortalApiClient
    {
        public function __construct(private array $rows, private array $leave) {}

        public function employees(?string $personNumber = null, ?PortalSetting $settings = null): array
        {
            return $this->rows;
        }

        public function vacationDetails(?string $personNumber = null, ?PortalSetting $settings = null): array
        {
            return $this->leave;
        }
    };
}

/** 150 leave records starting Sunday to Thursday, each sent a day early. */
function oracleLeaveDayEarly(int $shift = -1): array
{
    $rows = [];
    $day = Carbon\CarbonImmutable::parse('2026-08-02'); // a Sunday

    while (count($rows) < 150) {
        if (! in_array($day->dayOfWeek, [5, 6], true)) {
            $rows[] = ['personNumber' => '1', 'absenceType' => 'Annual Leave',
                'startDate' => $day->addDays($shift)->toDateString(), 'endDate' => $day->addDays($shift)->toDateString()];
        }
        $day = $day->addDay();
    }

    return $rows;
}

function employeeSyncWith(array $rows, ?array $leave = null): EmployeeSync
{
    return new EmployeeSync(
        portalEmployeesApi($rows, $leave),
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
        'department' => 'Some Division - Jeddah',
        'locationName' => 'Jeddah',
        'jobName' => 'Technician',
        'gender' => 'M',
        'personId' => '10000000000'.$number,
        'phone' => '0555'.str_pad($number, 6, '0', STR_PAD_LEFT),
        'status' => 'Active',
        // Oracle means 2009-10-17: its API writes every date a day early.
        'startDate' => '2009-10-16',
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
        oracleEmployee('1001', ['status' => 'Inactive']),
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
        $rows[] = oracleEmployee((string) (6000 + $i), ['status' => 'Inactive']);
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
        oracleEmployee('1001', ['status' => 'Inactive']),
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
        oracleEmployee('1001', ['status' => 'Inactive']),
    ]))->sync();

    expect($employee->refresh()->oracle_assignment_status)->toBe('INACTIVE');
});

it('applies the Arabic name, mobile and hire date the API now carries, without blanking DEPT NO', function () {
    // Oracle's API has no DEPT NO. Before 2026-09-29 applying an API row wrote
    // its null over the one the spreadsheet had set.
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'oracle_dept_no' => '4410']);

    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['personNameAr' => 'شخص ألف', 'phone' => '0551234567']),
    ]))->sync();

    $row = HrImportRow::where('hr_import_batch_id', $result['batch']->id)->where('emp_no', '1001')->sole();

    expect($row->status)->toBe('matched');

    app(OracleHrImportService::class)->applyMatched($row);

    $employee->refresh();

    expect($employee->oracle_dept_no)->toBe('4410')
        ->and($employee->name_ar)->toBe('شخص ألف')
        ->and($employee->mobile_phone)->toBe('+966551234567')
        ->and($employee->oracle_person_id)->toBe('100000000001001')
        ->and($employee->hired_date?->toDateString())->toBe('2009-10-17');
});

/** What Oracle says about somebody beyond what the spreadsheet ever held. */
function oracleProfile(array $overrides = []): array
{
    return array_merge([
        'jobCategory' => 'AppDev&prog&analysis',
        'profession' => 'محلل نظم المعلومات',
        'contractEndDate' => '02-DEC-27',
        'managerName' => 'Mona Manager',
        'managerEmail' => 'Mona.Manager@samirgroup.com',
        'supervisorName' => 'Sami Supervisor',
        'supervisorEmail' => 'sami.supervisor@samirgroup.com',
    ], $overrides);
}

it('records what Oracle says on the profile of everyone holding their Oracle number, without review', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active']);

    employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001', oracleProfile())]))->sync();

    $employee->refresh();

    expect($employee->oracle_job_category)->toBe('AppDev&prog&analysis')
        ->and($employee->oracle_profession)->toBe('محلل نظم المعلومات')
        // As sent: only the start date carries Oracle's day-early error.
        ->and($employee->oracle_contract_end_date?->toDateString())->toBe('2027-12-02')
        ->and($employee->oracle_manager_name)->toBe('Mona Manager')
        ->and($employee->oracle_manager_email)->toBe('mona.manager@samirgroup.com')
        ->and($employee->oracle_supervisor_name)->toBe('Sami Supervisor')
        ->and($employee->oracle_supervisor_email)->toBe('sami.supervisor@samirgroup.com');
});

it('never turns Oracle\'s manager or supervisor into the NOC\'s reporting line', function () {
    // manager_id and supervisor_id decide whose attendance and leave somebody
    // may read. Oracle naming a person is not that decision.
    $mona = Employee::create(['name' => 'Mona Manager', 'email' => 'mona.manager@samirgroup.com',
        'branch_id' => 1, 'status' => 'active']);
    $current = Employee::create(['name' => 'Current Manager', 'email' => 'current@samirgroup.com',
        'branch_id' => 1, 'status' => 'active']);
    $withManager = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'manager_id' => $current->id]);
    $without = Employee::create(['name' => 'Person 1002', 'email' => 'person1002@samirgroup.com',
        'oracle_emp_no' => '1002', 'branch_id' => 1, 'status' => 'active']);

    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', oracleProfile()),
        oracleEmployee('1002', oracleProfile()),
    ]))->sync();

    app(OracleHrImportService::class)->applyBatchMatched($result['batch']);

    expect($withManager->refresh()->manager_id)->toBe($current->id)
        ->and($withManager->supervisor_id)->toBeNull()
        ->and($without->refresh()->manager_id)->toBeNull()
        ->and($without->supervisor_id)->toBeNull()
        ->and($without->oracle_manager_email)->toBe($mona->email);
});

it('clears what Oracle no longer says, so a withdrawn contract end does not stay on the profile', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active',
        'oracle_contract_end_date' => '2026-12-02', 'oracle_job_category' => 'Sales',
        'oracle_manager_name' => 'Old Manager', 'oracle_manager_email' => 'old.manager@samirgroup.com']);

    // Other people still carry every field, so Oracle has not dropped a column.
    employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', oracleProfile(['contractEndDate' => '-', 'jobCategory' => '-',
            'managerName' => '-', 'managerEmail' => '-'])),
        oracleEmployee('1002', oracleProfile()),
    ]))->sync();

    $employee->refresh();

    expect($employee->oracle_contract_end_date)->toBeNull()
        ->and($employee->oracle_job_category)->toBeNull()
        ->and($employee->oracle_manager_name)->toBeNull()
        ->and($employee->oracle_manager_email)->toBeNull()
        // Still said, still held.
        ->and($employee->oracle_profession)->toBe('محلل نظم المعلومات');
});

it('keeps what it recorded when Oracle stops sending a field to everybody', function () {
    // Oracle dropped three columns from this list on 2026-09-27. A field no
    // row carries is a column that went away, not 617 people losing a manager.
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active',
        'oracle_profession' => 'محلل نظم المعلومات', 'oracle_contract_end_date' => '2027-12-02']);

    // oracleWorkforce() rows carry none of the profile fields.
    employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001')]))->sync();

    $employee->refresh();

    expect($employee->oracle_profession)->toBe('محلل نظم المعلومات')
        ->and($employee->oracle_contract_end_date?->toDateString())->toBe('2027-12-02');
});

it('leaves an SSS Egypt employee holding the same number alone', function () {
    $cairo = Employee::create(['name' => 'Cairo Person', 'email' => 'cairo@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 3, 'status' => 'active', 'hired_date' => '2021-03-01']);

    employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001', oracleProfile())]))->sync();

    $cairo->refresh();

    // Another person's start date would move the day their absences begin.
    expect($cairo->hired_date->toDateString())->toBe('2021-03-01');
    expect($cairo->oracle_profession)->toBeNull()
        ->and($cairo->oracle_manager_name)->toBeNull()
        ->and($cairo->oracle_contract_end_date)->toBeNull();
});

it('gives somebody linked from the review page the same fields', function () {
    // No Oracle number yet, so the sync itself cannot reach them: the row has
    // to carry everything to whoever applies or links it.
    $employee = Employee::create(['name' => 'Someone Else Entirely', 'email' => 'other.address@samirgroup.com',
        'branch_id' => 1, 'status' => 'active', 'hired_date' => '2026-06-07']);

    $result = employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001', oracleProfile())]))->sync();

    expect($employee->refresh()->oracle_profession)->toBeNull();

    $row = HrImportRow::where('hr_import_batch_id', $result['batch']->id)->where('emp_no', '1001')->sole();

    expect($row->manager_email)->toBe('mona.manager@samirgroup.com')
        ->and($row->contract_end_date)->toStartWith('2027-12-02');

    app(OracleHrImportService::class)->resolveUnmatched($row, 'link', $employee->id);

    $employee->refresh();

    expect($employee->oracle_emp_no)->toBe('1001')
        ->and($employee->hired_date->toDateString())->toBe('2009-10-17')
        ->and($employee->oracle_job_category)->toBe('AppDev&prog&analysis')
        ->and($employee->oracle_profession)->toBe('محلل نظم المعلومات')
        ->and($employee->oracle_contract_end_date?->toDateString())->toBe('2027-12-02')
        ->and($employee->oracle_manager_name)->toBe('Mona Manager')
        ->and($employee->oracle_supervisor_email)->toBe('sami.supervisor@samirgroup.com');
});

// ─── The Arabic name ──────────────────────────────────────────────

it('puts Oracle\'s Arabic name on the record without anybody applying a batch', function () {
    // No API batch was ever applied on NOC2, so the name staged on 617 rows
    // had reached two employees.
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active']);
    $typed = Employee::create(['name' => 'Person 1002', 'email' => 'person1002@samirgroup.com',
        'oracle_emp_no' => '1002', 'branch_id' => 1, 'status' => 'active', 'name_ar' => 'اسم قديم']);

    employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['personNameAr' => 'تغريد احمد خلاوي']),
        oracleEmployee('1002', ['personNameAr' => 'اسم من اوراكل']),
    ]))->sync();

    expect($employee->refresh()->name_ar)->toBe('تغريد احمد خلاوي')
        // Oracle's wins where it sends one.
        ->and($typed->refresh()->name_ar)->toBe('اسم من اوراكل');
});

it('keeps an Arabic name typed here when Oracle sends none', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'name_ar' => 'اسم مكتوب']);

    // oracleEmployee() carries no personNameAr.
    employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001')]))->sync();

    expect($employee->refresh()->name_ar)->toBe('اسم مكتوب');
});

// ─── Oracle's start date is the hire date ─────────────────────────

it('makes Oracle\'s start date the hire date, over the day the record was imported', function () {
    // The Entra import stamps the day it ran. 509 of 612 people held that day
    // as their hire date, and "fill only a blank" left every one of them wrong.
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'hired_date' => '2026-06-07']);

    // Oracle sends 2023-12-02 and means the 3rd: its API is a day early.
    $result = employeeSyncWith(oracleWorkforce(450, [
        oracleEmployee('1001', ['startDate' => '2023-12-02']),
    ]))->sync();

    // Without anybody applying the batch.
    expect($employee->refresh()->hired_date->toDateString())->toBe('2023-12-03')
        ->and($result['hire_dates'])->toBe(1);
});

it('fills a blank hire date and leaves a right one alone', function () {
    $blank = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active']);
    $right = Employee::create(['name' => 'Person 1002', 'email' => 'person1002@samirgroup.com',
        'oracle_emp_no' => '1002', 'branch_id' => 1, 'status' => 'active', 'hired_date' => '2009-10-17']);

    $result = employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001'), oracleEmployee('1002')]))->sync();

    expect($blank->refresh()->hired_date->toDateString())->toBe('2009-10-17')
        ->and($right->refresh()->hired_date->toDateString())->toBe('2009-10-17')
        ->and($result['hire_dates'])->toBe(1);
});

it('never blanks a hire date Oracle does not send', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'hired_date' => '2015-04-01']);

    foreach (['-', '', '2020-02-31', null] as $unusable) {
        employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001', ['startDate' => $unusable])]))->sync();

        expect($employee->refresh()->hired_date->toDateString())->toBe('2015-04-01');
    }
});

it('writes a hire date as Oracle sends it once Oracle has fixed its dates', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'hired_date' => '2026-06-07']);

    employeeSyncWith(
        oracleWorkforce(450, [oracleEmployee('1001', ['startDate' => '2023-12-03'])]),
        oracleLeaveDayEarly(0),
    )->sync();

    expect($employee->refresh()->hired_date->toDateString())->toBe('2023-12-03');
});

it('records what Oracle says on a day Oracle has not changed', function () {
    // It used to wait for the feed to move. Somebody given their Oracle number
    // on a quiet day went without their facts until it did.
    $rows = oracleWorkforce(450, [oracleEmployee('1001', oracleProfile())]);

    employeeSyncWith($rows)->sync();

    $employee = Employee::create(['name' => 'Late Link', 'email' => 'late.link@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'hired_date' => '2026-06-07']);

    $again = employeeSyncWith($rows)->sync();

    $employee->refresh();

    expect($again['unchanged'])->toBeTrue()
        ->and($again['batch'])->toBeNull()
        ->and(HrImportBatch::count())->toBe(1)
        ->and($employee->oracle_assignment_status)->toBe('ACTIVE')
        ->and($employee->oracle_profession)->not->toBeNull()
        ->and($employee->hired_date->toDateString())->toBe('2009-10-17');
});

it('logs nothing on an unchanged day that wrote nothing', function () {
    $rows = oracleWorkforce();

    employeeSyncWith($rows)->sync();
    employeeSyncWith($rows)->sync();

    expect(DB::table('activity_logs')->where('action', 'portal_employees_staged')->count())->toBe(1);
});

it('rolls back a hire date written by a dry run', function () {
    $employee = Employee::create(['name' => 'Person 1001', 'email' => 'person1001@samirgroup.com',
        'oracle_emp_no' => '1001', 'branch_id' => 1, 'status' => 'active', 'hired_date' => '2026-06-07']);

    $result = employeeSyncWith(oracleWorkforce(450, [oracleEmployee('1001')]))->sync(dryRun: true);

    expect($result['hire_dates'])->toBe(1)
        ->and($employee->refresh()->hired_date->toDateString())->toBe('2026-06-07');
});

it('corrects the day Oracle\'s hire dates are out, and stops correcting once Oracle does', function () {
    $early = employeeSyncWith(oracleWorkforce())->sync(dryRun: true);

    expect($early['date_offset'])->toBe(1);

    $fixed = employeeSyncWith(oracleWorkforce(), oracleLeaveDayEarly(0))->sync(dryRun: true);

    expect($fixed['date_offset'])->toBe(0);
});

it('stages nothing when the leave dates cannot say whether hire dates are out', function () {
    expect(fn () => employeeSyncWith(oracleWorkforce(), array_slice(oracleLeaveDayEarly(), 0, 20))->sync())
        ->toThrow(RuntimeException::class, 'too few');

    expect(HrImportBatch::count())->toBe(0);
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
