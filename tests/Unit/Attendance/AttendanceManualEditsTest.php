<?php

use App\Http\Controllers\Admin\Attendance\AttendanceAdjustmentController;
use App\Http\Controllers\Admin\Attendance\AttendanceDayController;
use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\AttendancePeriodService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * HR's manual edits to a day: the check-in and the check-out are edited
 * separately, each with its own reason, flagged separately on the day and to
 * Oracle, and listed in the day log beside the punches they override.
 *
 * The controllers are called directly, signed in as an HR admin, like
 * AttendanceOwnerPageTest — the admin middleware is not what is under test.
 * Ahmed punched 08:52 and 17:02 on Wednesday 2026-09-09; "now" is the
 * evening after.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    CarbonImmutable::setTestNow('2026-09-10 18:00:00');
    config(['cache.default' => 'array']);

    foreach (['attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'activity_logs', 'employees', 'departments', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
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
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });
    Schema::create('activity_logs', function (Blueprint $t) {
        $t->id();
        $t->string('model_type');
        $t->unsignedBigInteger('model_id');
        $t->string('model_label', 150)->nullable();
        $t->string('action');
        $t->json('changes')->nullable();
        $t->unsignedBigInteger('user_id')->nullable();
        $t->string('actor_label', 100)->nullable();
        $t->string('ip_address')->nullable();
        $t->text('user_agent')->nullable();
        $t->timestamps();
    });

    foreach (array_merge(
        glob(database_path('migrations/2026_09_10_*.php')),
        glob(database_path('migrations/2026_09_11_*.php')),
        glob(database_path('migrations/2026_09_2*_*attendance*.php')),
        glob(database_path('migrations/2026_09_12_*biotime*.php')),
    ) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert(['id' => 1, 'name' => 'Jeddah']);
    DB::table('users')->insert(['id' => 7, 'name' => 'HR Admin']);

    $admin = new User(['name' => 'HR Admin']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);

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

/** Ahmed's Wednesday: punches at 08:52 and 17:02. */
function manualEditDay(): AttendanceDay
{
    $employee = Employee::create(['name' => 'Ahmed', 'oracle_emp_no' => '1001', 'branch_id' => 1, 'hired_date' => '2020-01-01', 'status' => 'active']);
    $source = BiotimeSource::create([
        'name' => 'BioTime KSA', 'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
        'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
    ]);
    $code = BiotimeEmployee::create(['biotime_source_id' => $source->id, 'emp_code' => '1001', 'employee_id' => $employee->id]);

    foreach (['2026-09-09 08:52:00', '2026-09-09 17:02:00'] as $i => $time) {
        AttendancePunch::create([
            'biotime_source_id' => $source->id, 'biotime_id' => $i + 1, 'external_id' => (string) ($i + 1),
            'biotime_employee_id' => $code->id, 'employee_id' => $employee->id, 'emp_code' => '1001',
            'punch_time' => $time, 'punch_state' => (string) $i, 'terminal_alias' => 'JED Main Entrance', 'synced_at' => now(),
        ]);
    }

    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-09', '2026-09-09');

    return AttendanceDay::where('work_date', '2026-09-09')->sole();
}

/** @param  array<string, string>  $data */
function manualEdit(AttendanceDay $day, array $data): RedirectResponse
{
    $request = Request::create("/admin/attendance/days/{$day->id}/adjustments", 'POST', $data);
    $request->setUserResolver(fn () => auth()->user());
    $request->setLaravelSession(app('session.store'));

    return app(AttendanceAdjustmentController::class)->store($request, $day->fresh(), app(AttendanceDayProcessor::class));
}

it('edits the check-in and the check-out separately, each with its own reason', function () {
    $day = manualEditDay();

    manualEdit($day, ['action' => 'check_in', 'time' => '2026-09-09T08:30', 'reason' => 'Forgot to punch in, manager confirmed 08:30']);
    manualEdit($day, ['action' => 'check_out', 'time' => '2026-09-09T17:45', 'reason' => 'Stayed for the stock count']);

    $edits = AttendanceAdjustment::whereNull('revoked_at')->orderBy('id')->get();
    $day = $day->fresh();

    expect($edits)->toHaveCount(2)
        ->and($edits[0]->kinds())->toBe(['check_in'])
        ->and($edits[0]->reason)->toBe('Forgot to punch in, manager confirmed 08:30')
        ->and($edits[1]->kinds())->toBe(['check_out'])
        ->and($edits[1]->reason)->toBe('Stayed for the stock count')
        ->and($day->first_in->format('H:i'))->toBe('08:30')
        ->and($day->last_out->format('H:i'))->toBe('17:45')
        ->and($day->checkInEdited())->toBeTrue()
        ->and($day->checkOutEdited())->toBeTrue()
        ->and($day->flags)->not->toContain(AttendanceDayBuilder::FLAG_ADJUSTED)
        ->and(AttendancePunch::count())->toBe(2);
});

it('replaces only the earlier edit of the same side', function () {
    $day = manualEditDay();

    manualEdit($day, ['action' => 'check_in', 'time' => '2026-09-09T08:30', 'reason' => 'First look']);
    manualEdit($day, ['action' => 'check_out', 'time' => '2026-09-09T17:45', 'reason' => 'Stock count']);
    manualEdit($day, ['action' => 'check_in', 'time' => '2026-09-09T08:40', 'reason' => 'Camera shows 08:40']);

    expect(AttendanceAdjustment::whereNull('revoked_at')->orderBy('id')->pluck('reason')->all())->toBe(['Stock count', 'Camera shows 08:40'])
        ->and(AttendanceAdjustment::whereNotNull('revoked_at')->sole()->reason)->toBe('First look')
        ->and($day->fresh()->first_in->format('H:i'))->toBe('08:40')
        ->and($day->fresh()->last_out->format('H:i'))->toBe('17:45');
});

it('refuses a check-out before the check-in, and an edit with no reason', function () {
    $day = manualEditDay();

    manualEdit($day, ['action' => 'check_out', 'time' => '2026-09-09T08:00', 'reason' => 'Typo']);

    expect(session('error'))->toContain('after the check-in')
        ->and(AttendanceAdjustment::count())->toBe(0)
        ->and(fn () => manualEdit($day, ['action' => 'check_in', 'time' => '2026-09-09T08:30']))->toThrow(ValidationException::class);
});

it('revokes one edit and keeps the other', function () {
    $day = manualEditDay();
    manualEdit($day, ['action' => 'check_in', 'time' => '2026-09-09T08:30', 'reason' => 'In']);
    manualEdit($day, ['action' => 'check_out', 'time' => '2026-09-09T17:45', 'reason' => 'Out']);

    app(AttendanceAdjustmentController::class)->revoke(AttendanceAdjustment::whereNotNull('check_in')->sole(), app(AttendanceDayProcessor::class));

    $day = $day->fresh();
    expect($day->first_in->format('H:i'))->toBe('08:52')
        ->and($day->checkInEdited())->toBeFalse()
        ->and($day->last_out->format('H:i'))->toBe('17:45')
        ->and($day->checkOutEdited())->toBeTrue();
});

it('lists each active edit in the day log, with the time it replaced', function () {
    $day = manualEditDay();
    manualEdit($day, ['action' => 'check_in', 'time' => '2026-09-09T08:30', 'reason' => 'Forgot to punch in']);

    $log = app(AttendanceDayController::class)->show($day->fresh())->getData()['log'];

    expect(array_map(fn (array $row) => [$row['time']->format('H:i:s'), $row['role'], $row['edit']?->reason], $log))->toBe([
        ['08:30:00', 'in', 'Forgot to punch in'],
        ['08:52:00', null, null],
        ['17:02:00', 'out', null],
    ])
        ->and($log[0]['replaces'])->toBe('08:52:00')
        ->and($log[0]['edit']->createdBy->name)->toBe('HR Admin');
});

it('tells Oracle which side HR edited', function () {
    $day = manualEditDay();
    manualEdit($day, ['action' => 'check_out', 'time' => '2026-09-09T17:45', 'reason' => 'Stock count']);

    $record = AttendancePeriodService::record($day->fresh()->load(['employee', 'shift', 'branch']));

    expect($record)->toMatchArray([
        'check_out' => '2026-09-09 17:45:00',
        'corrected' => true,
        'check_in_adjusted' => false,
        'check_out_adjusted' => true,
    ]);
});

it('splits an old two-time correction and relabels the day it built, locked or not', function () {
    $day = manualEditDay();
    $old = AttendanceAdjustment::create([
        'employee_id' => $day->employee_id, 'work_date' => '2026-09-09',
        'check_in' => '2026-09-09 08:30:00', 'check_out' => '2026-09-09 17:45:00',
        'reason' => 'Both times, one reason', 'created_by' => 7,
    ]);
    DB::table('attendance_days')->where('id', $day->id)
        ->update(['flags' => json_encode(['adjusted']), 'attendance_adjustment_id' => $old->id, 'locked' => true]);

    (require database_path('migrations/2026_09_14_100001_split_attendance_edits_by_side.php'))->up();

    $rows = AttendanceAdjustment::orderBy('id')->get();
    expect($rows->map->kinds()->all())->toBe([['check_in'], ['check_out']])
        ->and($rows->pluck('reason')->unique()->all())->toBe(['Both times, one reason'])
        ->and($rows->pluck('created_by')->unique()->all())->toBe([7])
        ->and($day->fresh()->flags)->toBe([AttendanceDayBuilder::FLAG_CHECK_IN_ADJUSTED, AttendanceDayBuilder::FLAG_CHECK_OUT_ADJUSTED])
        ->and($day->fresh()->locked)->toBeTrue();
});
