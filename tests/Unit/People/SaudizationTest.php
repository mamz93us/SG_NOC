<?php

use App\Http\Controllers\Admin\SaudizationController;
use App\Models\Employee;
use App\Models\SaudizationGroup;
use App\Models\User;
use App\Services\People\Saudization;
use App\Services\People\WorkforceToolbox;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

/**
 * Saudization: each professional group's share of Saudis against the
 * percentage it must reach.
 *
 * What is defended: the arithmetic at the edges (9 of 13 is not 70%), a person
 * counted once and nobody left out, a missing nationality never flattering a
 * group, the targets being HR's to edit and only theirs, and the assistant
 * being given counts — never names — and only for the people who can open the
 * page.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['saudization_groups', 'activity_logs', 'employees', 'departments', 'branches', 'users'] as $table) {
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
    // Every column the counting filters on has to be here: SQLite reads a
    // column it cannot find as a string, and the filter then matches nothing.
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

    // The real migration, so the seeded table is the one HR opens.
    (require database_path('migrations/2026_10_05_100004_create_saudization_groups_table.php'))->up();

    DB::table('branches')->insert([['id' => 1, 'name' => 'JED'], ['id' => 2, 'name' => 'RYD']]);

    $admin = new User(['name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);

    File::ensureDirectoryExists(saudizationViews().'/layouts');
    File::put(saudizationViews().'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation(saudizationViews());
    view()->share('errors', new ViewErrorBag);
});

afterEach(function () {
    File::deleteDirectory(saudizationViews());
});

function saudizationViews(): string
{
    return storage_path('framework/testing/saudization-pages');
}

/**
 * The signed-in user holds exactly these permissions. A real answer from
 * hasPermission(), because that is what the route gates and the menu ask.
 */
function saudizationCan(string ...$abilities): void
{
    test()->actingAs(workforceUser(...$abilities));
}

/** People in one Oracle job category: so many Saudis, so many of another nationality. */
function staff(string $category, int $saudis, int $others, array $more = []): void
{
    foreach ([[$saudis, 'Saudi'], [$others, 'Indian']] as [$count, $nationality]) {
        for ($i = 0; $i < $count; $i++) {
            Employee::create(array_merge(['name' => "{$category} {$nationality} {$i}", 'oracle_assignment_status' => 'ACTIVE',
                'oracle_job_category' => $category, 'oracle_nationality' => $nationality, 'branch_id' => 1, 'status' => 'active'], $more));
        }
    }
}

function saudizationRow(string $category, ?CarbonImmutable $today = null): array
{
    return app(Saudization::class)->report($today)['groups']->first(fn (array $row) => $row['group']->job_category === $category);
}

/** A user who holds exactly these permissions, without the RBAC tables. */
function workforceUser(string ...$permissions): User
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

// ─── The seeded table ─────────────────────────────────────────────

it('seeds HR\'s seventeen groups once, and never puts a changed percentage back', function () {
    expect(SaudizationGroup::count())->toBe(17);

    $accounting = SaudizationGroup::where('job_category', 'Accountant')->sole();

    expect($accounting->name_ar)->toBe('المهن المحاسبية')
        ->and($accounting->required_percent)->toBe(40.0)
        ->and($accounting->next_percent)->toBe(50.0)
        ->and($accounting->next_from->format('Y-m'))->toBe('2026-10')
        ->and($accounting->future_percent)->toBe(60.0)
        ->and($accounting->future_from->format('Y-m'))->toBe('2027-10');

    $accounting->update(['required_percent' => 50]);
    (require database_path('migrations/2026_10_05_100004_create_saudization_groups_table.php'))->up();

    expect(SaudizationGroup::count())->toBe(17)
        ->and($accounting->fresh()->required_percent)->toBe(50.0);
});

it('gives every group a job category of its own', function () {
    $categories = SaudizationGroup::pluck('job_category');

    expect($categories->filter()->count())->toBe(17)
        ->and($categories->unique()->count())->toBe(17);
});

// ─── The arithmetic ───────────────────────────────────────────────

it('decides a percentage on whole numbers, not on how the share was rounded', function () {
    // 9 of 13 is 69.2%: under 70, however it is displayed.
    expect(Saudization::meets(9, 13, 70))->toBeFalse()
        ->and(Saudization::meets(10, 13, 70))->toBeTrue()
        // Exactly on the line counts.
        ->and(Saudization::meets(3, 10, 30))->toBeTrue()
        ->and(Saudization::meets(24, 24, 100))->toBeTrue()
        ->and(Saudization::meets(23, 24, 100))->toBeFalse()
        // Nobody in the group is not "compliant".
        ->and(Saudization::meets(0, 0, 30))->toBeFalse();
});

it('says how many more Saudis a group needs at the size it is today', function () {
    // Marketing on 2026-10-05: 60% of 88 is 52.8, so 53 Saudis, and it has 46.
    // The first version said 17 — the hires it would take, each one growing
    // the group — and HR read it as wrong, which for this question it was.
    expect(Saudization::required(88, 60))->toBe(53)
        ->and(Saudization::shortBy(46, 88, 60))->toBe(7)
        // 30% of 52 is 15.6: sixteen.
        ->and(Saudization::shortBy(14, 52, 30))->toBe(2)
        // One pharmacist: 55% of 1 rounds up to that one person.
        ->and(Saudization::shortBy(0, 1, 55))->toBe(1)
        ->and(Saudization::shortBy(23, 24, 100))->toBe(1)
        // Exactly on the line needs nobody, and neither does being over it.
        ->and(Saudization::required(10, 30))->toBe(3)
        ->and(Saudization::shortBy(3, 10, 30))->toBe(0)
        ->and(Saudization::shortBy(10, 19, 40))->toBe(0)
        ->and(Saudization::shortBy(0, 0, 60))->toBe(0);
});

it('is short by nought exactly when it is compliant', function () {
    // meets() and shortBy() draw the same line, so the badge and the number
    // under it can never disagree.
    foreach ([25, 30, 40, 55, 60, 65, 70, 100, 33.33] as $percent) {
        foreach (range(1, 60) as $people) {
            foreach (range(0, $people) as $saudis) {
                expect(Saudization::shortBy($saudis, $people, $percent) === 0)->toBe(Saudization::meets($saudis, $people, $percent));
            }
        }
    }
});

// ─── The report ───────────────────────────────────────────────────

it('sets each group\'s Saudi share against the percentage it must reach', function () {
    staff('Engineers', saudis: 14, others: 38);
    staff('Accountant', saudis: 10, others: 9);
    staff('Administrative support', saudis: 24, others: 0);

    $engineers = saudizationRow('Engineers');
    $accounting = saudizationRow('Accountant');
    $admin = saudizationRow('Administrative support');

    expect($engineers['people'])->toBe(52)
        ->and($engineers['saudis'])->toBe(14)
        ->and(round($engineers['share'], 1))->toBe(26.9)
        ->and($engineers['required'])->toBe(30.0)
        ->and($engineers['compliant'])->toBeFalse()
        ->and($engineers['saudis_required'])->toBe(16)
        ->and($engineers['short_by'])->toBe(2);

    expect($accounting['compliant'])->toBeTrue()
        ->and($admin['compliant'])->toBeTrue();

    $report = app(Saudization::class)->report();

    expect($report['compliant'])->toBe(2)
        ->and($report['not_compliant'])->toBe(1)
        // Fourteen groups have nobody in them: neither compliant nor not.
        ->and($report['empty'])->toBe(14)
        ->and($report['overall'])->toMatchArray(['people' => 95, 'saudis' => 48]);
});

it('checks today\'s staff against the percentages announced for later', function () {
    staff('Accountant', saudis: 10, others: 9); // 52.6%

    $steps = collect(saudizationRow('Accountant', CarbonImmutable::parse('2026-10-05'))['announced'])->keyBy('key');

    // 50% from October 2026: met, and the month is here.
    expect($steps['next']['percent'])->toBe(50.0)
        ->and($steps['next']['meets'])->toBeTrue()
        ->and($steps['next']['arrived'])->toBeTrue()
        // 60% from October 2027: not yet met, and not yet due.
        ->and($steps['future']['meets'])->toBeFalse()
        // 60% of 19 is 11.4: twelve, and there are ten.
        ->and($steps['future']['short_by'])->toBe(2)
        ->and($steps['future']['arrived'])->toBeFalse();

    // The current percentage does not move by itself when the month arrives.
    expect(saudizationRow('Accountant', CarbonImmutable::parse('2027-11-01'))['required'])->toBe(40.0);
});

it('counts a person once, and only current staff Oracle lists', function () {
    staff('Sales', saudis: 3, others: 2);
    $saudi = Employee::where('oracle_job_category', 'Sales')->where('oracle_nationality', 'Saudi')->first();

    // The same person's second mailbox, somebody who left, and a Cairo
    // employee Oracle's list does not cover.
    Employee::create(['name' => 'Second mailbox', 'linked_primary_employee_id' => $saudi->id, 'oracle_assignment_status' => 'ACTIVE',
        'oracle_job_category' => 'Sales', 'oracle_nationality' => 'Saudi', 'status' => 'active']);
    staff('Sales', saudis: 4, others: 0, more: ['status' => 'terminated']);
    Employee::create(['name' => 'Cairo', 'oracle_job_category' => 'Sales', 'oracle_nationality' => 'Egyptian', 'status' => 'active']);

    expect(saudizationRow('Sales'))->toMatchArray(['people' => 5, 'saudis' => 3]);
});

it('counts somebody with no nationality in the group and not as a Saudi', function () {
    // A missing nationality must not make a group look better than it is.
    staff('Purchases', saudis: 7, others: 2);
    Employee::create(['name' => 'Unknown', 'oracle_assignment_status' => 'ACTIVE', 'oracle_job_category' => 'Purchases', 'status' => 'active']);

    $row = saudizationRow('Purchases');

    // 7 of 9 would be 77.8% and compliant; 7 of 10 is exactly 70%.
    expect($row['people'])->toBe(10)
        ->and($row['saudis'])->toBe(7)
        ->and($row['unknown'])->toBe(1)
        ->and($row['compliant'])->toBeTrue();
});

it('reads the nationality whatever its case', function () {
    staff('Legal', saudis: 0, others: 0);
    Employee::create(['name' => 'Upper', 'oracle_assignment_status' => 'ACTIVE', 'oracle_job_category' => 'Legal', 'oracle_nationality' => 'SAUDI', 'status' => 'active']);

    expect(saudizationRow('Legal'))->toMatchArray(['people' => 1, 'saudis' => 1, 'compliant' => true]);
});

it('names everybody no group counts, instead of leaving them out', function () {
    staff('Sales', saudis: 1, others: 1);
    staff('Customs Clearance', saudis: 2, others: 0);
    Employee::create(['name' => 'Driver', 'oracle_assignment_status' => 'ACTIVE', 'oracle_nationality' => 'Indian', 'status' => 'active']);

    $report = app(Saudization::class)->report();
    $outside = $report['outside']->keyBy(fn (array $count) => $count['job_category'] ?? '(none)');

    expect($outside->keys()->all())->toBe(['Customs Clearance', '(none)'])
        ->and($outside['Customs Clearance'])->toMatchArray(['people' => 2, 'saudis' => 2])
        ->and($outside['(none)'])->toMatchArray(['people' => 1, 'saudis' => 0])
        // The groups and what is outside them add up to everybody.
        ->and($report['groups']->sum('people') + $report['outside']->sum('people'))->toBe($report['overall']['people']);
});

// ─── The pages ────────────────────────────────────────────────────

it('opens to the people who can open workforce breakdown, and edits only with its own permission', function () {
    $routes = Route::getRoutes();

    expect($routes->getByName('admin.people.saudization')->gatherMiddleware())->toContain('permission:view-attendance,view-vacations');

    foreach (['admin.people.saudization.edit', 'admin.people.saudization.update'] as $name) {
        $gates = array_values(array_filter($routes->getByName($name)->gatherMiddleware(), fn ($m) => str_starts_with((string) $m, 'permission:')));

        // Its only gate: nested in the people group it would need both.
        expect($gates)->toBe(['permission:manage-saudization']);
    }

    // Registered before people/{employee}, which would read these as an id.
    expect($routes->match(Request::create('/admin/people/saudization', 'GET'))->getName())->toBe('admin.people.saudization')
        ->and($routes->match(Request::create('/admin/people/saudization/edit', 'GET'))->getName())->toBe('admin.people.saudization.edit');
});

it('shows the table with each group\'s status, and Edit only to someone who may', function () {
    staff('Engineers', saudis: 14, others: 38);
    staff('Accountant', saudis: 10, others: 9);
    saudizationCan('view-attendance');

    $request = Request::create('/admin/people/saudization', 'GET');
    $request->setUserResolver(fn () => auth()->user());
    $html = app(SaudizationController::class)->index($request, app(Saudization::class))->render();

    expect($html)->toContain('المهن الهندسية')
        ->toContain('غير ملتزمة بالتوطين')
        ->toContain('needs 2 more Saudis')
        ->toContain('30% of 52 people is 16 Saudis; the group has 14.')
        ->toContain('from Oct 2026')
        ->toContain('head count')
        ->toContain(route('admin.people.breakdown', ['by' => 'nationality']))
        ->not->toContain(route('admin.people.saudization.edit'));

    saudizationCan('view-attendance', 'manage-saudization');
    $html = app(SaudizationController::class)->index($request, app(Saudization::class))->render();

    expect($html)->toContain(route('admin.people.saudization.edit'));
});

it('renders the edit form with every group and a blank row for a new one', function () {
    staff('Engineers', saudis: 1, others: 1);

    $html = app(SaudizationController::class)->edit()->render();

    expect($html)->toContain('name="groups[1][required_percent]" value="30"')
        ->toContain('value="2026-10"')
        ->toContain('name="new[name_ar]"')
        // The category in use is offered, and so is one a group already holds.
        ->toContain('<option value="Engineers" selected>')
        ->toContain('<option value="Accountant" selected>');
});

// ─── Editing the targets ──────────────────────────────────────────

/** Every group as the edit form would post it, with some fields changed. */
function saudizationForm(array $changes = [], array $extra = []): Request
{
    $groups = SaudizationGroup::all()->mapWithKeys(fn (SaudizationGroup $g) => [$g->id => array_merge([
        'name_ar' => $g->name_ar, 'name_en' => $g->name_en, 'job_category' => $g->job_category,
        'required_percent' => $g->required_percent,
        'next_percent' => $g->next_percent, 'next_from' => $g->next_from?->format('Y-m'),
        'future_percent' => $g->future_percent, 'future_from' => $g->future_from?->format('Y-m'),
    ], $changes[$g->id] ?? [])])->all();

    $request = Request::create('/admin/people/saudization', 'PUT', ['groups' => $groups] + $extra);
    $request->setUserResolver(fn () => auth()->user());
    $request->setLaravelSession(app('session.store'));

    return $request;
}

it('saves a changed percentage and the percentages announced for later', function () {
    saudizationCan('view-attendance', 'manage-saudization');
    $engineers = SaudizationGroup::where('job_category', 'Engineers')->sole();
    $accounting = SaudizationGroup::where('job_category', 'Accountant')->sole();

    $response = app(SaudizationController::class)->update(saudizationForm([
        $engineers->id => ['required_percent' => '35.5', 'next_percent' => '40', 'next_from' => '2027-03'],
        // October arrived: 50 becomes the current one, and 60 moves up.
        $accounting->id => ['required_percent' => '50', 'next_percent' => '60', 'next_from' => '2027-10', 'future_percent' => null, 'future_from' => null],
    ]));

    expect($response->getTargetUrl())->toBe(route('admin.people.saudization'));

    $engineers->refresh();
    $accounting->refresh();

    expect($engineers->required_percent)->toBe(35.5)
        ->and($engineers->next_percent)->toBe(40.0)
        ->and($engineers->next_from->toDateString())->toBe('2027-03-01')
        ->and($accounting->required_percent)->toBe(50.0)
        ->and($accounting->next_from->format('Y-m'))->toBe('2027-10')
        ->and($accounting->future_percent)->toBeNull()
        ->and($accounting->future_from)->toBeNull()
        // Everything else is as it was.
        ->and(SaudizationGroup::where('job_category', 'Sales')->sole()->required_percent)->toBe(60.0);
});

it('adds a group and removes one', function () {
    saudizationCan('manage-saudization');
    $pharmacy = SaudizationGroup::where('job_category', 'Pharmacist')->sole();

    app(SaudizationController::class)->update(saudizationForm([], [
        'delete' => [$pharmacy->id],
        'new' => ['name_ar' => 'مهن العلاقات الحكومية', 'name_en' => 'Government relations', 'job_category' => 'Customs Clearance', 'required_percent' => '100'],
    ]));

    $added = SaudizationGroup::where('job_category', 'Customs Clearance')->sole();

    expect(SaudizationGroup::count())->toBe(17)
        ->and(SaudizationGroup::find($pharmacy->id))->toBeNull()
        ->and($added->name_ar)->toBe('مهن العلاقات الحكومية')
        ->and($added->required_percent)->toBe(100.0)
        // Last in the table.
        ->and($added->sort_order)->toBeGreaterThan(SaudizationGroup::where('id', '!=', $added->id)->max('sort_order'));
});

it('refuses one job category counted by two groups', function () {
    $sales = SaudizationGroup::where('job_category', 'Sales')->sole();

    expect(fn () => app(SaudizationController::class)->update(saudizationForm([$sales->id => ['job_category' => 'Marketing']])))
        ->toThrow(ValidationException::class, 'counted by one group only');

    expect($sales->fresh()->job_category)->toBe('Sales');
});

it('refuses a percentage that is not one, and a new group with no name', function () {
    $sales = SaudizationGroup::where('job_category', 'Sales')->sole();

    foreach (['120', '-5', 'sixty', ''] as $bad) {
        expect(fn () => app(SaudizationController::class)->update(saudizationForm([$sales->id => ['required_percent' => $bad]])))
            ->toThrow(ValidationException::class);
    }

    expect(fn () => app(SaudizationController::class)->update(saudizationForm([], ['new' => ['required_percent' => '50']])))
        ->toThrow(ValidationException::class, 'Give the new group a name');

    expect($sales->fresh()->required_percent)->toBe(60.0)
        ->and(SaudizationGroup::count())->toBe(17);
});

it('records a changed target in the audit trail', function () {
    saudizationCan('manage-saudization');
    $sales = SaudizationGroup::where('job_category', 'Sales')->sole();
    DB::table('activity_logs')->delete();

    app(SaudizationController::class)->update(saudizationForm([$sales->id => ['required_percent' => '65']]));

    $logs = DB::table('activity_logs')->where('model_type', SaudizationGroup::class)->get();

    // One row, for the one group that changed.
    expect($logs)->toHaveCount(1)
        ->and((int) $logs[0]->model_id)->toBe($sales->id)
        ->and($logs[0]->changes)->toContain('required_percent');
});

// ─── The assistant ────────────────────────────────────────────────

it('offers the assistant the workforce tools only to someone who can open the pages', function () {
    $nobody = new WorkforceToolbox(workforceUser('view-employees'));

    expect($nobody->enabled())->toBeFalse()
        ->and($nobody->definitions())->toBe([])
        ->and($nobody->promptNote())->toBe('')
        // A stale conversation calling the tool anyway is refused, not answered.
        ->and($nobody->call('get_saudization', []))->toHaveKey('error')
        ->and($nobody->call('get_workforce_breakdown', ['by' => 'nationality']))->toHaveKey('error');

    foreach (['view-attendance', 'view-vacations'] as $permission) {
        $holder = new WorkforceToolbox(workforceUser($permission));

        expect($holder->enabled())->toBeTrue()
            ->and(collect($holder->definitions())->pluck('function.name')->all())->toBe(WorkforceToolbox::TOOLS)
            ->and($holder->promptNote())->toContain('get_saudization');
    }
});

it('gives the assistant each group\'s standing, in the page\'s own figures', function () {
    staff('Engineers', saudis: 14, others: 38);
    staff('Accountant', saudis: 10, others: 9);

    $answer = (new WorkforceToolbox(workforceUser('view-attendance')))->call('get_saudization', []);
    $groups = collect($answer['groups'])->keyBy('job_category');

    expect($answer['company'])->toMatchArray(['employees_in_oracle_list' => 71, 'saudis' => 24, 'saudi_share_percent' => 33.8])
        ->and($answer['groups_not_compliant'])->toBe(1)
        ->and($groups['Engineers'])->toMatchArray([
            'group' => 'المهن الهندسية', 'employees' => 52, 'saudis' => 14, 'saudi_share_percent' => 26.9,
            'required_percent' => 30.0, 'status' => 'not compliant', 'saudis_required_at_this_size' => 16, 'more_saudis_needed' => 2,
        ])
        ->and($groups['Accountant']['status'])->toBe('compliant')
        ->and($groups['Accountant'])->not->toHaveKey('more_saudis_needed')
        ->and($groups['Accountant']['announced_for_later'][0])->toMatchArray(['percent' => 50.0, 'from' => 'October 2026', 'met_by_todays_staff' => true])
        ->and($groups['Sales']['status'])->toBe('nobody in this group')
        // It is told what kind of count this is.
        ->and($answer['note'])->toContain('head count')->toContain('Qiwa');
});

it('gives the assistant head counts by nationality, and never a name', function () {
    staff('Engineers', saudis: 3, others: 2);
    staff('Sales', saudis: 1, others: 0, more: ['branch_id' => 2]);
    $toolbox = new WorkforceToolbox(workforceUser('view-vacations'));

    $all = $toolbox->call('get_workforce_breakdown', ['by' => 'nationality']);

    expect($all['total_employees'])->toBe(6)
        ->and($all['groups'])->toBe([
            ['name' => 'Saudi', 'employees' => 4, 'share_percent' => 66.7],
            ['name' => 'Indian', 'employees' => 2, 'share_percent' => 33.3],
        ]);

    $riyadh = $toolbox->call('get_workforce_breakdown', ['by' => 'nationality', 'branch' => 'ryd']);

    expect($riyadh['branch'])->toBe('RYD')
        ->and($riyadh['total_employees'])->toBe(1);

    // No answer carries anybody's name, whatever is asked for.
    foreach ([$all, $riyadh, $toolbox->call('get_saudization', [])] as $answer) {
        expect(json_encode($answer))->not->toContain('Engineers Saudi 0')->not->toContain('Sales Saudi 0');
    }

    expect($toolbox->call('get_workforce_breakdown', ['by' => 'salary']))->toHaveKey('error')
        ->and($toolbox->call('get_workforce_breakdown', ['by' => 'nationality', 'branch' => 'Dammam']))->toHaveKey('error');
});
