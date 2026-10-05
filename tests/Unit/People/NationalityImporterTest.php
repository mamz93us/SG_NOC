<?php

use App\Models\Employee;
use App\Services\OraclePortal\PortalBook;
use App\Services\People\NationalityImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oracle's nationality export onto employee records.
 *
 * What is defended: a person number is matched inside the Oracle book's
 * branches only — the SSS Egypt series collides with it — a number two
 * records share is not guessed between, and the import can add and correct
 * but never erase.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    config(['cache.default' => 'array']);
    config(['oracle_portal.book.branches' => ['JED', 'RYD']]);

    foreach (['activity_logs', 'employees', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    // Every column the importer filters on has to be here: SQLite reads a
    // column it cannot find as a string, and the filter then matches nothing.
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email')->nullable();
        $t->string('oracle_emp_no')->nullable();
        $t->string('oracle_nationality', 100)->nullable();
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

    DB::table('branches')->insert([['id' => 1, 'name' => 'JED'], ['id' => 2, 'name' => 'RYD'], ['id' => 3, 'name' => 'CAI']]);
});

function nationalityPerson(string $number, array $more = []): Employee
{
    return Employee::create(array_merge(['name' => 'Person '.$number, 'oracle_emp_no' => $number, 'branch_id' => 1, 'status' => 'active'], $more));
}

/** @param  array<int, array{0: mixed, 1: mixed}>  $people */
function nationalitySheet(array $people): array
{
    return NationalityImporter::read(array_merge([['PERSON_NUMBER', 'SYSTEM_NATIONALITY']], $people));
}

function nationalityImport(array $people, bool $dryRun = false): array
{
    return (new NationalityImporter(new PortalBook))->import(nationalitySheet($people), $dryRun);
}

// ─── Reading the sheet ────────────────────────────────────────────

it('reads person numbers and nationalities from Oracle\'s export', function () {
    $sheet = nationalitySheet([['2574', 'Saudi'], [1003, ' Indian '], ['0044', 'Sri  Lankan'], [1005.0, 'Egyptian']]);

    expect($sheet['people'])->toBe(['2574' => 'Saudi', '1003' => 'Indian', '44' => 'Sri Lankan', '1005' => 'Egyptian'])
        ->and($sheet['sheet_rows'])->toBe(4);
});

it('finds the columns wherever they sit, under a title row', function () {
    $sheet = NationalityImporter::read([
        ['Nationalities as of October', null, null],
        ['Nationality', 'Name', 'Person Number'],
        ['Saudi', 'ignored', '2574'],
    ]);

    expect($sheet['people'])->toBe(['2574' => 'Saudi']);
});

it('refuses a sheet that is not the nationality export', function () {
    expect(fn () => NationalityImporter::read([['EMP NO', 'EMAIL ADDRESS'], ['2574', 'a@samirgroup.com']]))
        ->toThrow(RuntimeException::class, 'not the nationality export');
});

it('counts a row with no nationality, and skips a row with no number', function () {
    $sheet = nationalitySheet([['2574', ''], ['1003', '-'], ['1004', null], [null, 'Saudi'], ['1005', 'Saudi']]);

    expect($sheet['people'])->toBe(['1005' => 'Saudi'])
        ->and($sheet['blank'])->toBe(3)
        ->and($sheet['sheet_rows'])->toBe(4);
});

it('drops a number listed with two different nationalities rather than taking the last', function () {
    $sheet = nationalitySheet([['2574', 'Saudi'], ['2574', 'Yemeni'], ['1003', 'Indian'], ['1003', 'Indian']]);

    expect($sheet['people'])->toBe(['1003' => 'Indian'])
        ->and($sheet['conflicting'])->toBe(1);
});

// ─── Writing to employees ─────────────────────────────────────────

it('sets each matched employee\'s nationality', function () {
    $taghreed = nationalityPerson('2574');
    $padded = nationalityPerson('01003', ['branch_id' => 2]);
    $noBranch = nationalityPerson('1004', ['branch_id' => null]);

    $result = nationalityImport([['2574', 'Saudi'], ['1003', 'Indian'], ['1004', 'Jordanian'], ['9999', 'Syrian']]);

    expect($taghreed->refresh()->oracle_nationality)->toBe('Saudi')
        ->and($padded->refresh()->oracle_nationality)->toBe('Indian')
        ->and($noBranch->refresh()->oracle_nationality)->toBe('Jordanian')
        ->and($result['matched'])->toBe(3)
        ->and($result['set'])->toBe(3)
        ->and($result['not_in_noc'])->toBe(1)
        ->and($result['nationalities'])->toBe(['Saudi' => 1, 'Indian' => 1, 'Jordanian' => 1]);
});

it('leaves an SSS Egypt employee holding the same number alone', function () {
    // Number 512 in Oracle's Saudi book and the Cairo employee holding 512
    // are two different people.
    $cairo = nationalityPerson('512', ['branch_id' => 3]);
    $saudi = nationalityPerson('512', ['name' => 'Saudi 512']);

    $result = nationalityImport([['512', 'Filipino']]);

    expect($cairo->refresh()->oracle_nationality)->toBeNull()
        ->and($saudi->refresh()->oracle_nationality)->toBe('Filipino')
        ->and($result['ambiguous'])->toBe(0);
});

it('does not guess between two records in the book that share a number', function () {
    $one = nationalityPerson('1003');
    $two = nationalityPerson('1003', ['name' => 'Other 1003', 'branch_id' => 2]);

    $result = nationalityImport([['1003', 'Indian']]);

    expect($one->refresh()->oracle_nationality)->toBeNull()
        ->and($two->refresh()->oracle_nationality)->toBeNull()
        ->and($result['ambiguous'])->toBe(1)
        ->and($result['matched'])->toBe(0);
});

it('writes to the main record, not to the same person\'s second mailbox', function () {
    $main = nationalityPerson('2574');
    $second = nationalityPerson('2574', ['name' => 'Second mailbox', 'linked_primary_employee_id' => $main->id]);

    nationalityImport([['2574', 'Saudi']]);

    expect($main->refresh()->oracle_nationality)->toBe('Saudi')
        ->and($second->refresh()->oracle_nationality)->toBeNull();
});

it('corrects a nationality that changed and never erases one', function () {
    $changed = nationalityPerson('2574', ['oracle_nationality' => 'Yemeni']);
    $absent = nationalityPerson('1003', ['oracle_nationality' => 'Indian']);
    $blank = nationalityPerson('1004', ['oracle_nationality' => 'Syrian']);

    $result = nationalityImport([['2574', 'Saudi'], ['1004', '']]);

    expect($changed->refresh()->oracle_nationality)->toBe('Saudi')
        // Not in this export, and in it with nothing: both keep what they hold.
        ->and($absent->refresh()->oracle_nationality)->toBe('Indian')
        ->and($blank->refresh()->oracle_nationality)->toBe('Syrian')
        ->and($result['set'])->toBe(1);
});

it('writes nothing on a dry run, and says what it would have', function () {
    $employee = nationalityPerson('2574');
    // Creating the person is audited; the import is what is being counted.
    DB::table('activity_logs')->delete();

    $result = nationalityImport([['2574', 'Saudi']], dryRun: true);

    expect($result['set'])->toBe(1)
        ->and($employee->refresh()->oracle_nationality)->toBeNull()
        ->and(DB::table('activity_logs')->count())->toBe(0);
});

it('logs one row for the import, and nothing when a second run changes nothing', function () {
    nationalityPerson('2574');
    nationalityPerson('1003');
    DB::table('activity_logs')->delete();

    nationalityImport([['2574', 'Saudi'], ['1003', 'Indian']]);
    $again = nationalityImport([['2574', 'Saudi'], ['1003', 'Indian']]);

    $logs = DB::table('activity_logs')->where('action', 'employee_nationalities_imported')->get();

    expect($again['set'])->toBe(0)
        ->and($again['unchanged'])->toBe(2)
        ->and($logs)->toHaveCount(1)
        ->and(json_decode($logs[0]->changes, true)['set'])->toBe(2)
        // One row for the import: the per-person audit is switched off for it.
        ->and(DB::table('activity_logs')->count())->toBe(1);
});
