<?php

use App\Http\Controllers\Admin\Attendance\AttendanceMonthController;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendanceHoliday;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\MonthlyDay;
use App\Services\Attendance\MonthlySheet;
use App\Services\Attendance\MonthlyTotals;
use App\Services\Attendance\ShiftResolver;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

/**
 * The monthly sheet against a real database: the days it builds from punches,
 * the totals, and the picker's own SQL.
 *
 * September 2026 — the 1st is a Tuesday, the 10th a Thursday, Friday and
 * Saturday are the shift's days off. "Now" is frozen on the 30th at midday, so
 * every earlier day has ended and the 30th itself has not.
 *
 * Binds Tests\TestCase by hand WITHOUT RefreshDatabase, like BioTimeSyncTest:
 * several migrations in this repo are MySQL-only and cannot run on SQLite.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
    CarbonImmutable::setTestNow('2026-09-30 12:00:00');
    config(['cache.default' => 'array']);

    foreach (['attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
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
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });

    // Every attendance migration except the permission grant (no role_permissions here).
    foreach (glob(database_path('migrations/2026_09_10_*.php')) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert(['id' => 1, 'name' => 'Jeddah']);
    DB::table('departments')->insert(['id' => 1, 'name' => 'Finance']);
});

/** Ahmed: Jeddah, Finance, hired long ago, punching on BioTime code 1001. */
function sheetPerson(): Employee
{
    $employee = Employee::create([
        'name' => 'Ahmed', 'oracle_emp_no' => '1001', 'branch_id' => 1,
        'department_id' => 1, 'hired_date' => '2020-01-01', 'status' => 'active',
    ]);

    $source = BiotimeSource::create([
        'name' => 'BioTime KSA', 'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
        'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
    ]);

    BiotimeEmployee::create([
        'biotime_source_id' => $source->id, 'emp_code' => '1001', 'employee_id' => $employee->id,
    ]);

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

    return $employee;
}

function sheetPunch(Employee $employee, string $time): void
{
    AttendancePunch::create([
        'biotime_source_id' => BiotimeSource::first()->id,
        'biotime_id' => AttendancePunch::max('biotime_id') + 1,
        'biotime_employee_id' => BiotimeEmployee::first()->id,
        'employee_id' => $employee->id,
        'emp_code' => '1001',
        'punch_time' => $time,
        'punch_state' => '0',
        'synced_at' => now(),
    ]);
}

function sheetFor(Employee $employee, string $month = '2026-09'): array
{
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange($month.'-01', '2026-09-30');

    return (new MonthlySheet(new ShiftResolver))->build($employee, $month);
}

/** @param  list<MonthlyDay>  $days */
function sheetOn(array $days, string $date): MonthlyDay
{
    foreach ($days as $day) {
        if ($day->date === $date) {
            return $day;
        }
    }

    throw new RuntimeException("No {$date} on the sheet.");
}

it('shows every calendar day of the month, punched or not', function () {
    $employee = sheetPerson();
    AttendanceHoliday::create(['holiday_date' => '2026-09-08', 'name' => 'National Day', 'branch_id' => null]);

    $days = sheetFor($employee);

    expect($days)->toHaveCount(30)
        ->and($days[0]->date)->toBe('2026-09-01')
        ->and($days[29]->date)->toBe('2026-09-30')
        // The 4th is a Friday and the 5th a Saturday: days off, and no row exists for either.
        ->and(sheetOn($days, '2026-09-04')->kind)->toBe(MonthlyDay::KIND_OFF)
        ->and(sheetOn($days, '2026-09-04')->day)->toBeNull()
        ->and(sheetOn($days, '2026-09-05')->kind)->toBe(MonthlyDay::KIND_OFF)
        ->and(sheetOn($days, '2026-09-08')->kind)->toBe(MonthlyDay::KIND_HOLIDAY)
        ->and(sheetOn($days, '2026-09-08')->kindLabel())->toBe('National Day')
        ->and(sheetOn($days, '2026-09-01')->kind)->toBe(MonthlyDay::KIND_WORK);
});

it('puts each punch on its own day and marks the check-in and check-out', function () {
    $employee = sheetPerson();
    sheetPunch($employee, '2026-09-01 08:55:00');
    sheetPunch($employee, '2026-09-01 13:02:00');
    sheetPunch($employee, '2026-09-01 17:05:00');
    sheetPunch($employee, '2026-09-02 09:25:00');

    $days = sheetFor($employee);
    $first = sheetOn($days, '2026-09-01');

    expect($first->punches)->toHaveCount(3)
        ->and($first->punches->map(fn ($p) => $p->punch_time->format('H:i'))->all())->toBe(['08:55', '13:02', '17:05'])
        ->and($first->day->first_in->format('H:i'))->toBe('08:55')
        ->and($first->day->last_out->format('H:i'))->toBe('17:05')
        ->and($first->day->worked_minutes)->toBe(490)
        ->and(sheetOn($days, '2026-09-02')->punches)->toHaveCount(1);
});

it('adds up the month the way HR reads it', function () {
    $employee = sheetPerson();
    sheetPunch($employee, '2026-09-01 08:55:00');   // present, on time
    sheetPunch($employee, '2026-09-01 17:05:00');
    sheetPunch($employee, '2026-09-02 09:25:00');   // late, and never punched out
    sheetPunch($employee, '2026-09-03 08:50:00');   // left an hour early
    sheetPunch($employee, '2026-09-03 16:00:00');

    $days = sheetFor($employee);
    $totals = MonthlyTotals::fromDays($days);

    expect($totals->presentDays)->toBe(3)
        ->and($totals->lateDays)->toBe(1)
        ->and($totals->lateMinutes)->toBe(25)
        ->and($totals->earlyLeaveDays)->toBe(1)
        ->and($totals->earlyLeaveMinutes)->toBe(60)
        ->and($totals->missingCheckOuts)->toBe(1)
        ->and($totals->punches)->toBe(5)
        // 08:55–17:05 and 08:50–16:00. The day with no check-out adds nothing.
        ->and($totals->workedMinutes)->toBe(490 + 430)
        ->and($totals->offDays)->toBe(8)      // four Fridays, four Saturdays
        ->and($totals->workDays)->toBe(22)    // 30 days less those 8
        // Every work day that has ENDED without a punch is an absence the
        // processor recorded. The 30th is today and still running, so it is
        // not an absence — it is simply not recorded yet, counted apart.
        ->and($totals->absentDays)->toBe(18)
        ->and($totals->unrecordedDays)->toBe(1)
        ->and($totals->presentDays + $totals->absentDays + $totals->unrecordedDays)->toBe($totals->workDays)
        ->and(sheetOn($days, '2026-09-02')->has(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT))->toBeTrue()
        ->and(sheetOn($days, '2026-09-07')->status())->toBe(AttendanceDayBuilder::STATUS_ABSENT);
});

it('leaves today alone until the shift is over', function () {
    $employee = sheetPerson();
    $days = sheetFor($employee);

    // Now is 12:00 on the 30th: the shift ends at 17:00, so they may still arrive.
    expect(sheetOn($days, '2026-09-30')->day)->toBeNull()
        ->and(sheetOn($days, '2026-09-30')->future)->toBeFalse()
        ->and(sheetOn($days, '2026-09-30')->emptyLabel())->toBe('Not recorded');
});

it('shows nothing for a date before the person was hired', function () {
    $employee = sheetPerson();
    $employee->update(['hired_date' => '2026-09-15']);

    $days = sheetFor($employee);

    expect(sheetOn($days, '2026-09-14')->kind)->toBe(MonthlyDay::KIND_BEFORE_HIRE)
        ->and(sheetOn($days, '2026-09-14')->day)->toBeNull()
        ->and(sheetOn($days, '2026-09-16')->kind)->toBe(MonthlyDay::KIND_WORK);
});

it('counts the same figures on the picker as on the sheet', function () {
    $employee = sheetPerson();
    sheetPunch($employee, '2026-09-01 08:55:00');
    sheetPunch($employee, '2026-09-01 17:05:00');
    sheetPunch($employee, '2026-09-02 09:25:00');   // late, no check-out

    $sheet = MonthlyTotals::fromDays(sheetFor($employee));

    $view = app(AttendanceMonthController::class)
        ->index(Request::create('/admin/attendance/monthly', 'GET', ['month' => '2026-09']));

    $roster = $view->getData()['totals']->get($employee->id);

    expect($view->getData()['employees']->pluck('id')->all())->toBe([$employee->id])
        ->and((int) $roster->present_days)->toBe($sheet->presentDays)
        ->and((int) $roster->absent_days)->toBe($sheet->absentDays)
        ->and((int) $roster->worked_minutes)->toBe($sheet->workedMinutes)
        ->and((int) $roster->late_days)->toBe($sheet->lateDays)
        ->and((int) $roster->late_minutes)->toBe($sheet->lateMinutes)
        ->and((int) $roster->early_leave_days)->toBe($sheet->earlyLeaveDays)
        ->and($roster->missing_check_outs)->toBe($sheet->missingCheckOuts);
});

/**
 * Renders a page's Blade for real, with `layouts.admin` replaced by a stub:
 * the admin layout needs a signed-in user and half the NOC's tables, and what
 * is being checked here is this page's own markup.
 */
function sheetRender(Illuminate\Contracts\View\View $view): string
{
    $stub = sys_get_temp_dir().'/sg-noc-view-stub';
    @mkdir($stub.'/layouts', 0777, true);
    file_put_contents($stub.'/layouts/admin.blade.php', "@yield('content')");

    View::getFinder()->prependLocation($stub);
    View::share('errors', new Illuminate\Support\ViewErrorBag);

    return $view->render();
}

it('renders the sheet and the picker', function () {
    $employee = sheetPerson();
    sheetPunch($employee, '2026-09-01 08:55:00');
    sheetPunch($employee, '2026-09-01 17:05:00');
    sheetPunch($employee, '2026-09-02 09:25:00');
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');

    $controller = app(AttendanceMonthController::class);

    $sheet = sheetRender($controller->show(
        Request::create('/admin/attendance/monthly/'.$employee->id, 'GET', ['month' => '2026-09']),
        $employee,
        app(MonthlySheet::class),
    ));

    expect($sheet)->toContain('Ahmed')
        ->toContain('September 2026')
        ->toContain('08:55')                  // the punch
        ->toContain('17:05')
        ->toContain('Missing check-out')      // the 2nd, punched in and never out
        ->toContain('Day off')                // Friday the 4th
        ->toContain('Total worked')
        ->toContain('Not recorded');          // the 30th, still running

    $picker = sheetRender($controller->index(
        Request::create('/admin/attendance/monthly', 'GET', ['month' => '2026-09'])
    ));

    expect($picker)->toContain('Ahmed')
        ->toContain('Monthly sheet')
        ->toContain('Finance')
        ->toContain('/admin/attendance/monthly/'.$employee->id);
});

it('builds the sheet page for one person', function () {
    $employee = sheetPerson();
    sheetPunch($employee, '2026-09-01 08:55:00');
    sheetPunch($employee, '2026-09-01 17:05:00');
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');

    $data = app(AttendanceMonthController::class)
        ->show(Request::create('/admin/attendance/monthly/'.$employee->id, 'GET', ['month' => '2026-09']), $employee, app(MonthlySheet::class))
        ->getData();

    expect($data['days'])->toHaveCount(30)
        ->and($data['month'])->toBe('2026-09')
        ->and($data['codes'])->toBe('1001')
        ->and($data['totals']->presentDays)->toBe(1)
        ->and($data['employee']->name)->toBe('Ahmed')
        ->and(AttendanceDay::where('employee_id', $employee->id)->count())->toBeGreaterThan(1);
});
