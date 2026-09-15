<?php

use App\Models\AiSetting;
use App\Models\Attendance\AttendanceOwner;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationEmployee;
use App\Services\Ai\AssistantAgent;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\AzureOpenAiClient;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Vacation\VacationImporter;
use App\Services\Vacation\VacationLinker;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's vacation tools. What is being defended here: an employee
 * reads their own leave as Oracle holds it, and the leave of exactly the people
 * whose attendance they may read — those whose HR record names them as manager
 * or supervisor, and everyone in the branches (or the company) HR put them on
 * the attendance owner list for. Nobody else's, however the turn is worded; and
 * every figure is Oracle's, never one worked out here.
 *
 * Everyone's remaining balance is a number nobody else has, so a leak shows up
 * in the JSON.
 *
 * 2026-09-14 is a Monday; the clock is frozen there.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-14 10:00:00');
    CarbonImmutable::setTestNow('2026-09-14 10:00:00');
    config(['cache.default' => 'array']);

    foreach (['vacation_absences', 'vacation_balances', 'vacation_employees', 'vacation_imports',
        'attendance_owners', 'employees', 'departments', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->string('city')->nullable();
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
        $t->string('job_title')->nullable();
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

    (require database_path('migrations/2026_09_13_100001_create_attendance_owners_table.php'))->up();
    (require database_path('migrations/2026_09_14_160001_create_vacation_tables.php'))->up();

    // Named by code with the city beside it, as on NOC2.
    DB::table('branches')->insert([['id' => 1, 'name' => 'JED', 'city' => 'Jeddah'], ['id' => 2, 'name' => 'RYD', 'city' => 'Riyadh']]);
    DB::table('departments')->insert([['id' => 1, 'name' => 'Finance'], ['id' => 2, 'name' => 'Sales']]);

    config([
        'vacations.books' => ['samirgroup' => ['label' => 'SamirGroup (Saudi Arabia)', 'branches' => ['JED', 'RYD'], 'weekend' => [5, 6]]],
        'vacations.default_book' => 'samirgroup',
        'vacations.stale_after_days' => 35,
    ]);
});

/** @param  array<string, mixed>  $attributes */
function leavePerson(string $name, ?string $oracleNo, array $attributes = []): Employee
{
    return Employee::create($attributes + [
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@samirgroup.com',
        'oracle_emp_no' => $oracleNo,
        'branch_id' => 1,
        'status' => 'active',
    ]);
}

function leaveToolbox(?Employee $employee): AssistantToolbox
{
    return new AssistantToolbox(
        new User(['name' => $employee?->name ?? 'Nobody', 'email' => $employee?->email ?? 'nobody@samirgroup.com']),
        $employee,
        null,
        app(KnowledgeRetriever::class),
        app(TicketRequestService::class),
    );
}

/** @param  list<array{0: string, 1: ?float, 2: ?float, 3: ?float, 4: ?float}>  $rows  Oracle no., CARRYOVER, ACCRUALS, ABSENCES (Oracle's sign), TOTAL_BALANCE */
function leaveBalances(string $asOf, array $rows): void
{
    app(VacationImporter::class)->importBalances('samirgroup', CarbonImmutable::parse($asOf), array_map(fn (array $row) => [
        'person_number' => $row[0], 'carryover' => $row[1], 'accrued' => $row[2], 'absences' => $row[3], 'balance' => $row[4],
    ], $rows));
}

/** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $rows  Oracle no., type, start, end */
function leaveRecords(array $rows): void
{
    app(VacationImporter::class)->importAbsences('samirgroup', array_map(fn (array $row) => [
        'person_number' => $row[0], 'type' => $row[1], 'start' => $row[2], 'end' => $row[3],
    ], $rows));
}

/** @param  list<int>|null  $branchIds  null for the whole company */
function leaveOwner(Employee $employee, ?array $branchIds = null): AttendanceOwner
{
    return AttendanceOwner::create([
        'employee_id' => $employee->id,
        'scope' => $branchIds === null ? AttendanceOwner::SCOPE_COMPANY : AttendanceOwner::SCOPE_BRANCHES,
        'branch_ids' => $branchIds,
    ]);
}

/**
 * Samir manages Ahmed and supervises Mona. Ahmed manages Omar, and Layla
 * supervises Ahmed. Karim reports to nobody here. Tarek reported to Samir and
 * has left. Everyone is in Jeddah but Rana, who is in Riyadh.
 *
 * Today Ahmed is on annual leave (13–17 Sep), Mona on sick leave, Karim on
 * annual leave (14–16 Sep) and Rana on a business trip; Omar's leave starts
 * next week. Mona and Rana are overdrawn, and Layla's balance is from July.
 *
 * @return array<string, Employee>
 */
function leaveOrg(): array
{
    $samir = leavePerson('Samir', '2000');
    $layla = leavePerson('Layla', '2006');
    $ahmed = leavePerson('Ahmed', '2001', ['manager_id' => $samir->id, 'supervisor_id' => $layla->id, 'job_title' => 'Accountant', 'department_id' => 1]);
    $mona = leavePerson('Mona', '2002', ['supervisor_id' => $samir->id, 'department_id' => 2]);
    $omar = leavePerson('Omar', '2003', ['manager_id' => $ahmed->id, 'department_id' => 1]);
    $karim = leavePerson('Karim', '2004', ['department_id' => 2]);
    $tarek = leavePerson('Tarek', '2005', ['manager_id' => $samir->id, 'status' => 'terminated', 'terminated_date' => '2026-09-01']);
    $rana = leavePerson('Rana', '2020', ['branch_id' => 2, 'department_id' => 2]);

    leaveBalances('2026-09-14', [
        ['2000', 5, 14.67, -3, 16.67],
        ['2001', 10, 14.67, -6, 30.67],     // 12 days beyond its own columns: an adjustment in Oracle
        ['2002', -3, 14.67, -20, -8.33],
        ['2003', 2, 9.25, -4.5, 6.75],
        ['2004', 1, 21.08, -2, 20.08],
        ['2005', 4, 12.33, 0, 16.33],
        ['2020', 3, 7.75, -20.25, -9.5],
    ]);
    leaveBalances('2026-07-01', [['2006', 0, 13.5, -2, 11.5]]);

    leaveRecords([
        ['2000', 'Annual Leave', '02-AUG-26', '03-AUG-26'],
        ['2001', 'Internal Business Trip', '01-SEP-26', '01-SEP-26'],
        ['2001', 'Annual Leave', '13-SEP-26', '17-SEP-26'],
        ['2001', 'Annual Leave', '11-OCT-26', '15-OCT-26'],
        ['2002', 'Sick Leave', '14-SEP-26', '14-SEP-26'],
        ['2003', 'Annual Leave', '20-SEP-26', '24-SEP-26'],
        ['2004', 'Annual Leave', '14-SEP-26', '16-SEP-26'],
        ['2005', 'Annual Leave', '14-SEP-26', '15-SEP-26'],
        ['2020', 'External Business Trip', '13-SEP-26', '15-SEP-26'],
    ]);

    return compact('samir', 'layla', 'ahmed', 'mona', 'omar', 'karim', 'tarek', 'rana');
}

it('offers no id, email or reporting line to point at', function () {
    $definitions = collect(leaveToolbox(null)->definitions());
    $parameters = fn (string $tool) => $definitions->firstWhere('function.name', $tool)['function']['parameters'];

    expect(array_keys((array) $parameters('get_my_vacation')['properties']))->toBe(['year'])
        ->and($parameters('get_my_vacation')['required'])->toBe([])
        ->and(array_keys((array) $parameters('get_team_member_vacation')['properties']))->toBe(['member', 'year', 'branch'])
        ->and($parameters('get_team_member_vacation')['required'])->toBe(['member'])
        ->and(array_keys((array) $parameters('get_team_vacation')['properties']))->toBe(['period', 'month', 'from', 'to', 'branch', 'department', 'only']);
});

it('gives the signed-in employee their own balance exactly as Oracle reported it', function () {
    $org = leaveOrg();

    $result = leaveToolbox($org['ahmed'])->call('get_my_vacation', []);

    expect($result['year'])->toBe(2026)
        ->and($result['balance'])->toBe([
            'as_of' => '2026-09-14',
            'carried_over_from_last_year' => 10.0,
            'earned_this_year_so_far' => 14.67,
            'used_this_year' => 6.0,
            'other_adjustments' => 12.0,
            'remaining' => 30.67,
        ])
        ->and($result)->not->toHaveKey('balance_note')
        ->and(array_column($result['records'], 'status'))->toBe(['taken', 'ongoing', 'upcoming'])
        ->and($result['records'][1])->toBe([
            'type' => 'Annual Leave', 'from' => '2026-09-13', 'from_weekday' => 'Sun', 'to' => '2026-09-17', 'to_weekday' => 'Thu',
            'days' => 5.0, 'calendar_days' => 5, 'status' => 'ongoing',
        ])
        ->and($result['records'][0]['business_trip'])->toBeTrue()
        // Leave first, business trips apart.
        ->and($result['records_by_type'])->toBe([
            ['type' => 'Annual Leave', 'records' => 2, 'days' => 10.0],
            ['type' => 'Internal Business Trip', 'records' => 1, 'days' => 1.0, 'business_trip' => true],
        ])
        ->and(implode(' ', $result['notes']))
        ->toContain("Oracle's own figure as of 14 Sep 2026")
        ->toContain('+12 days of other_adjustments')
        ->toContain('Friday and Saturday');
});

it('ignores any attempt to ask for someone else', function () {
    $org = leaveOrg();

    // Everything a model could invent to redirect the lookup.
    $result = leaveToolbox($org['ahmed'])->call('get_my_vacation', [
        'employee_id' => $org['karim']->id,
        'email' => $org['karim']->email,
        'member' => 'Karim',
        'oracle_emp_no' => '2004',
    ]);
    $json = json_encode($result);

    expect($result['balance']['remaining'])->toBe(30.67)
        ->and($json)->not->toContain('20.08')      // Karim's remaining
        ->and($json)->not->toContain('2026-09-16')  // the end of Karim's leave
        ->and($json)->not->toContain('Karim');
});

it('explains itself with no Oracle record, no HR record, no leave plan or no balance', function () {
    $org = leaveOrg();
    $newStarter = leavePerson('New Starter', null, ['manager_id' => $org['samir']->id]);
    $hana = leavePerson('Hana', '2030');
    leaveBalances('2026-09-14', [['2030', null, null, null, null]]);
    $salma = leavePerson('Salma', '2031');
    leaveRecords([['2031', 'Annual Leave', '07-SEP-26', '08-SEP-26']]);

    $unlinked = leaveToolbox($newStarter)->call('get_my_vacation', []);
    $noRecord = leaveToolbox(null)->call('get_my_vacation', []);
    $noPlan = leaveToolbox($hana)->call('get_my_vacation', []);
    $recordsOnly = leaveToolbox($salma)->call('get_my_vacation', []);
    $unlinkedReport = leaveToolbox($org['samir'])->call('get_team_member_vacation', ['member' => 'New Starter']);

    expect($unlinked['error'])->toContain('not linked')
        ->and($noRecord)->toHaveKey('error')
        ->and($noRecord)->not->toHaveKey('balance')
        ->and($noPlan)->not->toHaveKey('balance')
        ->and($noPlan['balance_note'])->toContain('no leave plan')
        ->and($recordsOnly)->not->toHaveKey('balance')
        ->and($recordsOnly['balance_note'])->toContain('no 2026 balance')
        ->and($recordsOnly['records'][0]['from'])->toBe('2026-09-07')
        ->and($unlinkedReport['error'])->toContain('New Starter')->toContain('not linked');
});

it('says a balance is out of date, and leaves out what Oracle no longer lists', function () {
    $org = leaveOrg();
    VacationAbsence::where('start_date', '2026-10-11')->update(['removed_at' => now()]);

    $layla = leaveToolbox($org['layla'])->call('get_my_vacation', []);
    $ahmed = leaveToolbox($org['ahmed'])->call('get_my_vacation', []);

    expect($layla['balance']['as_of'])->toBe('2026-07-01')
        ->and(implode(' ', $layla['notes']))->toContain('75 days old')
        ->and(array_column($ahmed['records'], 'from'))->toBe(['2026-09-01', '2026-09-13'])
        ->and($ahmed['records_by_type'][0])->toBe(['type' => 'Annual Leave', 'records' => 1, 'days' => 5.0])
        ->and($ahmed['no_longer_in_oracle'])->toContain('1 leave record');
});

it('reads another year, and refuses a year it cannot use', function () {
    $org = leaveOrg();
    $toolbox = leaveToolbox($org['ahmed']);

    $lastYear = $toolbox->call('get_my_vacation', ['year' => 2025]);
    $asText = $toolbox->call('get_my_vacation', ['year' => '2026']);

    expect($lastYear['year'])->toBe(2025)
        ->and($lastYear['balance_note'])->toContain('no 2025 balance')
        ->and($lastYear['other_years_with_a_balance'])->toBe([2026])
        ->and($lastYear['records'])->toBe([])
        ->and($asText['balance']['remaining'])->toBe(30.67);

    foreach (['last year', '26', '1999', 'twenty'] as $year) {
        expect($toolbox->call('get_my_vacation', ['year' => $year]))->toHaveKey('error')->not->toHaveKey('balance');
    }
});

it('shows a manager and a supervisor the leave of the people who report to them', function () {
    $org = leaveOrg();
    $toolbox = leaveToolbox($org['samir']);

    $managed = $toolbox->call('get_team_member_vacation', ['member' => 'Ahmed']);
    $supervised = $toolbox->call('get_team_member_vacation', ['member' => 'mona']);

    expect($managed['employee']['name'])->toBe('Ahmed')
        ->and($managed['employee']['job_title'])->toBe('Accountant')
        ->and($managed['employee']['reports_to_you_as'])->toBe('manager')
        ->and($managed['visible_because'])->toContain('manager')
        ->and($managed['balance']['remaining'])->toBe(30.67)
        ->and($managed['records'])->toHaveCount(3)
        ->and($supervised['employee']['reports_to_you_as'])->toBe('supervisor')
        ->and($supervised['balance']['remaining'])->toBe(-8.33);
});

it('does not reach a colleague, a skip-level report, a manager or someone who has left', function () {
    $org = leaveOrg();

    foreach ([
        ['samir', 'Karim', '20.08'],
        ['samir', 'karim@samirgroup.com', '20.08'],
        ['samir', '2004', '20.08'],
        ['samir', 'Omar', '6.75'],     // Ahmed's report, not Samir's
        ['samir', 'Tarek', '16.33'],   // has left
        ['ahmed', 'Samir', '16.67'],   // upwards
        ['mona', 'Samir', '16.67'],
    ] as [$asker, $member, $remaining]) {
        $result = leaveToolbox($org[$asker])->call('get_team_member_vacation', ['member' => $member, 'employee_id' => $org['karim']->id]);

        expect($result)->toHaveKey('error')
            ->and($result)->not->toHaveKey('balance')
            ->and(json_encode($result))->not->toContain($remaining);
    }

    $team = leaveToolbox($org['samir'])->call('get_team_vacation', ['period' => 'this_month']);
    $nobody = leaveToolbox($org['karim'])->call('get_team_vacation', []);

    expect(array_column($team['members'], 'name'))->toBe(['Ahmed', 'Mona'])
        ->and(json_encode($team))->not->toContain('20.08')->not->toContain('6.75')->not->toContain('16.33')->not->toContain('Tarek')
        ->and($nobody['error'])->toContain('Nobody reports to you')->toContain('Leave is only shown');
});

it('lets attendance owners read leave across their branches or the whole company', function () {
    $org = leaveOrg();
    $nadia = leavePerson('Nadia', null, ['job_title' => 'General Manager']);
    leaveOwner($nadia);
    $faisal = leavePerson('Faisal', null, ['branch_id' => 2]);
    leaveOwner($faisal, [2]);
    leaveOwner($org['tarek']);   // on the list, but has left

    $company = leaveToolbox($nadia)->call('get_team_member_vacation', ['member' => 'Karim']);
    $inBranch = leaveToolbox($faisal)->call('get_team_member_vacation', ['member' => 'Rana']);
    $elsewhere = leaveToolbox($faisal)->call('get_team_member_vacation', ['member' => 'Ahmed']);
    $gone = leaveToolbox($org['tarek'])->call('get_team_member_vacation', ['member' => 'Karim']);

    expect($company['balance']['remaining'])->toBe(20.08)
        ->and($company['employee'])->not->toHaveKey('reports_to_you_as')
        ->and($company['visible_because'])->toContain('whole company')
        ->and($inBranch['balance']['remaining'])->toBe(-9.5)
        ->and($inBranch['visible_because'])->toContain('their branch')
        ->and($elsewhere)->toHaveKey('error')
        ->and(json_encode($elsewhere))->not->toContain('30.67')
        ->and($gone)->toHaveKey('error')
        ->and(json_encode($gone))->not->toContain('20.08');
});

it('lists who is on leave today, business trips apart', function () {
    leaveOrg();
    $nadia = leavePerson('Nadia', null);
    leaveOwner($nadia);
    $toolbox = leaveToolbox($nadia);

    $onLeave = $toolbox->call('get_team_vacation', ['only' => 'on_leave']);
    $onTrip = $toolbox->call('get_team_vacation', ['period' => 'today', 'only' => 'on_business_trip']);
    $riyadh = $toolbox->call('get_team_vacation', ['branch' => 'riyadh']);

    expect($onLeave['period'])->toBe('Today')
        ->and($onLeave['people'])->toBe(7)
        ->and($onLeave['counts'])->toBe(['on_leave' => 3, 'on_business_trip' => 1, 'negative_balance' => 2, 'no_balance_in_oracle' => 0, 'not_linked_to_oracle' => 0])
        ->and(array_column($onLeave['members'], 'name'))->toBe(['Ahmed', 'Karim', 'Mona'])
        ->and($onLeave['members'][0]['balance'])->toBe(['as_of' => '2026-09-14', 'used_this_year' => 6.0, 'other_adjustments' => 12.0, 'remaining' => 30.67])
        ->and(array_column($onLeave['members'][0]['leave'], 'from'))->toBe(['2026-09-13'])
        ->and(implode(' ', $onLeave['notes']))->toContain('1 of these balances are more than 35 days old')
        ->and(json_encode($onLeave))->not->toContain('Tarek')
        ->and(array_column($onTrip['members'], 'name'))->toBe(['Rana'])
        ->and($onTrip['members'][0]['leave'][0]['business_trip'])->toBeTrue()
        ->and(array_column($riyadh['members'], 'name'))->toBe(['Rana']);
});

it('puts the most negative balance first, and names people with no Oracle record', function () {
    $org = leaveOrg();
    leavePerson('New Starter', null, ['manager_id' => $org['samir']->id]);
    $nadia = leavePerson('Nadia', null);
    leaveOwner($nadia);

    $negative = leaveToolbox($nadia)->call('get_team_vacation', ['only' => 'negative_balance']);

    expect(array_column($negative['members'], 'name'))->toBe(['Rana', 'Mona'])
        ->and($negative['listed'])->toContain('most negative first')
        ->and($negative['counts']['not_linked_to_oracle'])->toBe(1)
        ->and($negative['not_linked_to_oracle'])->toBe(['New Starter']);
});

it('looks ahead for booked leave, and refuses what it cannot list', function () {
    $org = leaveOrg();
    $toolbox = leaveToolbox($org['ahmed']);   // Ahmed manages Omar

    $nextWeek = $toolbox->call('get_team_vacation', ['period' => 'next_week', 'only' => 'on_leave']);
    $today = $toolbox->call('get_team_vacation', ['only' => 'on_leave']);

    expect([$nextWeek['from'], $nextWeek['to']])->toBe(['2026-09-20', '2026-09-26'])
        ->and(array_column($nextWeek['members'], 'name'))->toBe(['Omar'])
        ->and($nextWeek['members'][0]['leave'][0]['status'])->toBe('upcoming')
        ->and($today['members'])->toBe([])
        ->and($toolbox->call('get_team_vacation', ['period' => 'next_quarter'])['error'])->toContain('period must be one of')
        ->and($toolbox->call('get_team_vacation', ['from' => '2026-09-01', 'to' => '2026-12-31'])['error'])->toContain('at most 92 days')
        ->and($toolbox->call('get_team_vacation', ['only' => 'sleepy'])['error'])->toContain('only must be one of');
});

it('reads the primary record for someone signed in with a linked mailbox', function () {
    $org = leaveOrg();
    $ahmedMailbox = leavePerson('Ahmed SG', null, ['email' => 'ahmed@samirgroup.net', 'linked_primary_employee_id' => $org['ahmed']->id]);
    $samirMailbox = leavePerson('Samir SG', null, ['email' => 'samir@samirgroup.net', 'linked_primary_employee_id' => $org['samir']->id]);

    expect(leaveToolbox($ahmedMailbox)->call('get_my_vacation', [])['balance']['remaining'])->toBe(30.67)
        ->and(leaveToolbox($samirMailbox)->call('get_team_member_vacation', ['member' => 'Ahmed'])['balance']['remaining'])->toBe(30.67);
});

it('names a second Oracle balance instead of choosing one quietly', function () {
    $org = leaveOrg();
    leaveBalances('2026-09-10', [['9001', 1, 2, -1, 2]]);
    app(VacationLinker::class)->linkManually(VacationEmployee::where('oracle_emp_no', '9001')->sole(), $org['ahmed'], null);

    $result = leaveToolbox($org['ahmed'])->call('get_my_vacation', []);

    expect($result['balance']['remaining'])->toBe(30.67)
        ->and($result['other_balances'])->toBe([[
            'oracle_number' => '9001', 'as_of' => '2026-09-10', 'carried_over_from_last_year' => 1.0,
            'earned_this_year_so_far' => 2.0, 'used_this_year' => 1.0, 'remaining' => 2.0,
        ]])
        ->and(implode(' ', $result['notes']))->toContain('more than one balance');
});

it('tells the model the same people\'s leave is theirs to see, in every turn\'s system prompt', function () {
    $org = leaveOrg();

    $prompt = (new ReflectionMethod(AssistantAgent::class, 'systemPrompt'))
        ->invoke(new AssistantAgent(new AzureOpenAiClient), new AiSetting, leaveToolbox($org['samir']));

    expect($prompt)->toContain('Their access to vacation balances and leave records is exactly the same')
        ->toContain('get_team_member_vacation or get_team_vacation')
        ->toContain('Never calculate leave yourself')
        ->and(leaveToolbox($org['karim'])->attendanceAccessNote())->toContain('vacation balance or leave records');
});
