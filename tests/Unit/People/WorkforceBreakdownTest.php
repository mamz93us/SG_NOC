<?php

use App\Http\Controllers\Admin\WorkforceBreakdownController;
use App\Models\Employee;
use App\Models\User;
use App\Services\People\WorkforceBreakdown;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;

/**
 * Workforce breakdown: everybody counted by Oracle's job category or
 * profession, and the people behind each count.
 *
 * What is defended: the parts add up to the whole — nobody is dropped for
 * having nothing in the field — a count and the list it opens are the same
 * people, one person is counted once however many mailboxes they hold, and
 * somebody Oracle does not list is not reported as having no profession.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['activity_logs', 'employees', 'departments', 'branches', 'users'] as $table) {
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
    // Every column the page filters on has to be here: SQLite reads a column
    // it cannot find as a string, and the filter then quietly matches nothing.
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('name_ar')->nullable();
        $t->string('email')->nullable();
        $t->string('job_title')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('oracle_assignment_status', 30)->nullable();
        $t->string('oracle_job_category')->nullable();
        $t->string('oracle_profession')->nullable();
        $t->string('oracle_nationality')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
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

    DB::table('branches')->insert([['id' => 1, 'name' => 'JED'], ['id' => 2, 'name' => 'RYD'], ['id' => 3, 'name' => 'CAI']]);
    DB::table('departments')->insert(['id' => 1, 'name' => 'Information Systems']);

    $admin = new User(['name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);

    // The admin layout brings the whole navigation with it; the page is what is under test.
    File::ensureDirectoryExists(breakdownViews().'/layouts');
    File::put(breakdownViews().'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation(breakdownViews());
    view()->share('errors', new ViewErrorBag);
});

afterEach(function () {
    File::deleteDirectory(breakdownViews());
});

function breakdownViews(): string
{
    return storage_path('framework/testing/breakdown-pages');
}

/** Somebody Oracle lists, with whatever it says about them. */
function oraclePerson(string $name, ?string $category, ?string $profession, array $more = []): Employee
{
    return Employee::create(array_merge([
        'name' => $name, 'oracle_emp_no' => (string) (1000 + Employee::count()), 'oracle_assignment_status' => 'ACTIVE',
        'oracle_job_category' => $category, 'oracle_profession' => $profession, 'branch_id' => 1, 'status' => 'active',
    ], $more));
}

/**
 * Three developers, two in sales, one Oracle lists with no category, and one
 * in Cairo whom Oracle's list does not cover at all.
 */
function breakdownWorkforce(): void
{
    oraclePerson('Amal Dev', 'AppDev&prog&analysis', 'محلل نظم المعلومات');
    oraclePerson('Basel Dev', 'AppDev&prog&analysis', 'مبرمج');
    oraclePerson('Dina Dev', 'AppDev&prog&analysis', 'محلل نظم المعلومات', ['branch_id' => 2]);
    oraclePerson('Fahad Sales', 'Sales', 'مندوب مبيعات');
    oraclePerson('Ghada Sales', 'Sales', null, ['branch_id' => 2]);
    oraclePerson('Hani Driver', null, 'سائق');
    Employee::create(['name' => 'Karim Cairo', 'oracle_emp_no' => '55512', 'branch_id' => 3, 'status' => 'active']);
}

/** @param  array<string, mixed>  $query */
function breakdownPage(array $query = [])
{
    $request = Request::create('/admin/people/breakdown', 'GET', $query);
    $request->setUserResolver(fn () => auth()->user());

    return app(WorkforceBreakdownController::class)->index($request, app(WorkforceBreakdown::class));
}

function breakdownCounts(string $dimension, array $filters = []): array
{
    return app(WorkforceBreakdown::class)->groups($dimension, $filters)->pluck('count', 'key')->all();
}

it('opens to the people who can open employee profiles, and is not read as somebody\'s id', function () {
    $route = Route::getRoutes()->getByName('admin.people.breakdown');

    expect($route?->gatherMiddleware())->toContain('permission:view-attendance,view-vacations');

    // people/{employee} is registered after it; were it first, "breakdown"
    // would be looked up as an employee and answer 404.
    $matched = Route::getRoutes()->match(Request::create('/admin/people/breakdown', 'GET'));

    expect($matched->getName())->toBe('admin.people.breakdown');
});

it('counts everybody by job category, largest first', function () {
    breakdownWorkforce();

    $groups = app(WorkforceBreakdown::class)->groups('job_category', []);

    expect($groups->pluck('count', 'key')->all())->toBe([
        'AppDev&prog&analysis' => 3,
        'Sales' => 2,
        WorkforceBreakdown::NONE => 1,
        WorkforceBreakdown::OUTSIDE => 1,
    ]);
    expect($groups->firstWhere('key', 'Sales')['share'])->toEqualWithDelta(2 / 7, 0.0001);
});

it('counts everybody by profession', function () {
    breakdownWorkforce();

    expect(breakdownCounts('profession'))->toBe([
        'محلل نظم المعلومات' => 2,
        'سائق' => 1,
        'مبرمج' => 1,
        'مندوب مبيعات' => 1,
        WorkforceBreakdown::NONE => 1,
        WorkforceBreakdown::OUTSIDE => 1,
    ]);
});

it('counts everybody by nationality', function () {
    breakdownWorkforce();
    Employee::where('name', 'like', '% Dev')->update(['oracle_nationality' => 'Saudi']);
    Employee::where('name', 'Fahad Sales')->update(['oracle_nationality' => 'Indian']);
    // The nationality export lists people Oracle's API list no longer does.
    Employee::where('name', 'Karim Cairo')->update(['oracle_nationality' => 'Egyptian']);

    expect(breakdownCounts('nationality'))->toBe([
        'Saudi' => 3,
        'Egyptian' => 1,
        'Indian' => 1,
        // Ghada and Hani: Oracle lists them, the export gave no nationality.
        WorkforceBreakdown::NONE => 2,
    ]);

    $html = breakdownPage(['by' => 'nationality', 'pick' => 'Saudi'])->render();

    expect($html)->toContain('No nationality in Oracle')
        ->toContain('Amal Dev')
        ->not->toContain('Fahad Sales');
});

it('adds up to the whole workforce, whatever it is divided by', function () {
    breakdownWorkforce();

    foreach (array_keys(WorkforceBreakdown::DIMENSIONS) as $dimension) {
        expect(array_sum(breakdownCounts($dimension)))->toBe(7);
    }
});

it('does not call somebody Oracle does not list a person without a profession', function () {
    // Oracle's list is the Saudi book. A Cairo employee has no category there
    // because Oracle never said anything about them, not because it said none.
    breakdownWorkforce();

    $none = app(WorkforceBreakdown::class)->employees('job_category', WorkforceBreakdown::NONE, []);
    $outside = app(WorkforceBreakdown::class)->employees('job_category', WorkforceBreakdown::OUTSIDE, []);

    expect($none->pluck('name')->all())->toBe(['Hani Driver'])
        ->and($outside->pluck('name')->all())->toBe(['Karim Cairo']);
});

it('opens exactly the people a count says', function () {
    breakdownWorkforce();
    $breakdown = app(WorkforceBreakdown::class);

    foreach (array_keys(WorkforceBreakdown::DIMENSIONS) as $dimension) {
        foreach ($breakdown->groups($dimension, []) as $group) {
            expect($breakdown->employees($dimension, $group['key'], [])->total())->toBe($group['count']);
        }
    }

    expect($breakdown->employees('job_category', 'AppDev&prog&analysis', [])->pluck('name')->all())
        ->toBe(['Amal Dev', 'Basel Dev', 'Dina Dev']);
});

it('counts a person once, however many mailboxes they hold', function () {
    breakdownWorkforce();
    $amal = Employee::where('name', 'Amal Dev')->sole();
    // The same person's second mailbox, carrying a category of its own by mistake.
    Employee::create(['name' => 'Amal Second Mailbox', 'linked_primary_employee_id' => $amal->id,
        'oracle_job_category' => 'AppDev&prog&analysis', 'oracle_assignment_status' => 'ACTIVE', 'status' => 'active']);

    expect(breakdownCounts('job_category')['AppDev&prog&analysis'])->toBe(3);
});

it('counts current staff unless asked for leavers, and narrows to a branch', function () {
    breakdownWorkforce();
    oraclePerson('Tarek Left', 'Sales', 'مندوب مبيعات', ['status' => 'terminated']);

    expect(breakdownCounts('job_category')['Sales'])->toBe(2)
        ->and(breakdownCounts('job_category', ['status' => 'left']))->toBe(['Sales' => 1])
        ->and(breakdownCounts('job_category', ['status' => 'all'])['Sales'])->toBe(3)
        ->and(breakdownCounts('job_category', ['branch' => 2]))->toBe(['AppDev&prog&analysis' => 1, 'Sales' => 1]);
});

it('searches inside the picked group without changing the counts', function () {
    breakdownWorkforce();

    $data = breakdownPage(['by' => 'job_category', 'pick' => 'AppDev&prog&analysis', 'q' => 'Basel'])->getData();

    expect($data['employees']->pluck('name')->all())->toBe(['Basel Dev'])
        ->and($data['groups']->firstWhere('key', 'AppDev&prog&analysis')['count'])->toBe(3)
        ->and($data['total'])->toBe(7);
});

it('shows everybody when the picked group belongs to the other tab', function () {
    // The tabs share one URL: a job category left in it is not a profession.
    breakdownWorkforce();

    $data = breakdownPage(['by' => 'profession', 'pick' => 'Sales'])->getData();

    expect($data['picked'])->toBeNull()
        ->and($data['employees']->total())->toBe(7);
});

it('renders the counts, the people and a link to each profile', function () {
    breakdownWorkforce();
    $amal = Employee::where('name', 'Amal Dev')->sole();

    $html = breakdownPage(['by' => 'profession', 'pick' => 'محلل نظم المعلومات'])->render();

    expect($html)->toContain('Workforce breakdown')
        ->toContain('محلل نظم المعلومات')
        ->toContain('Not in Oracle&#039;s employee list')
        ->toContain('No profession in Oracle')
        ->toContain(route('admin.people.show', $amal))
        ->toContain('Dina Dev')
        // Not in the picked profession.
        ->not->toContain('Basel Dev');
});

it('rejects a way of counting it does not know', function () {
    expect(fn () => breakdownPage(['by' => 'salary']))->toThrow(Illuminate\Validation\ValidationException::class);
});
