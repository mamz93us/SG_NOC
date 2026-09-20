<?php

use App\Http\Controllers\Admin\EmployeeController;
use App\Models\Attendance\AttendanceTask;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * The employee form's Oracle HR & reporting-lines section: Oracle number,
 * Oracle department, manager and supervisor. What is defended: a pick is a
 * real colleague — their primary record, still employed, not the person
 * themselves; saving any other field never drops a manager who has since
 * left; and a changed Oracle number re-matches attendance, queued once, and
 * names anyone else who holds it.
 *
 * The controller is called directly, like AttendanceOwnerPageTest: the admin
 * middleware stack is not what is under test, and the route gate is asserted
 * on the routes themselves.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['attendance_owners', 'attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'employee_signature_roles', 'activity_logs', 'employees', 'departments', 'branches', 'users'] as $table) {
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
        $t->string('azure_id')->nullable();
        $t->string('employee_type', 20)->default(Employee::TYPE_STANDARD);
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('gender')->nullable();
        $t->string('job_title')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('oracle_dept_no')->nullable();
        $t->string('oracle_department')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->unsignedBigInteger('manager_id')->nullable();
        $t->unsignedBigInteger('supervisor_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->string('status')->default('active');
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->text('notes')->nullable();
        $t->timestamps();
    });
    Schema::create('employee_signature_roles', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('employee_id');
        $t->string('label', 120);
        $t->string('job_title')->nullable();
        $t->string('department')->nullable();
        $t->integer('sort_order')->default(0);
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

    // BioTime codes and the attendance task queue.
    foreach (array_merge(
        glob(database_path('migrations/2026_09_10_*.php')),
        glob(database_path('migrations/2026_09_11_*.php')),
        glob(database_path('migrations/2026_09_2*_*attendance*.php')),
    ) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert([
        ['id' => 1, 'name' => 'Jeddah'],
        ['id' => 2, 'name' => 'Riyadh'],
        ['id' => 3, 'name' => 'Cairo'],
    ]);
    DB::table('users')->insert(['id' => 7, 'name' => 'IT Admin', 'email' => 'it.admin@samirgroup.com']);

    $admin = new User(['name' => 'IT Admin', 'email' => 'it.admin@samirgroup.com']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);
});

function hrFormController(): EmployeeController
{
    return app(EmployeeController::class);
}

/** @param  array<string, mixed>  $data */
function hrFormRequest(string $method, array $data = []): Request
{
    $request = Request::create('/admin/employees', $method, $data);
    $request->setUserResolver(fn () => auth()->user());
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/** @param  array<string, mixed>  $attributes */
function hrFormEmployee(string $name, array $attributes = []): Employee
{
    return Employee::create($attributes + [
        'name' => $name,
        'branch_id' => 1,
        'status' => 'active',
    ]);
}

/**
 * Saves the form as it would post for $employee with nothing touched, $changes
 * on top. A picker holds the saved person's label, which starts with their id.
 *
 * @param  array<string, mixed>  $changes
 */
function hrFormSave(Employee $employee, array $changes = []): void
{
    $employee->refresh();

    hrFormController()->update(hrFormRequest('PUT', $changes + [
        'name' => $employee->name,
        'status' => $employee->status,
        'job_title' => $employee->job_title,
        'oracle_emp_no' => $employee->oracle_emp_no,
        'oracle_department' => $employee->oracle_department,
        'oracle_dept_no' => $employee->oracle_dept_no,
        'manager' => $employee->manager_id ? "{$employee->manager_id} · saved manager" : '',
        'supervisor' => $employee->supervisor_id ? "{$employee->supervisor_id} · saved supervisor" : '',
    ]), $employee);
}

/** A fingerprint code on a BioTime source, so there is something to re-match. */
function hrFormBiotimeCode(string $code): void
{
    $sourceId = DB::table('biotime_sources')->insertGetId([
        'name' => 'Jeddah BioTime',
        'host' => 'biotime.local',
        'database' => 'zkbiotime',
        'username' => 'reader',
    ]);

    DB::table('biotime_employees')->insert(['biotime_source_id' => $sourceId, 'emp_code' => $code]);
}

it('guards the form with manage-employees', function () {
    foreach (['create', 'store', 'edit', 'update'] as $action) {
        expect(Route::getRoutes()->getByName("admin.employees.{$action}")?->gatherMiddleware())
            ->toContain('permission:manage-employees');
    }
});

it('saves the Oracle number, the Oracle department and both reporting lines', function () {
    $manager = hrFormEmployee('Nadia', ['oracle_emp_no' => '1001']);
    $supervisor = hrFormEmployee('Omar', ['branch_id' => 2]);
    $employee = hrFormEmployee('Ahmed');

    hrFormSave($employee, [
        'oracle_emp_no' => ' 55512 ',
        'oracle_department' => ' Finance ',
        'oracle_dept_no' => '310',
        'manager' => "{$manager->id} · Nadia · Oracle 1001 · Jeddah",
        'supervisor' => "{$supervisor->id} · Omar · Riyadh",
    ]);

    $employee->refresh();

    expect($employee->oracle_emp_no)->toBe('55512')
        ->and($employee->oracle_department)->toBe('Finance')
        ->and($employee->oracle_dept_no)->toBe('310')
        ->and($employee->manager_id)->toBe($manager->id)
        ->and($employee->supervisor_id)->toBe($supervisor->id)
        ->and(session('success'))->toBe('Employee updated successfully.');
});

it('clears what is left blank', function () {
    $manager = hrFormEmployee('Nadia');
    $employee = hrFormEmployee('Ahmed', ['oracle_emp_no' => '1002', 'oracle_department' => 'Finance', 'manager_id' => $manager->id]);

    hrFormSave($employee, ['oracle_emp_no' => '  ', 'oracle_department' => '', 'manager' => '']);

    $employee->refresh();

    expect($employee->oracle_emp_no)->toBeNull()
        ->and($employee->oracle_department)->toBeNull()
        ->and($employee->manager_id)->toBeNull();
});

it('refuses a name that was typed rather than picked, and someone who does not exist', function () {
    $employee = hrFormEmployee('Ahmed');

    expect(fn () => hrFormSave($employee, ['manager' => 'Nadia']))
        ->toThrow(ValidationException::class, 'Pick the manager from the list')
        ->and(fn () => hrFormSave($employee, ['supervisor' => '99999 · Nobody']))
        ->toThrow(ValidationException::class, 'Pick the supervisor from the list');
});

it('will not make anyone their own manager or supervisor, even through their linked mailbox', function () {
    $employee = hrFormEmployee('Samir');
    $mailbox = hrFormEmployee('Samir SG', ['linked_primary_employee_id' => $employee->id]);

    expect(fn () => hrFormSave($employee, ['manager' => (string) $employee->id]))
        ->toThrow(ValidationException::class, 'their own manager')
        ->and(fn () => hrFormSave($employee, ['supervisor' => "{$mailbox->id} · Samir SG"]))
        ->toThrow(ValidationException::class, 'their own supervisor')
        ->and($employee->fresh()->manager_id)->toBeNull()
        ->and($employee->fresh()->supervisor_id)->toBeNull();
});

it('points a reporting line at the primary record of a linked mailbox', function () {
    $primary = hrFormEmployee('Samir');
    $mailbox = hrFormEmployee('Samir SG', ['linked_primary_employee_id' => $primary->id]);
    $employee = hrFormEmployee('Ahmed');

    hrFormSave($employee, ['manager' => "{$mailbox->id} · Samir SG"]);

    expect($employee->fresh()->manager_id)->toBe($primary->id);
});

it('refuses a leaver as a new pick, but keeps one already on the record when something else is saved', function () {
    $gone = hrFormEmployee('Tarek', ['status' => 'terminated']);
    $employee = hrFormEmployee('Ahmed', ['manager_id' => $gone->id]);
    $other = hrFormEmployee('Laila');

    hrFormSave($employee, ['job_title' => 'Accountant']);

    expect($employee->fresh()->manager_id)->toBe($gone->id)
        ->and($employee->fresh()->job_title)->toBe('Accountant')
        ->and(fn () => hrFormSave($other, ['supervisor' => (string) $gone->id]))
        ->toThrow(ValidationException::class, 'Tarek has left the company');
});

it('will not name one person as both manager and supervisor, unless the record already did', function () {
    $boss = hrFormEmployee('Nadia');
    $employee = hrFormEmployee('Ahmed', ['manager_id' => $boss->id]);
    $legacy = hrFormEmployee('Sara', ['manager_id' => $boss->id, 'supervisor_id' => $boss->id]);

    expect(fn () => hrFormSave($employee, ['supervisor' => (string) $boss->id]))
        ->toThrow(ValidationException::class, 'Manager and supervisor are the same person');

    hrFormSave($legacy, ['job_title' => 'Clerk']);

    expect($employee->fresh()->supervisor_id)->toBeNull()
        ->and($legacy->fresh()->job_title)->toBe('Clerk')
        ->and($legacy->fresh()->supervisor_id)->toBe($boss->id);
});

it('re-matches attendance when the Oracle number changes — once, and only when there are codes', function () {
    $employee = hrFormEmployee('Ahmed', ['oracle_emp_no' => '512']);

    hrFormSave($employee, ['oracle_emp_no' => '5120']);
    expect(AttendanceTask::count())->toBe(0);

    hrFormBiotimeCode('512');

    hrFormSave($employee, ['job_title' => 'Clerk']);
    expect(AttendanceTask::count())->toBe(0);

    hrFormSave($employee, ['oracle_emp_no' => '55512']);
    hrFormSave($employee, ['oracle_emp_no' => '55513']);

    $task = AttendanceTask::sole();

    expect($task->type)->toBe('relink')
        ->and($task->payload)->toBe(['source_id' => null, 'all' => true])
        ->and($task->status)->toBe(AttendanceTask::PENDING)
        ->and((int) $task->requested_by)->toBe(7)
        ->and(session('success'))->toContain('re-matching fingerprint codes');
});

it('names anyone else who holds the same Oracle number', function () {
    $mona = hrFormEmployee('Mona', ['oracle_emp_no' => '4410', 'branch_id' => 3]);
    hrFormEmployee('Mona SG', ['oracle_emp_no' => '4410', 'linked_primary_employee_id' => $mona->id]);
    $employee = hrFormEmployee('Ahmed');

    hrFormSave($employee, ['oracle_emp_no' => '4410']);

    expect(session('warning'))
        ->toContain('Oracle number 4410 is also on Mona (Cairo).')
        ->not->toContain('Mona SG');
});

it('adds an employee with the section filled in', function () {
    $boss = hrFormEmployee('Nadia');

    hrFormController()->store(hrFormRequest('POST', [
        'name' => 'Khaled the Driver',
        'status' => 'active',
        'oracle_emp_no' => '7001',
        'oracle_department' => 'Logistics',
        'manager' => "{$boss->id} · Nadia · Jeddah",
        'supervisor' => '',
    ]));

    $created = Employee::where('name', 'Khaled the Driver')->sole();

    expect($created->oracle_emp_no)->toBe('7001')
        ->and($created->oracle_department)->toBe('Logistics')
        ->and($created->manager_id)->toBe($boss->id)
        ->and($created->supervisor_id)->toBeNull();
});

it('fills the section with the saved values and offers current colleagues only', function () {
    $gone = hrFormEmployee('Tarek', ['status' => 'terminated', 'oracle_emp_no' => '900']);
    $supervisor = hrFormEmployee('Omar', ['branch_id' => 2, 'status' => 'on_leave']);
    $laila = hrFormEmployee('Laila', ['oracle_emp_no' => '1200']);
    $primary = hrFormEmployee('Samir');
    $mailbox = hrFormEmployee('Samir SG', ['linked_primary_employee_id' => $primary->id]);
    hrFormEmployee('Huda', ['oracle_department' => 'Finance', 'oracle_dept_no' => '310']);
    hrFormEmployee('Faris', ['oracle_department' => 'Sales', 'oracle_dept_no' => '410']);
    hrFormEmployee('Rana', ['oracle_department' => 'Sales', 'oracle_dept_no' => '420']);
    $employee = hrFormEmployee('Ahmed', ['manager_id' => $gone->id, 'supervisor_id' => $supervisor->id]);

    // The admin layout brings the whole navigation with it; the form is what is under test.
    $views = storage_path('framework/testing/employee-form-'.uniqid());
    File::ensureDirectoryExists($views.'/layouts');
    File::put($views.'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation($views);
    view()->share('errors', new ViewErrorBag);

    try {
        $html = hrFormController()->edit($employee)->render();
        $mailboxHtml = hrFormController()->edit($mailbox)->render();
    } finally {
        File::deleteDirectory($views);
    }

    expect($html)
        // The saved picks — a leaver included, so that saving does not drop them.
        ->toContain('name="manager" list="reporting-options"')
        ->toContain('value="'.$gone->id.' · Tarek · Oracle 900 · Jeddah · terminated"')
        ->toContain('value="'.$supervisor->id.' · Omar · Riyadh · on leave"')
        // Offered: primary records of people still employed, never the person themselves.
        ->toContain('<option value="'.$laila->id.' · Laila · Oracle 1200 · Jeddah"></option>')
        ->toContain('<option value="'.$primary->id.' · Samir · Jeddah"></option>')
        ->not->toContain('Samir SG')
        ->not->toContain('<option value="'.$gone->id.' ')
        ->not->toContain('<option value="'.$employee->id.' ')
        // Oracle departments on file, numbered only where the name has a single number.
        ->toContain('<option value="Finance">#310</option>')
        ->toContain('<option value="Sales"></option>')
        ->not->toContain('This mailbox is linked to');

    expect($mailboxHtml)
        ->toContain('This mailbox is linked to')
        ->toContain(route('admin.employees.edit', $primary->id).'#hr-reporting');
});
