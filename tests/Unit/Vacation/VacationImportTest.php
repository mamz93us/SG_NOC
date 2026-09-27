<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationBalance;
use App\Models\Vacation\VacationEmployee;
use App\Services\Vacation\VacationImporter;
use App\Services\Vacation\VacationLinker;
use App\Services\Vacation\VacationSheetReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * Oracle's vacation sheets into the NOC. What is defended: Oracle's figures are
 * kept as Oracle wrote them, an Oracle number never lands on someone in the
 * other book's branches, HR's links survive re-imports, and a later export
 * withdraws what Oracle stopped listing without destroying history.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);

    foreach (['vacation_absences', 'vacation_balances', 'vacation_employees', 'vacation_imports',
        'activity_logs', 'employees', 'branches', 'users'] as $table) {
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

    DB::table('branches')->insert([
        ['id' => 10, 'name' => 'JED'],
        ['id' => 20, 'name' => 'RYD'],
        ['id' => 60, 'name' => 'CAI'],
    ]);

    config([
        'vacations.books' => ['samirgroup' => ['label' => 'SamirGroup', 'branches' => ['JED', 'RYD'], 'weekend' => [5, 6]]],
        'vacations.default_book' => 'samirgroup',
    ]);
});

function vacationImporter(): VacationImporter
{
    return app(VacationImporter::class);
}

/** @param  array<string, mixed>  $attributes */
function vacationStaff(string $name, ?string $oracleNo, ?int $branchId, array $attributes = []): Employee
{
    return Employee::create($attributes + ['name' => $name, 'oracle_emp_no' => $oracleNo, 'branch_id' => $branchId, 'status' => 'active']);
}

/** @return array<string, mixed> a balance sheet row */
function vacationBalanceRow(string $number, ?float $carryover, ?float $accrued, ?float $absences, ?float $balance, ?float $personId = null): array
{
    return ['person_id' => $personId, 'person_number' => $number, 'carryover' => $carryover, 'accrued' => $accrued, 'absences' => $absences, 'balance' => $balance];
}

/** @return array<string, mixed> a details sheet row */
function vacationRecordRow(string $number, string $type, string $start, string $end): array
{
    return ['person_number' => $number, 'type' => $type, 'start' => $start, 'end' => $end];
}

function vacationPerson(string $number): VacationEmployee
{
    return VacationEmployee::where('oracle_emp_no', $number)->sole();
}

function vacationRecord(string $number, string $start): VacationAbsence
{
    return VacationAbsence::where('vacation_employee_id', vacationPerson($number)->id)->where('start_date', $start)->sole();
}

it('keeps Oracle\'s balance as Oracle wrote it, with used days positive and the gap its columns cannot explain', function () {
    vacationStaff('Nadia', '916', 10);

    $import = vacationImporter()->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), [
        vacationBalanceRow('916', 10, 14.67, -6, 30.67, 300000067631605.0),
        vacationBalanceRow('1666', 9, 14.67, -8, 15.66),
        // Enrolled in no leave plan yet: every figure empty.
        vacationBalanceRow('2886', null, null, null, null),
    ], filename: 'samir_vacation balance.xls', userId: 7);

    $nadia = VacationBalance::where('vacation_employee_id', vacationPerson('916')->id)->sole();
    $rounded = VacationBalance::where('vacation_employee_id', vacationPerson('1666')->id)->sole();
    $new = VacationBalance::where('vacation_employee_id', vacationPerson('2886')->id)->sole();

    expect($nadia->carryover)->toBe(10.0)
        ->and($nadia->accrued)->toBe(14.67)
        ->and($nadia->used)->toBe(6.0)
        ->and($nadia->balance)->toBe(30.67)
        ->and($nadia->otherAdjustments())->toBe(12.0)
        ->and($nadia->year)->toBe(2026)
        ->and($nadia->as_of->toDateString())->toBe('2026-09-14')
        ->and(vacationPerson('916')->oracle_person_id)->toBe('300000067631605')
        // 9 + 14.67 − 8 is 15.67; Oracle's 15.66 is its own rounding, not an adjustment.
        ->and($rounded->otherAdjustments())->toBe(0.0)
        ->and($new->hasBalance())->toBeFalse()
        ->and($new->otherAdjustments())->toBeNull()
        ->and($import->created)->toBe(3)
        ->and($import->unlinked)->toBe(2)
        ->and((int) $import->imported_by)->toBe(7)
        ->and(VacationBalance::days(-0.0))->toBe('0')
        ->and(VacationBalance::days(15.5))->toBe('15.5');
});

it('links a number to the one employee in the book\'s branches, and never to someone in another book', function () {
    $jeddah = vacationStaff('Jeddah 166', '166', 10);
    $cairo = vacationStaff('Cairo 166', '166', 60);
    $onlyCairo = vacationStaff('Cairo 1666', '1666', 60);
    $riyadh = vacationStaff('Riyadh 1674', '1674', 20);
    vacationStaff('Jeddah 1675', '1675', 10);
    vacationStaff('Riyadh 1675', '1675', 20);
    $noBranch = vacationStaff('No branch 1677', '1677', null);
    vacationStaff('Mailbox 1677', '1677', 10, ['linked_primary_employee_id' => $noBranch->id]);

    vacationImporter()->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), array_map(
        fn ($n) => vacationBalanceRow($n, 10, 14.67, -2, 22.67),
        ['166', '1666', '1674', '1675', '1677', '2500'],
    ));

    expect(vacationPerson('166')->employee_id)->toBe($jeddah->id)
        ->and(vacationPerson('166')->match_method)->toBe(VacationEmployee::METHOD_AUTO_BRANCH)
        ->and(vacationPerson('166')->candidate_ids)->toBe([$jeddah->id, $cairo->id])
        ->and(vacationPerson('1666')->employee_id)->toBeNull()
        ->and(vacationPerson('1666')->match_method)->toBe(VacationEmployee::METHOD_NONE)
        ->and(vacationPerson('1666')->candidate_ids)->toBe([$onlyCairo->id])
        ->and(vacationPerson('1674')->employee_id)->toBe($riyadh->id)
        ->and(vacationPerson('1674')->match_method)->toBe(VacationEmployee::METHOD_AUTO)
        ->and(vacationPerson('1675')->employee_id)->toBeNull()
        ->and(vacationPerson('1675')->match_method)->toBe(VacationEmployee::METHOD_AMBIGUOUS)
        ->and(vacationPerson('1677')->employee_id)->toBe($noBranch->id)
        ->and(vacationPerson('2500')->match_method)->toBe(VacationEmployee::METHOD_NONE)
        ->and(VacationEmployee::unlinkedCount())->toBe(3);
});

it('never overwrites HR\'s link on a later import, and follows the rule again once reset', function () {
    $auto = vacationStaff('Samir', '2487', 10);
    $chosen = vacationStaff('Samir A.', null, 20);
    $rows = [vacationBalanceRow('2487', 10, 14.67, -19, 5.67)];

    vacationImporter()->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), $rows);
    app(VacationLinker::class)->linkManually(vacationPerson('2487'), $chosen, 7);
    vacationImporter()->importBalances('samirgroup', CarbonImmutable::parse('2026-09-15'), $rows);

    expect(vacationPerson('2487')->employee_id)->toBe($chosen->id)
        ->and(vacationPerson('2487')->isManual())->toBeTrue();

    app(VacationLinker::class)->resetToAuto(vacationPerson('2487'));

    expect(vacationPerson('2487')->employee_id)->toBe($auto->id)
        ->and(vacationPerson('2487')->match_method)->toBe(VacationEmployee::METHOD_AUTO)
        ->and(vacationPerson('2487')->confirmed_by)->toBeNull();
});

it('updates a balance from a newer export and never from an older one', function () {
    $importer = vacationImporter();

    $first = $importer->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), [vacationBalanceRow('916', 10, 14.67, -6, 18.67)]);
    $same = $importer->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), [vacationBalanceRow('916', 10, 14.67, -6, 18.67)]);
    $older = $importer->importBalances('samirgroup', CarbonImmutable::parse('2026-08-31'), [vacationBalanceRow('916', 10, 12.83, -4, 18.83)]);
    $newer = $importer->importBalances('samirgroup', CarbonImmutable::parse('2026-10-01'), [vacationBalanceRow('916', 10, 16.5, -6, 20.5)]);

    $balance = VacationBalance::sole();

    expect([$first->created, $same->unchanged, $older->skipped, $newer->updated])->toBe([1, 1, 1, 1])
        ->and($older->notes[0])->toContain('later date')
        ->and($balance->accrued)->toBe(16.5)
        ->and($balance->as_of->toDateString())->toBe('2026-10-01')
        ->and($balance->vacation_import_id)->toBe($newer->id);
});

it('counts work days without the book\'s weekend, and a repeated row once', function () {
    vacationStaff('Omar', '1976', 10);

    $import = vacationImporter()->importAbsences('samirgroup', [
        vacationRecordRow('1976', 'Annual Leave', '14-SEP-26', '22-SEP-26'),
        vacationRecordRow('1976', 'Annual Leave', '14-SEP-26', '22-SEP-26'),
        vacationRecordRow('1976', 'Internal Business Trip', '17-AUG-26', '17-AUG-26'),
    ]);

    $leave = vacationRecord('1976', '2026-09-14');

    expect($leave->calendar_days)->toBe(9)
        ->and($leave->work_days)->toBe(7)
        ->and($leave->days())->toBe(7.0)
        ->and($leave->isBusinessTrip())->toBeFalse()
        ->and(vacationRecord('1976', '2026-08-17')->isBusinessTrip())->toBeTrue()
        ->and($leave->status(CarbonImmutable::parse('2026-09-13')))->toBe(VacationAbsence::STATUS_UPCOMING)
        ->and($leave->status(CarbonImmutable::parse('2026-09-16')))->toBe(VacationAbsence::STATUS_ONGOING)
        ->and($leave->status(CarbonImmutable::parse('2026-09-23')))->toBe(VacationAbsence::STATUS_TAKEN)
        ->and([$import->rows, $import->created, $import->skipped])->toBe([3, 2, 1])
        ->and($import->notes[0])->toContain('repeated')
        ->and($import->window_from->toDateString())->toBe('2026-08-17')
        ->and($import->window_to->toDateString())->toBe('2026-09-14');
});

it('withdraws what a later export stopped listing, keeps older history, and restores a record listed again', function () {
    $importer = vacationImporter();
    $a = vacationRecordRow('1976', 'Annual Leave', '20-MAY-26', '21-MAY-26');
    $b = vacationRecordRow('1976', 'Annual Leave', '01-JUL-26', '02-JUL-26');
    $c = vacationRecordRow('1982', 'Internal Business Trip', '10-AUG-26', '10-AUG-26');
    $d = vacationRecordRow('1982', 'Annual Leave', '01-SEP-26', '03-SEP-26');
    $e = vacationRecordRow('1982', 'Sick Leave', '20-AUG-26', '20-AUG-26');

    $importer->importAbsences('samirgroup', [$a, $b, $c, $d]);
    // Its cutoff has moved past May, and the trip in August is gone.
    $later = $importer->importAbsences('samirgroup', [$b, $d, $e]);

    expect([$later->created, $later->unchanged, $later->removed, $later->restored])->toBe([1, 2, 1, 0])
        ->and(vacationRecord('1982', '2026-08-10')->removed_at)->not->toBeNull()
        ->and(vacationRecord('1982', '2026-08-10')->status())->toBe(VacationAbsence::STATUS_WITHDRAWN)
        ->and(vacationRecord('1976', '2026-05-20')->removed_at)->toBeNull()
        ->and(VacationAbsence::active()->count())->toBe(4);

    $again = $importer->importAbsences('samirgroup', [$b, $c, $d, $e]);

    expect([$again->restored, $again->removed, $again->unchanged])->toBe([1, 0, 3])
        ->and(vacationRecord('1982', '2026-08-10')->removed_at)->toBeNull()
        ->and(VacationAbsence::count())->toBe(5);

    // A feed that sends only some records withdraws nothing.
    $partial = $importer->importAbsences('samirgroup', [$d], withdrawMissing: false);

    expect($partial->removed)->toBe(0)
        ->and(VacationAbsence::active()->count())->toBe(5);
});

it('keeps the records of people Oracle leaves out of its leave data', function () {
    // Oracle filters 1655, 1656 and 2682 out of both vacation endpoints at the
    // database level while still listing them as employees, so nothing Oracle
    // sends — a sheet or the API — ever mentions them. Without protecting
    // them, every import covering a span they have leave in reads all of it as
    // withdrawn, and their history empties one import at a time.
    config(['vacations.books.samirgroup.blocked' => ['1655']]);

    $importer = vacationImporter();
    $blocked = vacationRecordRow('1655', 'Annual Leave', '10-JUL-26', '12-JUL-26');
    $ordinary = vacationRecordRow('1976', 'Annual Leave', '01-JUL-26', '02-JUL-26');
    $other = vacationRecordRow('1982', 'Sick Leave', '20-AUG-26', '20-AUG-26');

    $importer->importAbsences('samirgroup', [$blocked, $ordinary, $other]);

    // The next export is Oracle's whole list again, and — as always — it does
    // not mention 1655. Their record sits inside the window and is the only
    // thing missing, so without the protection it would be withdrawn here.
    $later = $importer->importAbsences('samirgroup', [$ordinary, $other]);

    expect($later->removed)->toBe(0)
        ->and(vacationRecord('1655', '2026-07-10')->removed_at)->toBeNull()
        ->and(vacationRecord('1655', '2026-07-10')->status())->not->toBe(VacationAbsence::STATUS_WITHDRAWN)
        ->and(collect($later->notes ?? [])->contains(fn ($n) => str_contains($n, '1655')))->toBeTrue();

    // Now leave that really was cancelled disappears. The anchor keeps the
    // window over both July records, so the ordinary one is genuinely inside
    // the span and missing — and it is withdrawn while 1655's is not.
    $anchor = vacationRecordRow('1982', 'Annual Leave', '01-JUL-26', '01-JUL-26');
    $third = $importer->importAbsences('samirgroup', [$other, $anchor]);

    expect($third->removed)->toBe(1)
        ->and(vacationRecord('1976', '2026-07-01')->removed_at)->not->toBeNull()
        ->and(vacationRecord('1655', '2026-07-10')->removed_at)->toBeNull();
});

it('withdraws normally when the book blocks nobody', function () {
    config(['vacations.books.samirgroup.blocked' => []]);

    $importer = vacationImporter();
    $a = vacationRecordRow('1976', 'Annual Leave', '01-JUL-26', '02-JUL-26');
    $b = vacationRecordRow('1982', 'Sick Leave', '20-AUG-26', '20-AUG-26');

    $importer->importAbsences('samirgroup', [$a, $b]);
    $later = $importer->importAbsences('samirgroup', [$b, vacationRecordRow('1982', 'Annual Leave', '01-JUL-26', '01-JUL-26')]);

    expect($later->removed)->toBe(1)
        ->and(vacationRecord('1976', '2026-07-01')->removed_at)->not->toBeNull();
});

it('names the rows it cannot read and imports the rest', function () {
    $import = vacationImporter()->importAbsences('samirgroup', [
        ['row' => 2] + vacationRecordRow('1976', 'Annual Leave', 'soon', '22-SEP-26'),
        ['row' => 3] + vacationRecordRow('1976', 'Annual Leave', '22-SEP-26', '14-SEP-26'),
        ['row' => 4] + vacationRecordRow('', 'Annual Leave', '14-SEP-26', '14-SEP-26'),
        ['row' => 5] + vacationRecordRow('1976', 'Sick Leave', '2026-09-01', '31/12/2026'),
        ['row' => 6] + vacationRecordRow('1976', 'Annual Leave', ExcelDate::PHPToExcel(new DateTime('2026-08-17')), '17-AUG-26'),
    ]);

    expect([$import->rows, $import->created, $import->skipped])->toBe([5, 2, 3])
        ->and($import->notes)->toBe([
            "Row 2: VAC_START_DATE 'soon' is not a date",
            'Row 3: it ends on 2026-09-14, before it starts on 2026-09-22',
            'Row 4: PERSON_NUMBER is empty',
        ])
        ->and(vacationRecord('1976', '2026-09-01')->end_date->toDateString())->toBe('2026-12-31')
        ->and(vacationRecord('1976', '2026-08-17')->calendar_days)->toBe(1);
});

it('refuses a person twice with different figures, and writes nothing to the audit log', function () {
    vacationStaff('Nadia', '916', 10);
    // Creating the employee is audited like any employee; the import must add nothing.
    $logged = ActivityLog::count();

    $import = vacationImporter()->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), [
        vacationBalanceRow('916', 10, 14.67, -6, 18.67),
        vacationBalanceRow('916', 10, 14.67, -7, 17.67),
        vacationBalanceRow('917', 1, 14.67, 0, 15.67),
        vacationBalanceRow('917', 1, 14.67, 0, 15.67),
    ]);

    expect($import->created)->toBe(1)
        ->and($import->skipped)->toBe(3)
        ->and($import->notes)->toContain('Person 916 appears more than once with different figures, so none of them were imported.')
        ->and(VacationBalance::count())->toBe(1)
        ->and(ActivityLog::count())->toBe($logged);
});

it('reads Oracle\'s sheets as Oracle writes them', function () {
    $dir = storage_path('framework/testing/vacation-sheets-'.uniqid());
    File::ensureDirectoryExists($dir);

    $write = function (string $name, array $rows) use ($dir): string {
        $book = new Spreadsheet;
        foreach ($rows as $r => $row) {
            foreach (array_values($row) as $c => $value) {
                $cell = $book->getActiveSheet()->getCell([$c + 1, $r + 1]);
                is_string($value) ? $cell->setValueExplicit($value, DataType::TYPE_STRING) : $cell->setValue($value);
            }
        }
        (new Xls($book))->save($path = "{$dir}/{$name}");

        return $path;
    };

    try {
        $balances = (new VacationSheetReader)->read($write('balance.xls', [
            ['PERSON_ID', 'PERSON_NUMBER', 'CARRYOVER', 'ACCRUALS', 'ABSENCES', 'TOTAL_BALANCE'],
            [100000000367156.0, '166', 10, 14.67, -2, 22.67],
            [null, null, null, null, null, null],
            [300000289558981.0, '2487', -5.18, 14.67, null, 9.49],
        ]), 'xls');

        $records = (new VacationSheetReader)->read($write('details.xls', [
            ['Vacation details export'],
            ['PERSON_NUMBER', 'ABSENCE_TYPE', 'VAC_START_DATE', 'VAC_END_DATE'],
            ['1976', 'Annual Leave', '14-SEP-26', '22-SEP-26'],
        ]), 'xls');

        expect($balances['kind'])->toBe('balances')
            ->and($balances['rows'])->toHaveCount(2)
            ->and($balances['rows'][0])->toMatchArray(['row' => 2, 'person_number' => '166', 'accrued' => 14.67])
            ->and($balances['rows'][1]['row'])->toBe(4)
            ->and($records['kind'])->toBe('absences')
            ->and($records['rows'][0])->toBe(['row' => 3, 'person_number' => '1976', 'type' => 'Annual Leave', 'start' => '14-SEP-26', 'end' => '22-SEP-26']);

        $import = vacationImporter()->importBalances('samirgroup', CarbonImmutable::parse('2026-09-14'), $balances['rows']);

        expect($import->created)->toBe(2)
            ->and(vacationPerson('166')->oracle_person_id)->toBe('100000000367156')
            ->and(VacationBalance::where('vacation_employee_id', vacationPerson('2487')->id)->sole()->used)->toBeNull();

        expect(fn () => (new VacationSheetReader)->read($write('other.xls', [['EMP_NO', 'EMP_NAME'], ['1', 'Someone']]), 'xls'))
            ->toThrow(RuntimeException::class, 'not one of Oracle');
    } finally {
        File::deleteDirectory($dir);
    }
});
