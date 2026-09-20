<?php

use App\Http\Controllers\Admin\Attendance\AttendanceOwnerController;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceOwner;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * Attendance ▸ Owners: the list that lets a general manager or a branch GM ask
 * the home-portal assistant about attendance beyond their own team. What is
 * defended: only manage-attendance-owners changes it, a branch GM gets exactly
 * the branches ticked, a row always lands on the primary record, and every
 * change leaves a security-action audit row.
 *
 * The controller is called directly — the admin middleware stack (Microsoft
 * sign-in, 2FA, host isolation) is not what is under test — and the route
 * gates are asserted on the routes themselves.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['attendance_owners', 'attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'activity_logs', 'employees', 'departments', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('password')->nullable();
        $t->string('role', 50)->default('viewer');
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
        $t->string('email')->nullable();
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

    // The page's tab bar counts unmapped BioTime codes and queued attendance tasks.
    foreach (array_merge(
        glob(database_path('migrations/2026_09_10_*.php')),
        glob(database_path('migrations/2026_09_11_*.php')),
        glob(database_path('migrations/2026_09_2*_*attendance*.php')),
    ) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }
    (require database_path('migrations/2026_09_13_100001_create_attendance_owners_table.php'))->up();

    DB::table('branches')->insert([
        ['id' => 1, 'name' => 'Jeddah'],
        ['id' => 2, 'name' => 'Riyadh'],
        ['id' => 3, 'name' => 'Cairo'],
    ]);
    DB::table('users')->insert(['id' => 7, 'name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);

    $admin = new User(['name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);
});

function ownerController(): AttendanceOwnerController
{
    return app(AttendanceOwnerController::class);
}

/** @param  array<string, mixed>  $data */
function ownerRequest(string $method, array $data = []): Request
{
    $request = Request::create('/admin/attendance/owners', $method, $data);
    $request->setUserResolver(fn () => auth()->user());
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/** @param  array<string, mixed>  $attributes */
function ownerEmployee(string $name, array $attributes = []): Employee
{
    return Employee::create($attributes + [
        'name' => $name,
        'email' => strtolower($name).'@samirgroup.com',
        'branch_id' => 1,
        'status' => 'active',
    ]);
}

it('guards every change with its own permission', function () {
    foreach (['store', 'update', 'destroy'] as $action) {
        expect(Route::getRoutes()->getByName("admin.attendance.owners.{$action}")?->gatherMiddleware())
            ->toContain('permission:manage-attendance-owners');
    }

    expect(Route::getRoutes()->getByName('admin.attendance.owners.index')?->gatherMiddleware())
        ->toContain('permission:view-attendance')
        ->and(RolePermission::allSlugs())->toContain('manage-attendance-owners')
        ->and(RolePermission::defaultPermissions()['hr'])->toContain('manage-attendance-owners');
});

it('adds a general manager for the whole company', function () {
    $gm = ownerEmployee('Nadia');

    ownerController()->store(ownerRequest('POST', [
        'employee' => "{$gm->id} · Nadia · Jeddah",
        'title' => 'General Manager',
        'scope' => 'company',
        // Still ticked in the hidden branch box: ignored for the whole company.
        'branch_ids' => [1],
    ]));

    $owner = AttendanceOwner::sole();
    $log = ActivityLog::where('action', 'attendance_owner_added')->sole();

    expect($owner->employee_id)->toBe($gm->id)
        ->and($owner->isCompanyWide())->toBeTrue()
        ->and($owner->branch_ids)->toBeNull()
        ->and($owner->title)->toBe('General Manager')
        ->and((int) $owner->created_by)->toBe(7)
        ->and($log->changes['new']['access'])->toBe('Whole company')
        ->and((int) $log->user_id)->toBe(7)
        ->and(config('audit.security_actions'))->toContain('attendance_owner_added', 'attendance_owner_changed', 'attendance_owner_removed');
});

it('gives a branch GM exactly the branches ticked', function () {
    $gm = ownerEmployee('Faisal', ['branch_id' => 2]);

    ownerController()->store(ownerRequest('POST', [
        'employee' => (string) $gm->id,
        'scope' => 'branches',
        'branch_ids' => ['3', '2', '2'],
    ]));

    $owner = AttendanceOwner::sole();

    expect($owner->branchIds())->toBe([2, 3])
        ->and($owner->accessLabel(Branch::all()->keyBy('id')))->toBe('Cairo, Riyadh')
        ->and(AttendanceOwner::accessFor([$gm->id]))->toBe(['company' => false, 'branch_ids' => [2, 3]]);
});

it('needs at least one branch for a branch GM', function () {
    $gm = ownerEmployee('Faisal');

    expect(fn () => ownerController()->store(ownerRequest('POST', ['employee' => (string) $gm->id, 'scope' => 'branches'])))
        ->toThrow(ValidationException::class);

    expect(AttendanceOwner::count())->toBe(0);
});

it('puts a linked mailbox\'s row on the primary record', function () {
    $primary = ownerEmployee('Samir', ['email' => 'samir@sss-egypt.com']);
    $mailbox = ownerEmployee('Samir SG', ['email' => 'samir@samirgroup.com', 'linked_primary_employee_id' => $primary->id]);

    ownerController()->store(ownerRequest('POST', ['employee' => (string) $mailbox->id, 'scope' => 'company']));

    expect(AttendanceOwner::sole()->employee_id)->toBe($primary->id);
});

it('adds nobody who has left, and nobody twice', function () {
    $gone = ownerEmployee('Tarek', ['status' => 'terminated']);
    $gm = ownerEmployee('Nadia');

    ownerController()->store(ownerRequest('POST', ['employee' => (string) $gone->id, 'scope' => 'company']));
    expect(session('error'))->toContain('has left');

    ownerController()->store(ownerRequest('POST', ['employee' => (string) $gm->id, 'scope' => 'company']));
    ownerController()->store(ownerRequest('POST', ['employee' => (string) $gm->id, 'scope' => 'branches', 'branch_ids' => [1]]));

    expect(session('error'))->toContain('already on the list')
        ->and(AttendanceOwner::pluck('employee_id')->all())->toBe([$gm->id])
        ->and(AttendanceOwner::sole()->isCompanyWide())->toBeTrue();
});

it('logs a change of branches and a removal, and nothing for a save that changes nothing', function () {
    $gm = ownerEmployee('Faisal');
    $owner = AttendanceOwner::create(['employee_id' => $gm->id, 'scope' => 'branches', 'branch_ids' => [1]]);

    ownerController()->update(ownerRequest('PUT', ['scope' => 'branches', 'branch_ids' => [1, 2], 'title' => 'GM West']), $owner);
    ownerController()->update(ownerRequest('PUT', ['scope' => 'branches', 'branch_ids' => [2, 1], 'title' => 'GM West']), $owner->fresh());
    ownerController()->destroy($owner->fresh());

    $changed = ActivityLog::where('action', 'attendance_owner_changed')->get();

    expect($changed)->toHaveCount(1)
        ->and($changed[0]->changes['old'])->toBe(['title' => null, 'access' => 'Jeddah'])
        ->and($changed[0]->changes['new'])->toBe(['title' => 'GM West', 'access' => 'Jeddah, Riyadh'])
        ->and(ActivityLog::where('action', 'attendance_owner_removed')->count())->toBe(1)
        ->and(AttendanceOwner::count())->toBe(0);
});

it('lists the owners and offers the forms', function () {
    $gm = ownerEmployee('Nadia');
    $branchGm = ownerEmployee('Faisal', ['email' => null, 'branch_id' => 2]);
    $company = AttendanceOwner::create(['employee_id' => $gm->id, 'scope' => 'company', 'title' => 'General Manager', 'created_by' => 7]);
    $branches = AttendanceOwner::create(['employee_id' => $branchGm->id, 'scope' => 'branches', 'branch_ids' => [2, 3]]);
    ownerEmployee('Omar');
    ownerEmployee('Tarek', ['status' => 'terminated']);

    // The admin layout brings the whole navigation with it; the page is what is under test.
    $views = storage_path('framework/testing/owner-page-'.uniqid());
    File::ensureDirectoryExists($views.'/layouts');
    File::put($views.'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation($views);
    view()->share('errors', new ViewErrorBag);
    Gate::before(fn () => true);

    try {
        $html = ownerController()->index(ownerRequest('GET'))->render();
    } finally {
        File::deleteDirectory($views);
    }

    expect($html)
        ->toContain('Attendance owners')
        ->toContain('General Manager')
        ->toContain('by HR Admin')
        ->toContain('Whole company')
        ->toContain('Riyadh')
        ->toContain('Cairo')
        ->toContain('No email — cannot sign in')
        ->toContain('id="owner-'.$company->id.'-scope-company" checked')
        ->toContain('id="owner-'.$branches->id.'-branch-3"')
        ->toContain(route('admin.attendance.owners.store'))
        ->toContain('Omar')
        ->not->toContain('Tarek');
});
