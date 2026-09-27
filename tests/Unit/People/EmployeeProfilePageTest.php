<?php

use App\Http\Controllers\Admin\Attendance\AttendanceMonthController;
use App\Http\Controllers\Admin\EmployeeProfileController;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\MonthlySheet;
use App\Services\People\EmployeeProfile;
use App\Services\Vacation\VacationImporter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;

/**
 * Employee profiles: one person's attendance and Oracle vacation on one page.
 * What is defended: either permission opens it and each half shows only to its
 * own holders, an absence Oracle has as leave is called out, the figures are the
 * monthly sheet's and Oracle's own, and a linked mailbox lands on its primary.
 *
 * "Now" is noon on 30 September 2026; Friday and Saturday are days off.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-30 12:00:00');
    CarbonImmutable::setTestNow('2026-09-30 12:00:00');
    config(['cache.default' => 'array']);

    foreach (['vacation_absences', 'vacation_balances', 'vacation_employees', 'vacation_imports',
        'attendance_exports', 'attendance_periods', 'attendance_tasks', 'attendance_adjustments', 'attendance_holidays',
        'attendance_shift_assignments', 'attendance_shifts', 'attendance_days', 'attendance_punches', 'biotime_employees',
        'biotime_terminals', 'biotime_areas', 'biotime_sources', 'activity_logs', 'employees', 'departments', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('role', 50)->default('viewer');
        $t->timestamps();
    });
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
        $t->string('email')->nullable();
        $t->string('job_title')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->unsignedBigInteger('manager_id')->nullable();
        $t->unsignedBigInteger('supervisor_id')->nullable();
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

    // Every attendance migration except the permission grants, then the vacation tables.
    foreach (array_merge(glob(database_path('migrations/2026_09_10_*.php')), glob(database_path('migrations/2026_09_11_*.php')), glob(database_path('migrations/2026_09_2*_*attendance*.php'))) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }
    (require database_path('migrations/2026_09_14_160001_create_vacation_tables.php'))->up();

    DB::table('branches')->insert(['id' => 10, 'name' => 'JED']);
    DB::table('departments')->insert(['id' => 1, 'name' => 'Finance']);
    config([
        'vacations.books' => ['samirgroup' => ['label' => 'SamirGroup', 'branches' => ['JED'], 'weekend' => [5, 6]]],
        'vacations.default_book' => 'samirgroup',
    ]);

    $admin = new User(['name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);

    // The admin layout brings the whole navigation with it; the pages are what is under test.
    File::ensureDirectoryExists(profileViews().'/layouts');
    File::put(profileViews().'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation(profileViews());
    view()->share('errors', new ViewErrorBag);
});

afterEach(function () {
    File::deleteDirectory(profileViews());
});

function profileViews(): string
{
    return storage_path('framework/testing/profile-pages');
}

/** The signed-in user holds exactly these permissions. */
function profileCan(string ...$abilities): void
{
    Gate::before(fn ($user, string $ability) => in_array($ability, $abilities, true));
}

/** @param  array<string, mixed>  $query */
function profileRequest(array $query = []): Request
{
    $request = Request::create('/admin/people', 'GET', $query);
    $request->setUserResolver(fn () => auth()->user());
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/**
 * Ahmed punches only on 1 September, so every later work day is absent. Oracle
 * has him on leave on the 2nd and 3rd, on a business trip on the 7th, and
 * booked for 11–15 October.
 */
function profilePerson(): Employee
{
    $ahmed = Employee::create([
        'name' => 'Ahmed Saleh', 'email' => 'ahmed@samirgroup.com', 'job_title' => 'Accountant', 'oracle_emp_no' => '1001',
        'branch_id' => 10, 'department_id' => 1, 'hired_date' => '2020-01-01', 'status' => 'active',
    ]);

    $source = BiotimeSource::create([
        'name' => 'BioTime KSA', 'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
        'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
    ]);
    $code = BiotimeEmployee::create(['biotime_source_id' => $source->id, 'emp_code' => '1001', 'employee_id' => $ahmed->id]);

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

    foreach (['2026-09-01 08:55:00', '2026-09-01 17:05:00'] as $i => $time) {
        AttendancePunch::create([
            'biotime_source_id' => $source->id, 'biotime_id' => $i + 1, 'biotime_employee_id' => $code->id,
            'employee_id' => $ahmed->id, 'emp_code' => '1001', 'punch_time' => $time, 'punch_state' => '0', 'synced_at' => now(),
        ]);
    }
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');

    $importer = app(VacationImporter::class);
    $importer->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), [
        ['person_number' => '1001', 'carryover' => 4, 'accrued' => 14.5, 'absences' => -6, 'balance' => 12.5],
    ]);
    $importer->importAbsences('samirgroup', [
        ['person_number' => '1001', 'type' => 'Annual Leave', 'start' => '02-SEP-26', 'end' => '03-SEP-26'],
        ['person_number' => '1001', 'type' => 'Internal Business Trip', 'start' => '07-SEP-26', 'end' => '07-SEP-26'],
        ['person_number' => '1001', 'type' => 'Annual Leave', 'start' => '11-OCT-26', 'end' => '15-OCT-26'],
    ]);

    return $ahmed;
}

function profileShow(Employee $employee, array $query = ['month' => '2026-09'])
{
    return app(EmployeeProfileController::class)->show(profileRequest($query), $employee, app(EmployeeProfile::class), app(MonthlySheet::class));
}

it('opens to either permission', function () {
    foreach (['admin.people.index', 'admin.people.show'] as $name) {
        expect(Route::getRoutes()->getByName($name)?->gatherMiddleware())->toContain('permission:view-attendance,view-vacations');
    }
});

it('puts the year, the month and the vacation side by side, and calls out absences Oracle has as leave', function () {
    $ahmed = profilePerson();
    profileCan('view-attendance', 'view-vacations', 'view-employees');

    $view = profileShow($ahmed);
    $data = $view->getData();
    $september = $data['yearStats']->months['2026-09'];

    expect($september->attendance->presentDays)->toBe(1)
        ->and($september->attendance->absentDays)->toBe(20)       // the monthly sheet's own count
        ->and($september->absentOnLeaveDays)->toBe(3)             // the 2nd, the 3rd and the trip on the 7th
        ->and($september->absentDays)->toBe(17)
        ->and($september->awayDays)->toBe(3)
        ->and($data['yearStats']->total->leaveDays)->toBe(7)      // 2 in September, 5 booked in October
        ->and($data['yearStats']->total->tripDays)->toBe(1)
        ->and(array_column($data['yearStats']->absentOnLeave, 'date'))->toBe(['2026-09-02', '2026-09-03', '2026-09-07'])
        ->and($data['balance']->balance)->toBe(12.5)
        ->and($data['nextLeave']->start_date->toDateString())->toBe('2026-10-11')
        ->and(collect(array_keys($data['leaveByDate']))->sort()->values()->all())->toBe(['2026-09-02', '2026-09-03', '2026-09-07'])
        ->and($data['days'])->toHaveCount(30);

    expect($view->render())
        ->toContain('Ahmed Saleh')
        ->toContain('2026 at a glance')
        ->toContain('Vacation left')
        ->toContain('12.5')
        ->toContain('6%')                                          // 1 present ÷ (1 + 17 unexplained)
        ->toContain('3 days recorded absent that Oracle has as leave or a business trip')
        ->toContain('profile-year-chart')
        ->toContain('Attendance, September 2026')
        ->toContain('08:55')                                       // the day table has its rows
        ->toContain('Internal Business Trip')
        ->toContain('Next leave:')
        ->toContain('Leave records in 2026');
});

it('shows only the vacation half to someone who may only see vacations', function () {
    $ahmed = profilePerson();
    profileCan('view-vacations');

    $view = profileShow($ahmed);

    expect($view->getData()['yearStats'])->toBeNull()
        ->and($view->getData()['days'])->toBe([])
        ->and($view->render())->toContain('Vacation 2026')->not->toContain('Attendance, September 2026')->not->toContain('Attendance rate');
});

it('shows only the attendance half to someone who may only see attendance', function () {
    $ahmed = profilePerson();
    profileCan('view-attendance');

    $view = profileShow($ahmed);

    expect($view->getData()['people'])->toHaveCount(0)
        ->and($view->getData()['leaveByDate'])->toBe([])
        ->and($view->render())->toContain('Attendance, September 2026')->not->toContain('Vacation left')->not->toContain('Annual Leave');
});

it('lists people with this month and their vacation left, filtered by what they have', function () {
    $ahmed = profilePerson();
    Employee::create(['name' => 'Omar Ali', 'oracle_emp_no' => '1002', 'branch_id' => 10, 'status' => 'active']);
    Employee::create(['name' => 'Tarek Left', 'status' => 'terminated']);
    Employee::create(['name' => 'Ahmed Mailbox', 'linked_primary_employee_id' => $ahmed->id, 'status' => 'active']);
    profileCan('view-attendance', 'view-vacations');

    $names = fn (array $query = []) => app(EmployeeProfileController::class)->index(profileRequest($query))
        ->getData()['employees']->getCollection()->pluck('name')->all();

    expect($names())->toBe(['Ahmed Saleh', 'Omar Ali'])
        ->and($names(['data' => 'vacation']))->toBe(['Ahmed Saleh'])
        ->and($names(['data' => 'attendance']))->toBe(['Ahmed Saleh'])
        ->and($names(['status' => 'left']))->toBe(['Tarek Left'])
        ->and($names(['q' => '1002']))->toBe(['Omar Ali']);

    $view = app(EmployeeProfileController::class)->index(profileRequest());

    expect((int) $view->getData()['attendance']->get($ahmed->id)->absent_days)->toBe(20)
        ->and($view->getData()['balances']->get($ahmed->id)->balance)->toBe(12.5)
        ->and($view->render())->toContain('Employee profiles')->toContain('Not on BioTime')->toContain(route('admin.people.show', $ahmed));
});

it('sends a linked mailbox to its primary\'s profile, and shows Oracle leave on the monthly sheet', function () {
    $ahmed = profilePerson();
    $mailbox = Employee::create(['name' => 'Ahmed SG', 'linked_primary_employee_id' => $ahmed->id, 'status' => 'active']);
    profileCan('view-attendance', 'view-vacations');

    $redirect = profileShow($mailbox, ['month' => '2026-08']);

    expect($redirect)->toBeInstanceOf(RedirectResponse::class)
        ->and($redirect->getTargetUrl())->toBe(route('admin.people.show', ['employee' => $ahmed->id, 'month' => '2026-08']));

    $sheet = app(AttendanceMonthController::class)->show(profileRequest(['month' => '2026-09']), $ahmed, app(MonthlySheet::class));

    expect(array_keys($sheet->getData()['leaveByDate']))->toContain('2026-09-02')
        ->and($sheet->render())->toContain(route('admin.people.show', ['employee' => $ahmed, 'month' => '2026-09']))->toContain('Annual Leave');
});
