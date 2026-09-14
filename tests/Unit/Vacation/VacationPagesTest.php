<?php

use App\Http\Controllers\Admin\Vacation\VacationAbsenceController;
use App\Http\Controllers\Admin\Vacation\VacationBalanceController;
use App\Http\Controllers\Admin\Vacation\VacationImportController;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\RolePermission;
use App\Models\User;
use App\Models\Vacation\VacationEmployee;
use App\Models\Vacation\VacationImport;
use App\Services\Vacation\VacationImporter;
use App\Services\Vacation\VacationLinker;
use App\Services\Vacation\VacationSheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * Vacations ▸ Balances, Leave records and Import. What is defended: every page
 * and action sits behind its own permission, the pages show Oracle's figures
 * (and the gap its columns leave) as imported, an upload is recognised by its
 * columns rather than its box, and HR's links are logged.
 *
 * Controllers are called directly, under a one-line layout: the admin
 * middleware stack and navigation are not what is under test.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    $this->travelTo(CarbonImmutable::parse('2026-09-14 10:00'));

    foreach (['vacation_absences', 'vacation_balances', 'vacation_employees', 'vacation_imports',
        'activity_logs', 'departments', 'employees', 'branches', 'users'] as $table) {
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

    (require database_path('migrations/2026_09_14_160001_create_vacation_tables.php'))->up();

    DB::table('branches')->insert([['id' => 10, 'name' => 'JED'], ['id' => 20, 'name' => 'RYD'], ['id' => 60, 'name' => 'CAI']]);
    DB::table('departments')->insert(['id' => 1, 'name' => 'Finance']);
    DB::table('users')->insert(['id' => 7, 'name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);

    config([
        'vacations.books' => ['samirgroup' => ['label' => 'SamirGroup (Saudi Arabia)', 'branches' => ['JED', 'RYD'], 'weekend' => [5, 6]]],
        'vacations.default_book' => 'samirgroup',
    ]);

    $admin = new User(['name' => 'HR Admin', 'email' => 'hr.admin@samirgroup.com']);
    $admin->forceFill(['id' => 7]);
    $this->actingAs($admin);

    // The admin layout brings the whole navigation with it; the pages are what is under test.
    File::ensureDirectoryExists(vacationPagesViews().'/layouts');
    File::put(vacationPagesViews().'/layouts/admin.blade.php', "@yield('content')");
    app('view')->getFinder()->prependLocation(vacationPagesViews());
    view()->share('errors', new ViewErrorBag);
    Gate::before(fn () => true);
});

afterEach(function () {
    File::deleteDirectory(vacationPagesViews());
});

function vacationPagesViews(): string
{
    return storage_path('framework/testing/vacation-pages');
}

/** @param  array<string, mixed>  $data */
function vacationPagesRequest(string $method, array $data = [], array $files = []): Request
{
    $request = Request::create('/admin/vacations', $method, $data, [], $files);
    $request->setUserResolver(fn () => auth()->user());
    $request->setLaravelSession(app('session.store'));

    return $request;
}

/** Nadia is on leave and has an adjustment; 1675 is only held in Cairo; 2886 has no leave plan. */
function vacationPagesSeed(): Employee
{
    $nadia = Employee::create(['name' => 'Nadia Salem', 'email' => 'nadia@samirgroup.com', 'oracle_emp_no' => '916', 'branch_id' => 10, 'department_id' => 1]);
    Employee::create(['name' => 'Cairo Namesake', 'oracle_emp_no' => '1675', 'branch_id' => 60]);

    $importer = app(VacationImporter::class);
    $importer->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), [
        ['person_number' => '916', 'carryover' => 10, 'accrued' => 14.67, 'absences' => -6, 'balance' => 30.67],
        ['person_number' => '1675', 'carryover' => -3, 'accrued' => 14.67, 'absences' => -20, 'balance' => -8.33],
        ['person_number' => '2886', 'carryover' => null, 'accrued' => null, 'absences' => null, 'balance' => null],
    ]);
    $importer->importAbsences('samirgroup', [
        ['person_number' => '916', 'type' => 'Annual Leave', 'start' => '13-SEP-26', 'end' => '17-SEP-26'],
        ['person_number' => '916', 'type' => 'Internal Business Trip', 'start' => '01-SEP-26', 'end' => '01-SEP-26'],
        ['person_number' => '1675', 'type' => 'Sick Leave', 'start' => '14-SEP-26', 'end' => '14-SEP-26'],
        ['person_number' => '2886', 'type' => 'Annual Leave', 'start' => '02-AUG-26', 'end' => '03-AUG-26'],
    ]);

    return $nadia;
}

/** @param  list<list<mixed>>  $rows */
function vacationPagesUpload(string $name, array $rows): UploadedFile
{
    $book = new Spreadsheet;
    foreach ($rows as $r => $row) {
        foreach ($row as $c => $value) {
            $cell = $book->getActiveSheet()->getCell([$c + 1, $r + 1]);
            is_string($value) ? $cell->setValueExplicit($value, DataType::TYPE_STRING) : $cell->setValue($value);
        }
    }

    $path = vacationPagesViews().'/'.uniqid().'.xls';
    (new Xls($book))->save($path);

    return new UploadedFile($path, $name, null, null, true);
}

it('gates every page and every change behind its own permission', function () {
    foreach (['balances.index', 'balances.export', 'balances.show', 'absences.index', 'absences.export'] as $name) {
        expect(Route::getRoutes()->getByName("admin.vacations.{$name}")?->gatherMiddleware())->toContain('permission:view-vacations');
    }

    foreach (['imports.index', 'imports.store', 'people.link', 'people.no-employee', 'people.reset'] as $name) {
        expect(Route::getRoutes()->getByName("admin.vacations.{$name}")?->gatherMiddleware())->toContain('permission:manage-vacations');
    }

    expect(RolePermission::allSlugs())->toContain('view-vacations', 'manage-vacations')
        ->and(RolePermission::defaultPermissions()['hr'])->toContain('view-vacations', 'manage-vacations')
        ->and(RolePermission::defaultPermissions()['admin'])->toContain('view-vacations', 'manage-vacations')
        ->and(RolePermission::defaultPermissions()['viewer'])->not->toContain('view-vacations');
});

it('lists every balance as Oracle reported it, with the year added up', function () {
    vacationPagesSeed();

    $view = app(VacationBalanceController::class)->index(vacationPagesRequest('GET'));
    $totals = $view->getData()['totals'];
    $html = $view->render();

    expect([(int) $totals->people, (int) $totals->negative, (int) $totals->no_balance, (int) $totals->unlinked, (int) $totals->on_leave_today])
        ->toBe([2, 1, 1, 2, 2])
        ->and(round((float) $totals->remaining, 2))->toBe(22.34)
        ->and($html)
        ->toContain('Vacation balances')
        ->toContain('As of 14 Sep 2026')
        ->toContain('Nadia Salem')
        ->toContain('30.67')
        ->toContain('+12')
        ->toContain('-8.33')
        ->toContain('Oracle person 1675')
        ->toContain('No leave plan in Oracle yet')
        ->toContain(route('admin.vacations.balances.export'));

    $people = fn (string $show) => app(VacationBalanceController::class)
        ->index(vacationPagesRequest('GET', ['show' => $show]))
        ->getData()['people']
        ->getCollection()
        ->pluck('oracle_emp_no')
        ->all();

    expect($people('negative'))->toBe(['1675'])
        ->and($people('adjusted'))->toBe(['916'])
        ->and($people('no_balance'))->toBe(['2886'])
        ->and($people('unlinked'))->toBe(['1675', '2886'])
        ->and($people('all'))->toBe(['916', '1675', '2886']);
});

it('exports the roster as a CSV Excel reads', function () {
    vacationPagesSeed();

    ob_start();
    app(VacationBalanceController::class)->export(vacationPagesRequest('GET'))->sendContent();
    $csv = ob_get_clean();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->toContain('Last year balance (carried over)')
        ->toContain('916,,"Nadia Salem",JED,Finance,active,"Auto: Oracle no.",2026,10,14.67,6,12,30.67,2026-09-14');
});

it('shows one person\'s balance, their leave records and who they are', function () {
    vacationPagesSeed();

    $nadia = app(VacationBalanceController::class)->show(vacationPagesRequest('GET'), VacationEmployee::where('oracle_emp_no', '916')->sole())->render();
    $cairo = app(VacationBalanceController::class)->show(vacationPagesRequest('GET'), VacationEmployee::where('oracle_emp_no', '1675')->sole())->render();

    expect($nadia)
        ->toContain('Annual leave balance 2026')
        ->toContain('10 + 14.67 − 6')
        ->toContain('+ 12')
        ->toContain('= 30.67')
        ->toContain('includes +12 days')
        ->toContain('On it now')
        ->toContain('Internal Business Trip')
        ->toContain('Link to employee')
        ->toContain('vacation-employee-options')
        ->and($cairo)
        ->toContain('Not linked to an employee')
        ->toContain('outside this company')
        ->toContain('Cairo Namesake · CAI');
});

it('lists who is away in a date range, leave and business trips apart', function () {
    vacationPagesSeed();

    $page = fn (array $query = []) => app(VacationAbsenceController::class)->index(vacationPagesRequest('GET', $query));

    $month = $page();
    $count = fn (array $query) => $page($query)->getData()['records']->total();

    expect($month->getData()['records']->total())->toBe(3)
        ->and($month->getData()['onLeaveToday'])->toBe(2)
        ->and($month->getData()['onTripToday'])->toBe(0)
        ->and($count(['type' => 'leave']))->toBe(2)
        ->and($count(['type' => 'trips']))->toBe(1)
        ->and($count(['type' => 'Sick Leave']))->toBe(1)
        ->and($count(['from' => '2026-08-01']))->toBe(1)
        ->and($count(['from' => '2026-01-01', 'to' => '2026-12-31', 'q' => 'Nadia']))->toBe(2)
        ->and($month->render())->toContain('Leave records')->toContain('Sick Leave')->toContain('Nadia Salem');

    expect(fn () => $page(['from' => '2026-09-14', 'to' => '2026-09-01']))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('imports both sheets from one upload, whichever box each is in, and logs each', function () {
    Employee::create(['name' => 'Nadia Salem', 'oracle_emp_no' => '916', 'branch_id' => 10]);

    $balance = vacationPagesUpload('samir_vacation balance.xls', [
        ['PERSON_ID', 'PERSON_NUMBER', 'CARRYOVER', 'ACCRUALS', 'ABSENCES', 'TOTAL_BALANCE'],
        [300000067631605.0, '916', 10, 14.67, -6, 30.67],
    ]);
    $details = vacationPagesUpload('samir_vacation details.xls', [
        ['PERSON_NUMBER', 'ABSENCE_TYPE', 'VAC_START_DATE', 'VAC_END_DATE'],
        ['916', 'Annual Leave', '13-SEP-26', '17-SEP-26'],
    ]);

    // Swapped boxes: the columns say which sheet is which.
    $response = app(VacationImportController::class)->store(
        vacationPagesRequest('POST', ['book' => 'samirgroup', 'as_of' => '2026-09-14'], ['balances_file' => $details, 'absences_file' => $balance]),
        app(VacationSheetReader::class),
        app(VacationImporter::class),
    );

    expect($response->getTargetUrl())->toBe(route('admin.vacations.imports.index'))
        ->and(session('success'))->toContain('Balances: 1 rows read, 1 new balances')->toContain('Leave records: 1 rows read, 1 new leave records')
        ->and(VacationImport::orderBy('id')->pluck('kind')->all())->toBe(['balances', 'absences'])
        ->and(VacationImport::orderBy('id')->first()->filename)->toBe('samir_vacation balance.xls')
        ->and((int) VacationImport::orderBy('id')->first()->imported_by)->toBe(7)
        ->and(ActivityLog::where('action', 'vacation_import')->count())->toBe(2)
        ->and(VacationEmployee::sole()->oracle_person_id)->toBe('300000067631605');

    $html = app(VacationImportController::class)->index()->render();

    expect($html)->toContain('samir_vacation details.xls')->toContain('as of 14 Sep 2026')->toContain('Friday and Saturday');
});

it('refuses an upload with no sheet, the same sheet twice, or a sheet that is not Oracle\'s', function () {
    $store = fn (array $files) => app(VacationImportController::class)->store(
        vacationPagesRequest('POST', ['book' => 'samirgroup', 'as_of' => '2026-09-14'], $files),
        app(VacationSheetReader::class),
        app(VacationImporter::class),
    );
    $balance = fn () => vacationPagesUpload('balance.xls', [['PERSON_NUMBER', 'CARRYOVER', 'ACCRUALS', 'ABSENCES', 'TOTAL_BALANCE'], ['916', 1, 2, -1, 2]]);

    $store([]);
    expect(session('error'))->toContain('Choose the balance sheet');

    $store(['balances_file' => $balance(), 'absences_file' => $balance()]);
    expect(session('error'))->toContain('Both files are the balance sheet');

    $store(['balances_file' => vacationPagesUpload('staff.xls', [['EMP_NO', 'EMP_NAME'], ['916', 'Nadia']])]);
    expect(session('error'))->toContain('staff.xls')->toContain('not one of Oracle');

    expect(VacationImport::count())->toBe(0);

    // A balance cannot be as of tomorrow.
    expect(fn () => app(VacationImportController::class)->store(
        vacationPagesRequest('POST', ['book' => 'samirgroup', 'as_of' => '2026-09-15'], ['balances_file' => $balance()]),
        app(VacationSheetReader::class),
        app(VacationImporter::class),
    ))->toThrow(Illuminate\Validation\ValidationException::class);

    expect(VacationImport::count())->toBe(0);
});

it('links, marks and resets a person, and logs each decision', function () {
    vacationPagesSeed();
    $saudi = Employee::create(['name' => 'Riyadh 1675', 'oracle_emp_no' => null, 'branch_id' => 20]);
    $person = VacationEmployee::where('oracle_emp_no', '1675')->sole();
    $controller = app(VacationBalanceController::class);

    $controller->link(vacationPagesRequest('POST', ['employee' => "{$saudi->id} · Riyadh 1675 · RYD"]), $person, app(VacationLinker::class));
    expect($person->fresh()->employee_id)->toBe($saudi->id)
        ->and($person->fresh()->isManual())->toBeTrue()
        ->and(session('success'))->toContain('now linked to Riyadh 1675');

    $controller->noEmployee($person->fresh(), app(VacationLinker::class));
    expect($person->fresh()->isConfirmedNotEmployee())->toBeTrue();

    $controller->reset($person->fresh(), app(VacationLinker::class));
    expect($person->fresh()->match_method)->toBe(VacationEmployee::METHOD_NONE)
        ->and($person->fresh()->employee_id)->toBeNull();

    $controller->link(vacationPagesRequest('POST', ['employee' => 'nobody']), $person->fresh(), app(VacationLinker::class));
    expect(session('error'))->toContain('Pick the employee from the list');

    $logs = ActivityLog::where('model_type', VacationEmployee::class)->orderBy('id')->get();

    expect($logs->pluck('action')->all())->toBe(['vacation_person_linked', 'vacation_person_not_employee', 'vacation_person_reset'])
        ->and($logs[0]->changes['employee_id'])->toBe($saudi->id)
        ->and($logs[0]->changes['oracle_emp_no'])->toBe('1675')
        ->and((int) $logs[0]->user_id)->toBe(7);
});

it('finds a linked mailbox\'s vacation on its primary record', function () {
    $nadia = vacationPagesSeed();
    $mailbox = Employee::create(['name' => 'Nadia SG', 'oracle_emp_no' => '916', 'branch_id' => 10, 'linked_primary_employee_id' => $nadia->id]);

    expect(VacationEmployee::forEmployee($mailbox)->pluck('oracle_emp_no')->all())->toBe(['916'])
        ->and(VacationEmployee::forEmployee($nadia)->first()->balances->first()->balance)->toBe(30.67);
});
