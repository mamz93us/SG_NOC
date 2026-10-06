<?php

use App\Http\Controllers\Admin\SaudizationController;
use App\Models\Employee;
use App\Models\User;
use App\Services\People\DepartmentName;
use App\Services\People\Saudization;
use App\Services\People\SaudizationByDepartment;
use App\Services\People\WorkforceToolbox;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * Saudization department by department.
 *
 * What is defended: Oracle's branch-by-branch names are read as one department
 * and two different departments never are; a department's target is what its
 * own professions ask of its own people, rounded up once; people with no
 * professional group are neither counted nor lost; and the parts still add up
 * to everybody.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['saudization_groups', 'activity_logs', 'employees', 'branches', 'users'] as $table) {
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
    // Every column the counting filters or groups on has to be here: SQLite
    // reads a column it cannot find as a string, and the filter matches nothing.
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('oracle_assignment_status', 30)->nullable();
        $t->string('oracle_department')->nullable();
        $t->string('oracle_job_category')->nullable();
        $t->string('oracle_nationality')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
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

    (require database_path('migrations/2026_10_05_100004_create_saudization_groups_table.php'))->up();

    DB::table('branches')->insert([['id' => 1, 'name' => 'JED'], ['id' => 2, 'name' => 'RYD'], ['id' => 3, 'name' => 'KBR']]);

    File::ensureDirectoryExists(byDepartmentViews().'/layouts');
    File::put(byDepartmentViews().'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation(byDepartmentViews());
    view()->share('errors', new ViewErrorBag);
});

afterEach(function () {
    File::deleteDirectory(byDepartmentViews());
});

function byDepartmentViews(): string
{
    return storage_path('framework/testing/saudization-department-pages');
}

/** A user who holds exactly these permissions, without the RBAC tables. */
function departmentUser(string ...$permissions): User
{
    return new class($permissions) extends User
    {
        public function __construct(private array $held = [])
        {
            parent::__construct(['name' => 'Asker']);
        }

        public function hasPermission(string $slug): bool
        {
            return in_array($slug, $this->held, true);
        }
    };
}

/** People in one Oracle department and job category: so many Saudis, so many others. */
function departmentStaff(string $department, ?string $category, int $saudis, int $others, int $branch = 1, array $more = []): void
{
    $rows = [];
    foreach ([[$saudis, 'Saudi'], [$others, 'Indian']] as [$count, $nationality]) {
        for ($i = 0; $i < $count; $i++) {
            $rows[] = array_merge(['name' => "{$department} {$nationality} {$i}", 'oracle_assignment_status' => 'ACTIVE',
                'oracle_department' => $department, 'oracle_job_category' => $category, 'oracle_nationality' => $nationality,
                'branch_id' => $branch, 'status' => 'active', 'linked_primary_employee_id' => null], $more);
        }
    }
    Employee::insert($rows);
}

function departmentRow(string $name): ?array
{
    return app(SaudizationByDepartment::class)->report()['departments']->firstWhere('name', $name);
}

// ─── One department out of Oracle's several names ─────────────────

it('reads a department\'s branches as one department', function () {
    foreach (['Accounting - Jeddah', 'Accounting - Khobar', 'Accounting - Riyadh', 'accounting  -  riyadh', 'Accounting'] as $name) {
        expect(DepartmentName::key($name))->toBe('accounting');
    }

    expect(DepartmentName::base('Credit - Abha'))->toBe('Credit')
        ->and(DepartmentName::city('Credit - Abha'))->toBe('Abha')
        ->and(DepartmentName::city('Planning'))->toBeNull()
        // An en dash inside the name stays; the branch after it goes.
        ->and(DepartmentName::base('Digital Transformation – Professional Services - Riyadh'))->toBe('Digital Transformation – Professional Services')
        ->and(DepartmentName::key('Digital Transformation – Professional Services - Riyadh'))
        ->toBe(DepartmentName::key('Digital Transformation - Professional Services - Abha'));
});

it('joins the head office row to its branches', function () {
    // "Division" closes the name of the people at a department's head, and is
    // part of the name of others: either way it is not what tells two apart.
    expect(DepartmentName::key('Board Of Directors Division'))->toBe(DepartmentName::key('Board Of Directors - Jeddah'))
        ->and(DepartmentName::key('Information System Division'))->toBe(DepartmentName::key('Information System Division - Riyadh'))
        ->and(DepartmentName::key('Marketing Department'))->toBe(DepartmentName::key('Marketing Division'))
        ->and(DepartmentName::key('Audio Visual & Low Current Integrator Division'))
        ->toBe(DepartmentName::key('Audio Visual & Low Current Integrator - Khobar'));
});

it('reads a department\'s units as its teams, not as departments', function () {
    $keys = array_map(DepartmentName::key(...), [
        'Visualization & Integrated Projects - Unit 1 - Riyadh',
        'Visualization & Integrated Projects - UNIT 3 - Jeddah',
        'Visualization & Integrated Projects - UNIT 3 - Riyadh',
        'Visualization & Integrated Projects - Unit 4 - Khobar',
    ]);

    expect(array_unique($keys))->toBe(['visualization & integrated projects']);
});

it('never joins two departments whose names differ in their words', function () {
    // They may be one department to the people in them, but nothing in the
    // name says so, and a guess would count one's people under the other.
    $apart = [
        ['Accounting - Jeddah', 'Finance & Accounting Division'],
        ['Warehouses - Jeddah', 'Warehouses & Distribution Division'],
        ['Medical IT - Jeddah', 'Medical IT Services - Jeddah'],
        ['Clinical Diagnostics Division - Jeddah', 'Clinical Diagnostics Service Division - Jeddah'],
        ['Clinical Diagnostics Division - Jeddah', 'Clinical Diagnostics - Quidelortho - Jeddah'],
        ['Digital Transformation - Jeddah', 'Digital Transformation – Professional Services - Jeddah'],
        ['Visualization & Integrated Projects - Unit 1 - Riyadh', 'Visualization & Integrated Projects Services - Riyadh'],
        ['Personnel - Jeddah', 'Human Resources Division'],
    ];

    foreach ($apart as [$one, $other]) {
        expect(DepartmentName::key($one))->not->toBe(DepartmentName::key($other));
    }
});

it('leaves a name it does not understand as it is', function () {
    expect(DepartmentName::key('Salamah Building 2 - Jeddah'))->toBe('salamah building 2')
        // A city that is not a known branch is not stripped: it stays a row of
        // its own, named with its city, until the branch is added.
        ->and(DepartmentName::key('Accounting - Dammam'))->toBe('accounting - dammam')
        ->and(DepartmentName::key(null))->toBe('')
        ->and(DepartmentName::key('  '))->toBe('');
});

// ─── The department's target ──────────────────────────────────────

it('gives a department of one profession exactly the sum done by hand', function () {
    // Marketing: 60% of 88 is 52.8, so 53 Saudis; it has 46.
    departmentStaff('Marketing Department', 'Marketing', saudis: 46, others: 42);

    $row = departmentRow('Marketing Department');

    expect($row)->toMatchArray(['people' => 88, 'saudis' => 46, 'saudis_required' => 53, 'short_by' => 7, 'compliant' => false])
        ->and(round($row['target_percent'], 1))->toBe(60.0)
        // The same answer the professional group gets.
        ->and($row['short_by'])->toBe(Saudization::shortBy(46, 88, 60));
});

it('counts a department\'s branches together', function () {
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 6, others: 9, branch: 1);
    departmentStaff('Accounting - Khobar', 'Accountant', saudis: 0, others: 1, branch: 3);
    departmentStaff('Accounting - Riyadh', 'Accountant', saudis: 1, others: 0, branch: 2);

    $report = app(SaudizationByDepartment::class)->report();
    $row = $report['departments']->sole();

    // 40% of 17 is 6.8: seven, and there are seven.
    expect($row['name'])->toBe('Accounting')
        ->and($row)->toMatchArray(['people' => 17, 'saudis' => 7, 'saudis_required' => 7, 'short_by' => 0, 'compliant' => true])
        ->and($row['branches'])->toBe(['JED' => 15, 'KBR' => 1, 'RYD' => 1])
        ->and($row['oracle_departments'])->toBe(['Accounting - Jeddah' => 15, 'Accounting - Khobar' => 1, 'Accounting - Riyadh' => 1]);

    // Branch by branch the same people would have been short: 40% of 15 is 6,
    // 40% of 1 is 1 with none. Rounding a department once is the point.
    expect($report['short_by'])->toBe(0);
});

it('sets a mixed department\'s target from what each of its professions asks', function () {
    // 40% of 6 accountants is 2.4, 100% of 2 administrative staff is 2:
    // 4.4 between them, so five Saudis of eight.
    departmentStaff('Credit - Jeddah', 'Accountant', saudis: 1, others: 5);
    departmentStaff('Credit - Riyadh', 'Administrative support', saudis: 2, others: 0, branch: 2);

    $row = departmentRow('Credit');

    expect($row)->toMatchArray(['people' => 8, 'saudis' => 3, 'saudis_required' => 5, 'short_by' => 2, 'compliant' => false])
        ->and($row['target_percent'])->toEqualWithDelta(55.0, 0.0001)
        ->and($row['groups']->pluck('asks', 'group.job_category')->all())->toBe(['Accountant' => 2.4, 'Administrative support' => 2.0])
        // Each profession's own standing is still shown under the row.
        ->and($row['groups']->pluck('meets', 'group.job_category')->all())->toBe(['Accountant' => false, 'Administrative support' => true]);
});

it('rounds a department\'s target up once, not once per profession', function () {
    // Three professions asking 0.3, 0.25 and 0.25 of a Saudi: 0.8, so one —
    // not the three that rounding each of them up would demand.
    departmentStaff('Planning', 'Engineers', saudis: 0, others: 1);
    departmentStaff('Planning', 'AppDev&prog&analysis', saudis: 0, others: 1);
    departmentStaff('Planning', 'Comm eng &information tech', saudis: 1, others: 0);

    expect(departmentRow('Planning'))->toMatchArray(['people' => 3, 'saudis' => 1, 'saudis_required' => 1, 'compliant' => true, 'short_by' => 0]);
});

it('does not count people with no professional group at all, as Saudis or otherwise', function () {
    // Twelve Saudi drivers do not make up for the Saudi engineers it lacks,
    // and three who are not Saudi do not count against it either: they are
    // in no figure of the row — not its people, not its branches.
    departmentStaff('Warehouses - Jeddah', 'Engineers', saudis: 0, others: 10);
    departmentStaff('Warehouses - Jeddah', null, saudis: 12, others: 3);
    departmentStaff('Warehouses - Riyadh', 'Customs Clearance', saudis: 2, others: 0, branch: 2);

    $report = app(SaudizationByDepartment::class)->report();
    $row = $report['departments']->sole();

    expect($row)->toMatchArray(['name' => 'Warehouses', 'people' => 10, 'saudis' => 0, 'saudis_required' => 3, 'short_by' => 3, 'compliant' => false])
        ->and($row['branches'])->toBe(['JED' => 10])
        ->and($row['oracle_departments'])->toBe(['Warehouses - Jeddah' => 10])
        ->and($row)->not->toHaveKeys(['outside_people', 'outside_saudis'])
        // Only how many were left out.
        ->and($report['people'])->toBe(10)
        ->and($report['uncounted_people'])->toBe(17);
});

it('does not list a department with nobody in a professional group, and says which it left out', function () {
    departmentStaff('Office Services - Jeddah', null, saudis: 2, others: 7);
    departmentStaff('Human Resources Division', null, saudis: 3, others: 0);
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 2, others: 2);

    $report = app(SaudizationByDepartment::class)->report();

    expect($report['departments']->pluck('name')->all())->toBe(['Accounting'])
        ->and($report['compliant'])->toBe(1)
        ->and($report['not_compliant'])->toBe(0)
        ->and($report['uncounted_people'])->toBe(12)
        ->and($report['unlisted'])->toBe([
            ['name' => 'Human Resources Division', 'people' => 3],
            ['name' => 'Office Services', 'people' => 9],
        ])
        ->and($report)->not->toHaveKey('no_target');
});

it('adds up to everybody Oracle lists, and counts a person once', function () {
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 3, others: 4);
    departmentStaff('Sales Division', 'Sales', saudis: 5, others: 5, branch: 2);
    departmentStaff('Warehouses - Jeddah', null, saudis: 1, others: 6);
    departmentStaff('', 'Sales', saudis: 1, others: 0);
    $one = Employee::where('oracle_department', 'Accounting - Jeddah')->first();

    // Not counted: a second mailbox, somebody who left, somebody Oracle does not list.
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 1, others: 0, more: ['linked_primary_employee_id' => $one->id]);
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 2, others: 0, more: ['status' => 'terminated']);
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 2, others: 0, more: ['oracle_assignment_status' => null]);

    $report = app(SaudizationByDepartment::class)->report();

    $groups = app(Saudization::class)->report();

    // Everybody Oracle lists is either counted or told apart as not counted,
    // and the two pages agree on both.
    expect($report['people'] + $report['uncounted_people'])->toBe(25)
        ->and($report['people'])->toBe($groups['overall']['people'])
        ->and($report['saudis'])->toBe($groups['overall']['saudis'])
        ->and($report['uncounted_people'])->toBe($groups['uncounted']['people'])
        ->and(departmentRow('Accounting'))->toMatchArray(['people' => 7, 'saudis' => 3])
        // Somebody with no Oracle department is named, not dropped.
        ->and(departmentRow('No department in Oracle'))->toMatchArray(['people' => 1, 'saudis' => 1]);
});

it('names a department by the spelling most of its people are under', function () {
    departmentStaff('Information System Division - Jeddah', 'AppDev&prog&analysis', saudis: 2, others: 3);
    departmentStaff('Information System Division', 'AppDev&prog&analysis', saudis: 1, others: 0);
    departmentStaff('Board Of Directors - Jeddah', 'Administrative support', saudis: 3, others: 0);
    departmentStaff('Board Of Directors Division', 'Administrative support', saudis: 2, others: 0);

    expect(app(SaudizationByDepartment::class)->report('name')['departments']->pluck('name')->all())
        ->toBe(['Board Of Directors', 'Information System Division']);
});

it('puts the departments needing the most Saudis first, or sorts as asked', function () {
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 5, others: 5);          // meets
    departmentStaff('Sales Division', 'Sales', saudis: 2, others: 18, branch: 2);         // 12 of 20, needs 10
    departmentStaff('Engineering - Jeddah', 'Engineers', saudis: 1, others: 9);           // 3 of 10, needs 2
    departmentStaff('Zebra Stores - Jeddah', null, saudis: 0, others: 40);                // nobody counted: not a row
    departmentStaff('Engineering - Jeddah', null, saudis: 30, others: 0);                 // and not part of a row's size

    $names = fn (string $sort) => app(SaudizationByDepartment::class)->report($sort)['departments']->pluck('name')->all();

    expect($names('needs'))->toBe(['Sales Division', 'Engineering', 'Accounting'])
        ->and($names('name'))->toBe(['Accounting', 'Engineering', 'Sales Division'])
        ->and($names('people'))->toBe(['Sales Division', 'Accounting', 'Engineering']);
});

// ─── The page ─────────────────────────────────────────────────────

it('opens to whoever can open the Saudization table, and is not read as somebody\'s id', function () {
    $routes = Route::getRoutes();

    expect($routes->getByName('admin.people.saudization.departments')->gatherMiddleware())->toContain('permission:view-attendance,view-vacations')
        ->and($routes->match(Request::create('/admin/people/saudization/departments', 'GET'))->getName())->toBe('admin.people.saudization.departments');
});

it('shows each department with its status, its professions and the Oracle names behind it', function () {
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 1, others: 5);
    departmentStaff('Accounting - Riyadh', 'Administrative support', saudis: 2, others: 0, branch: 2);
    departmentStaff('Accounting - Riyadh', null, saudis: 0, others: 3, branch: 2);
    test()->actingAs(departmentUser('view-attendance'));

    $request = Request::create('/admin/people/saudization/departments', 'GET');
    $request->setUserResolver(fn () => auth()->user());
    $html = app(SaudizationController::class)->departments($request, app(SaudizationByDepartment::class), app(Saudization::class))->render();

    expect($html)->toContain('Below its target')
        ->toContain('needs 2 more Saudis')
        ->toContain('5 Saudis')
        ->toContain('55% of 8')
        ->not->toContain('no group')
        ->toContain('<strong>3 people</strong>')
        ->toContain('are not counted at all, as Saudi or otherwise')
        ->toContain('Accounting - Jeddah')
        ->toContain('Accounting - Riyadh')
        ->toContain('المهن المحاسبية')
        ->toContain('4.4 → 5')
        // Both ways of reading it are one click apart.
        ->toContain(route('admin.people.saudization'))
        ->toContain('By professional group');

    expect(fn () => app(SaudizationController::class)->departments(
        Request::create('/admin/people/saudization/departments', 'GET', ['sort' => 'salary']), app(SaudizationByDepartment::class), app(Saudization::class)
    ))->toThrow(ValidationException::class);
});

it('says so when the departments add up to more than the professional groups do', function () {
    // Accountants: 5 of 10 in one department and 0 of 2 in another. The group
    // is 5 of 12 against 40% and short of nobody; the second department on its
    // own is short of one. A department over its target lends nothing.
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 5, others: 5);
    departmentStaff('Credit - Jeddah', 'Accountant', saudis: 0, others: 2);
    test()->actingAs(departmentUser('view-attendance'));

    $request = Request::create('/admin/people/saudization/departments', 'GET');
    $request->setUserResolver(fn () => auth()->user());
    $view = app(SaudizationController::class)->departments($request, app(SaudizationByDepartment::class), app(Saudization::class));

    expect($view->getData()['short_by'])->toBe(1)
        ->and($view->getData()['groupsShortBy'])->toBe(0)
        ->and($view->render())->toContain('The two differ because a department above its target');
});

// ─── The assistant ────────────────────────────────────────────────

it('gives the assistant each department\'s standing, in the page\'s own figures and no names', function () {
    departmentStaff('Marketing Department', 'Marketing', saudis: 46, others: 42);
    departmentStaff('Accounting - Jeddah', 'Accountant', saudis: 6, others: 9);
    departmentStaff('Accounting - Riyadh', 'Accountant', saudis: 1, others: 1, branch: 2);
    $toolbox = new WorkforceToolbox(departmentUser('view-vacations'));

    $all = $toolbox->call('get_saudization_by_department', []);

    expect($all['departments_below_their_target'])->toBe(1)
        ->and($all['departments'][0])->toMatchArray([
            'department' => 'Marketing Department', 'employees_in_a_professional_group' => 88, 'saudis' => 46,
            'target_saudis' => 53, 'target_percent' => 60.0, 'status' => 'below its target', 'more_saudis_needed' => 7,
        ])
        ->and($all['note'])->toContain('not an official requirement');

    $accounting = $toolbox->call('get_saudization_by_department', ['department' => 'accounting']);

    expect($accounting['departments'])->toHaveCount(1)
        ->and($accounting['departments'][0])->toMatchArray(['department' => 'Accounting', 'branches' => ['JED' => 15, 'RYD' => 2],
            'status' => 'meets its target', 'target_saudis' => 7])
        ->and($accounting['departments'][0])->not->toHaveKey('more_saudis_needed');

    expect($toolbox->call('get_saudization_by_department', ['only' => 'below_target'])['departments'])->toHaveCount(1)
        ->and($toolbox->call('get_saudization_by_department', ['department' => 'Legal']))->toHaveKey('error')
        ->and($toolbox->call('get_saudization_by_department', ['only' => 'worst']))->toHaveKey('error')
        ->and(json_encode($all))->not->toContain('Marketing Department Saudi 0');

    // Somebody who cannot open the page is not offered it, and not answered.
    $nobody = new WorkforceToolbox(departmentUser('view-employees'));

    expect($nobody->call('get_saudization_by_department', []))->toHaveKey('error')
        ->and(collect($nobody->definitions())->pluck('function.name')->all())->toBe([]);
});
