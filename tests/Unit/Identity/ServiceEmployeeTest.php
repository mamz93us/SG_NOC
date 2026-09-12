<?php

use App\Models\AllowedDomain;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Services\Identity\OracleHrImportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People Oracle knows and Entra does not: drivers, guards, warehouse and
 * cleaning staff. They reach the NOC only through the Oracle HR import, as
 * service employees with no mailbox, and only so their fingerprint punches can
 * be recognised.
 *
 * Binds Tests\TestCase by hand WITHOUT RefreshDatabase, like BioTimeSyncTest:
 * several migrations in this repo are MySQL-only and cannot run on SQLite, so
 * the few tables this touches are created here.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    AllowedDomain::clearCache();

    foreach (['hr_import_rows', 'hr_import_batches', 'allowed_domains', 'employees', 'departments', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    // hr_import_batches carries the uploader's id.
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
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('azure_id')->nullable();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('employee_type', 20)->default(Employee::TYPE_STANDARD);
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('department_id')->nullable();
        $t->string('job_title')->nullable();
        $t->string('gender')->nullable();
        $t->string('mobile_phone')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('oracle_dept_no')->nullable();
        $t->string('oracle_department')->nullable();
        $t->string('oracle_location')->nullable();
        $t->timestamp('oracle_synced_at')->nullable();
        $t->string('status')->default('active');
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->timestamps();
    });

    foreach (['create_hr_import_batches', 'create_hr_import_rows', 'add_gender_to_hr_import_rows',
        'add_mailbox_columns_to_hr_import_rows'] as $name) {
        foreach (glob(database_path('migrations/*'.$name.'*.php')) as $migration) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert(['id' => 1, 'name' => 'Jeddah']);
    AllowedDomain::create(['domain' => 'samirgroup.com', 'is_primary' => true]);
    AllowedDomain::create(['domain' => 'sssegypt.com']);
});

// ── The rule: is the address in the EMAIL column their own? ─────────

dataset('addresses', [
    'their own company address' => ['ahmed@samirgroup.com', 1, true, null],
    'the Egyptian company address' => ['mona@sssegypt.com', 1, true, null],
    'no address at all' => ['', 0, false, 'blank'],
    'a personal gmail' => ['driver77@gmail.com', 1, false, 'outside_domain'],
    'their manager, on three rows' => ['supervisor@samirgroup.com', 3, false, 'shared'],
    'nonsense with no @' => ['n/a', 1, false, 'blank'],
]);

it('decides whether the email is the row\'s own mailbox', function (string $email, int $count, bool $own, ?string $reason) {
    [$isOwn, $why] = (new OracleHrImportService)->mailboxOf(
        $email,
        $email === '' ? [] : [$email => $count],
        ['samirgroup.com', 'sssegypt.com'],
    );

    expect($isOwn)->toBe($own)->and($why)->toBe($reason);
})->with('addresses');

it('treats every address as a company one when no domains are configured', function () {
    AllowedDomain::query()->delete();
    AllowedDomain::clearCache();

    [$isOwn] = (new OracleHrImportService)->mailboxOf('someone@anywhere.com', ['someone@anywhere.com' => 1], []);

    expect($isOwn)->toBeTrue();
});

// ── Creating them ───────────────────────────────────────────────────

function serviceBatch(): HrImportBatch
{
    return HrImportBatch::create(['filename' => 'empsg.xlsx', 'status' => 'parsed']);
}

function serviceRow(HrImportBatch $batch, array $attributes = []): HrImportRow
{
    return HrImportRow::create(array_merge([
        'hr_import_batch_id' => $batch->id,
        'row_number' => 2,
        'emp_no' => '5001',
        'emp_name' => 'Mohamed the Driver',
        'email' => 'supervisor@samirgroup.com',
        'own_mailbox' => false,
        'mailbox_reason' => 'shared',
        'location_name' => 'Jeddah',
        'dept_name' => 'Logistics',
        'job_name' => 'Driver',
        'resolved_branch_id' => 1,
        'status' => 'unmatched',
    ], $attributes));
}

it('creates a service employee with the Oracle data and no email', function () {
    $batch = serviceBatch();
    serviceRow($batch);

    $result = (new OracleHrImportService)->createServiceEmployees($batch);

    $employee = Employee::first();

    expect($result)->toBe(['created' => 1, 'skipped' => 0])
        ->and($employee->name)->toBe('Mohamed the Driver')
        // The address on the row is their supervisor's: it must not follow them.
        ->and($employee->email)->toBeNull()
        ->and($employee->employee_type)->toBe(Employee::TYPE_SERVICE)
        ->and($employee->isService())->toBeTrue()
        ->and($employee->oracle_emp_no)->toBe('5001')
        ->and($employee->branch_id)->toBe(1)
        ->and($employee->job_title)->toBe('Driver')
        ->and($employee->azure_id)->toBeNull()
        ->and($employee->oracle_synced_at)->not->toBeNull();
});

it('does not touch rows whose person has a mailbox', function () {
    $batch = serviceBatch();
    serviceRow($batch, ['own_mailbox' => true, 'mailbox_reason' => null, 'email' => 'ahmed@samirgroup.com']);

    $result = (new OracleHrImportService)->createServiceEmployees($batch);

    expect($result['created'])->toBe(0)
        ->and(Employee::count())->toBe(0);
});

it('leaves a row alone when its Oracle number is already on somebody', function () {
    Employee::create(['name' => 'Mohamed Ali', 'oracle_emp_no' => '5001', 'status' => 'active']);

    $batch = serviceBatch();
    $row = serviceRow($batch);

    $result = (new OracleHrImportService)->createServiceEmployees($batch);

    expect($result)->toBe(['created' => 0, 'skipped' => 1])
        ->and(Employee::count())->toBe(1)
        ->and($row->fresh()->status)->toBe('unmatched')
        ->and($row->fresh()->error_note)->toContain('already on Mohamed Ali');
});

it('will not create a mail-less person as an ordinary employee', function () {
    $batch = serviceBatch();
    $row = serviceRow($batch);

    // The reviewer picked plain "create" — it must still not copy the shared address.
    (new OracleHrImportService)->resolveUnmatched($row, 'create');

    $employee = Employee::first();

    expect($employee->email)->toBeNull()
        ->and($employee->employee_type)->toBe(Employee::TYPE_SERVICE)
        ->and($row->fresh()->status)->toBe('created')
        ->and($row->fresh()->decision)->toBe('create_service');
});

it('still creates an ordinary employee for someone with their own address', function () {
    $batch = serviceBatch();
    $row = serviceRow($batch, ['own_mailbox' => true, 'mailbox_reason' => null, 'email' => 'ahmed@samirgroup.com']);

    (new OracleHrImportService)->resolveUnmatched($row, 'create');

    $employee = Employee::first();

    expect($employee->email)->toBe('ahmed@samirgroup.com')
        ->and($employee->employee_type)->toBe(Employee::TYPE_STANDARD)
        ->and($row->fresh()->decision)->toBe('create');
});

it('lists only the rows that need a service employee', function () {
    $batch = serviceBatch();
    serviceRow($batch, ['emp_no' => '5001']);   // Mohamed
    serviceRow($batch, ['emp_no' => '5002', 'emp_name' => 'Aisha the Cleaner', 'email' => null, 'mailbox_reason' => 'blank']);
    serviceRow($batch, ['emp_no' => '5003', 'own_mailbox' => true, 'email' => 'ahmed@samirgroup.com']);
    serviceRow($batch, ['emp_no' => '', 'emp_name' => 'No Oracle number']);
    serviceRow($batch, ['emp_no' => '5005', 'status' => 'matched']);

    $candidates = (new OracleHrImportService)->serviceCandidates($batch);

    expect($candidates->pluck('emp_no')->all())->toBe(['5002', '5001']);
});
