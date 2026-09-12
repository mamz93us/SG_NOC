<?php

use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Ticketing\TicketRequestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The home portal assistant's attendance tool. What is being defended here is
 * one thing: an employee can read their OWN punches and nobody else's.
 *
 * The toolbox is built in AssistantController from the session, and this tool
 * takes no employee argument at all, so the model has nothing to point
 * elsewhere. These tests pin that shut — including against a turn that tries.
 *
 * 2026-09-10 is a Thursday; the clock is frozen there.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    CarbonImmutable::setTestNow('2026-09-10 18:00:00');
    config(['cache.default' => 'array']);

    foreach (['attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'employees', 'departments', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

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
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });

    // The 09-11 batch adds the periods and exports tables plus `locked` on
    // attendance_days. AttendanceDayProcessor reads attendance_periods through
    // PeriodLocks on every build, so it fails without them.
    foreach (array_merge(
        glob(database_path('migrations/2026_09_10_*.php')),
        glob(database_path('migrations/2026_09_11_*.php')),
    ) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert(['id' => 1, 'name' => 'Jeddah']);

    AttendanceShiftAssignment::create([
        'attendance_shift_id' => AttendanceShift::create([
            'name' => 'Office', 'start_time' => '09:00', 'end_time' => '17:00',
            'grace_in_minutes' => 10, 'grace_out_minutes' => 5, 'max_hours' => 12,
            'min_overtime_minutes' => 30, 'off_days' => [5, 6], 'is_active' => true,
        ])->id,
        'scope_type' => 'all',
        'scope_id' => null,
        'effective_from' => '2020-01-01',
    ]);
});

function toolPerson(string $name, string $email, string $code): Employee
{
    $employee = Employee::create([
        'name' => $name, 'email' => $email, 'oracle_emp_no' => $code,
        'branch_id' => 1, 'hired_date' => '2020-01-01', 'status' => 'active',
    ]);

    $source = BiotimeSource::firstOrCreate(['name' => 'BioTime KSA'], [
        'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
        'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
    ]);

    BiotimeEmployee::create([
        'biotime_source_id' => $source->id, 'emp_code' => $code, 'employee_id' => $employee->id,
    ]);

    return $employee;
}

function toolPunch(Employee $employee, string $time): void
{
    AttendancePunch::create([
        'biotime_source_id' => BiotimeSource::first()->id,
        'biotime_id' => (int) AttendancePunch::max('biotime_id') + 1,
        'biotime_employee_id' => BiotimeEmployee::where('employee_id', $employee->id)->value('id'),
        'employee_id' => $employee->id,
        'emp_code' => $employee->oracle_emp_no,
        'punch_time' => $time,
        'punch_state' => '0',
        'synced_at' => now(),
    ]);
}

function toolboxFor(?Employee $employee): AssistantToolbox
{
    return new AssistantToolbox(
        new User(['name' => $employee?->name ?? 'Nobody', 'email' => $employee?->email ?? 'nobody@samirgroup.com']),
        $employee,
        null,
        app(KnowledgeRetriever::class),
        app(TicketRequestService::class),
    );
}

/** Ahmed (asking) and Mona (not), each with their own punches today. */
function twoPeople(): array
{
    $ahmed = toolPerson('Ahmed', 'ahmed@samirgroup.com', '1001');
    $mona = toolPerson('Mona', 'mona@samirgroup.com', '1002');

    toolPunch($ahmed, '2026-09-10 08:55:00');
    toolPunch($ahmed, '2026-09-10 17:30:00');
    toolPunch($mona, '2026-09-10 07:11:00');
    toolPunch($mona, '2026-09-10 16:02:00');

    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');

    return [$ahmed, $mona];
}

it('offers no way to name another employee', function () {
    $definition = collect(toolboxFor(null)->definitions())
        ->firstWhere('function.name', 'get_my_attendance');

    expect($definition)->not->toBeNull()
        ->and(array_keys((array) $definition['function']['parameters']['properties']))->toBe(['period', 'month'])
        ->and($definition['function']['parameters']['required'])->toBe([]);
});

it('returns the signed-in employee\'s own day', function () {
    [$ahmed] = twoPeople();

    $result = toolboxFor($ahmed)->call('get_my_attendance', ['period' => 'today']);

    expect($result['period'])->toBe('Today')
        ->and($result['days'])->toHaveCount(1)
        ->and($result['days'][0]['check_in'])->toBe('08:55')
        ->and($result['days'][0]['check_out'])->toBe('17:30')
        ->and($result['days'][0]['status'])->toBe('Present')
        ->and($result['summary']['present'])->toBe(1);
});

it('ignores any attempt to ask for someone else', function () {
    [$ahmed, $mona] = twoPeople();

    // Everything a model could invent to redirect the lookup.
    $result = toolboxFor($ahmed)->call('get_my_attendance', [
        'period' => 'today',
        'employee_id' => $mona->id,
        'employee' => 'Mona',
        'email' => $mona->email,
        'emp_code' => $mona->oracle_emp_no,
    ]);

    $json = json_encode($result);

    expect($result['days'][0]['check_in'])->toBe('08:55')
        ->and($json)->not->toContain('07:11')   // Mona's check-in
        ->and($json)->not->toContain('16:02')   // Mona's check-out
        ->and($json)->not->toContain('Mona');
});

it('adds the month up for the employee who asked', function () {
    [$ahmed] = twoPeople();
    toolPunch($ahmed, '2026-09-09 09:40:00');   // late, and no check-out
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');

    $result = toolboxFor($ahmed)->call('get_my_attendance', ['period' => 'this_month']);

    expect($result['summary']['present'])->toBe(2)
        ->and($result['summary']['late_days'])->toBe(1)
        ->and($result['summary']['late_total_minutes'])->toBe(40)
        ->and($result['summary']['missing_check_outs'])->toBe(1)
        // The hours of a day with no check-out are missing, not zero worked.
        ->and($result['note'])->toContain('no check-out')
        ->and($result['summary']['total_worked'])->toBe('8:35 (h:mm)');
});

it('reads a named past month', function () {
    [$ahmed] = twoPeople();

    $result = toolboxFor($ahmed)->call('get_my_attendance', ['month' => '2026-08']);

    expect($result['period'])->toBe('August 2026')
        ->and($result['from'])->toBe('2026-08-01')
        ->and($result['to'])->toBe('2026-08-31');
});

it('explains itself when the employee has no fingerprint code', function () {
    $employee = Employee::create(['name' => 'New Starter', 'email' => 'new@samirgroup.com', 'status' => 'active']);

    $result = toolboxFor($employee)->call('get_my_attendance', ['period' => 'today']);

    expect($result)->toHaveKey('error')
        ->and($result['error'])->toContain('not linked')
        ->and($result)->not->toHaveKey('days');
});

it('explains itself when the account has no HR record at all', function () {
    $result = toolboxFor(null)->call('get_my_attendance', ['period' => 'today']);

    expect($result)->toHaveKey('error')
        ->and($result)->not->toHaveKey('days');
});
