<?php

use App\Models\Attendance\AttendanceOwner;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Ticketing\TicketRequestService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The assistant's team attendance tools. What is being defended here: someone
 * reads the attendance of the people whose HR record names them as manager or
 * supervisor and — when HR has put them on the attendance owner list — of
 * everyone in their branches or the whole company. Nobody else's, however the
 * turn is worded.
 *
 * Who may be seen is read on the server for the signed-in employee; the model
 * only ever picks a name from inside that list. Everyone punches at times no
 * one else does, so a leak shows up as a time in the JSON.
 *
 * 2026-09-10 is a Thursday; the clock is frozen there.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    CarbonImmutable::setTestNow('2026-09-10 18:00:00');
    config(['cache.default' => 'array']);

    foreach (['attendance_owners', 'attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'employees', 'departments', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

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

    // AttendanceDayProcessor reads attendance_periods through PeriodLocks on
    // every build; see AssistantAttendanceToolTest.
    foreach (array_merge(
        glob(database_path('migrations/2026_09_10_*.php')),
        glob(database_path('migrations/2026_09_11_*.php')),
    ) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }
    (require database_path('migrations/2026_09_13_100001_create_attendance_owners_table.php'))->up();

    DB::table('branches')->insert([['id' => 1, 'name' => 'Jeddah'], ['id' => 2, 'name' => 'Riyadh']]);
    DB::table('departments')->insert([['id' => 1, 'name' => 'Finance'], ['id' => 2, 'name' => 'Sales']]);

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
});

/** @param  array<string, mixed>  $attributes */
function teamPerson(string $name, array $attributes = [], ?string $code = null): Employee
{
    $employee = Employee::create($attributes + [
        'name' => $name,
        'email' => strtolower(str_replace(' ', '.', $name)).'@samirgroup.com',
        'oracle_emp_no' => $code,
        'branch_id' => 1,
        'hired_date' => '2020-01-01',
        'status' => 'active',
    ]);

    if ($code !== null) {
        $source = BiotimeSource::firstOrCreate(['name' => 'BioTime KSA'], [
            'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
            'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
        ]);

        BiotimeEmployee::create([
            'biotime_source_id' => $source->id, 'emp_code' => $code, 'employee_id' => $employee->id,
        ]);
    }

    return $employee;
}

function teamPunch(Employee $employee, string $in, string $out, string $date = '2026-09-10'): void
{
    foreach ([$in, $out] as $time) {
        AttendancePunch::create([
            'biotime_source_id' => BiotimeSource::first()->id,
            'biotime_id' => (int) AttendancePunch::max('biotime_id') + 1,
            'biotime_employee_id' => BiotimeEmployee::where('employee_id', $employee->id)->value('id'),
            'employee_id' => $employee->id,
            'emp_code' => $employee->oracle_emp_no,
            'punch_time' => "{$date} {$time}:00",
            'punch_state' => '0',
            'synced_at' => now(),
        ]);
    }
}

function teamToolbox(?Employee $employee): AssistantToolbox
{
    return new AssistantToolbox(
        new User(['name' => $employee?->name ?? 'Nobody', 'email' => $employee?->email ?? 'nobody@samirgroup.com']),
        $employee,
        null,
        app(KnowledgeRetriever::class),
        app(TicketRequestService::class),
    );
}

function rebuildTeamDays(): void
{
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');
}

/** @param  list<int>|null  $branchIds  null for the whole company */
function teamOwner(Employee $employee, ?array $branchIds = null): AttendanceOwner
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
 * has left. Everyone is in Jeddah; Ahmed and Omar are in Finance, Mona and
 * Karim in Sales.
 *
 * @return array<string, Employee>
 */
function teamOrg(): array
{
    $samir = teamPerson('Samir', [], '2000');
    $layla = teamPerson('Layla', [], '2006');
    $ahmed = teamPerson('Ahmed', ['manager_id' => $samir->id, 'supervisor_id' => $layla->id, 'job_title' => 'Accountant', 'department_id' => 1], '2001');
    $mona = teamPerson('Mona', ['supervisor_id' => $samir->id, 'department_id' => 2], '2002');
    $omar = teamPerson('Omar', ['manager_id' => $ahmed->id, 'department_id' => 1], '2003');
    $karim = teamPerson('Karim', ['department_id' => 2], '2004');
    $tarek = teamPerson('Tarek', ['manager_id' => $samir->id, 'status' => 'terminated', 'terminated_date' => '2026-09-01'], '2005');

    teamPunch($samir, '09:02', '17:10');
    teamPunch($layla, '08:31', '16:45');
    teamPunch($ahmed, '08:55', '17:30');
    teamPunch($mona, '07:11', '16:02');
    teamPunch($omar, '10:20', '18:00');
    teamPunch($karim, '09:44', '17:03');
    teamPunch($tarek, '06:48', '15:37');

    rebuildTeamDays();

    return compact('samir', 'layla', 'ahmed', 'mona', 'omar', 'karim', 'tarek');
}

/** Rana works in Riyadh, in Sales, and was late today. */
function teamRiyadhPerson(): Employee
{
    $rana = teamPerson('Rana', ['branch_id' => 2, 'department_id' => 2], '2020');
    teamPunch($rana, '09:31', '17:04');
    rebuildTeamDays();

    return $rana;
}

it('offers no id, email or reporting line to point at', function () {
    $definitions = collect(teamToolbox(null)->definitions());

    $team = $definitions->firstWhere('function.name', 'get_team_attendance');
    $member = $definitions->firstWhere('function.name', 'get_team_member_attendance');

    expect(array_keys((array) $team['function']['parameters']['properties']))->toBe(['period', 'month', 'branch', 'department', 'only'])
        ->and(array_keys((array) $member['function']['parameters']['properties']))->toBe(['member', 'period', 'month', 'branch'])
        ->and($member['function']['parameters']['required'])->toBe(['member']);
});

it('shows a manager the day of someone they manage', function () {
    $org = teamOrg();

    $result = teamToolbox($org['samir'])->call('get_team_member_attendance', ['member' => 'Ahmed', 'period' => 'today']);

    expect($result['employee']['name'])->toBe('Ahmed')
        ->and($result['employee']['job_title'])->toBe('Accountant')
        ->and($result['employee']['reports_to_you_as'])->toBe('manager')
        ->and($result['visible_because'])->toContain('manager')
        ->and($result['days'])->toHaveCount(1)
        ->and($result['days'][0]['check_in'])->toBe('08:55')
        ->and($result['days'][0]['check_out'])->toBe('17:30');
});

it('shows a supervisor the day of someone they supervise', function () {
    $org = teamOrg();

    $result = teamToolbox($org['samir'])->call('get_team_member_attendance', ['member' => 'mona', 'period' => 'today']);

    expect($result['employee']['reports_to_you_as'])->toBe('supervisor')
        ->and($result['days'][0]['check_in'])->toBe('07:11');
});

it('does not reach a colleague who does not report to them', function () {
    $org = teamOrg();
    $toolbox = teamToolbox($org['samir']);

    foreach (['Karim', 'karim@samirgroup.com', '2004'] as $member) {
        $result = $toolbox->call('get_team_member_attendance', ['member' => $member, 'period' => 'today']);

        expect($result)->toHaveKey('error')
            ->and($result)->not->toHaveKey('days')
            ->and(json_encode($result))->not->toContain('09:44');
    }
});

it('stops at direct reports', function () {
    $org = teamOrg();

    $skipLevel = teamToolbox($org['samir'])->call('get_team_member_attendance', ['member' => 'Omar', 'period' => 'today']);
    $direct = teamToolbox($org['ahmed'])->call('get_team_member_attendance', ['member' => 'Omar', 'period' => 'today']);

    expect($skipLevel)->toHaveKey('error')
        ->and(json_encode($skipLevel))->not->toContain('10:20')
        ->and($direct['days'][0]['check_in'])->toBe('10:20');
});

it('never reads upwards', function () {
    $org = teamOrg();

    foreach (['ahmed', 'mona'] as $report) {
        $result = teamToolbox($org[$report])->call('get_team_member_attendance', ['member' => 'Samir', 'period' => 'today']);

        expect($result)->toHaveKey('error')
            ->and(json_encode($result))->not->toContain('09:02');
    }
});

it('leaves out people who have left', function () {
    $org = teamOrg();
    $toolbox = teamToolbox($org['samir']);

    $member = $toolbox->call('get_team_member_attendance', ['member' => 'Tarek', 'period' => 'today']);
    $team = $toolbox->call('get_team_attendance', ['period' => 'today']);

    expect($member)->toHaveKey('error')
        ->and(json_encode($member))->not->toContain('06:48')
        ->and(json_encode($team))->not->toContain('Tarek')
        ->and(json_encode($team))->not->toContain('06:48');
});

it('ignores anything a turn invents to point elsewhere', function () {
    $org = teamOrg();
    $toolbox = teamToolbox($org['samir']);

    $redirected = $toolbox->call('get_team_member_attendance', [
        'member' => 'Ahmed',
        'period' => 'today',
        'employee_id' => $org['karim']->id,
        'email' => $org['karim']->email,
        'manager_id' => $org['samir']->id,
    ]);

    $claimed = $toolbox->call('get_team_member_attendance', [
        'member' => 'Karim',
        'period' => 'today',
        'manager' => 'Samir',
        'reports_to' => $org['samir']->id,
        'as' => 'HR',
        'scope' => 'company',
    ]);

    expect($redirected['days'][0]['check_in'])->toBe('08:55')
        ->and(json_encode($redirected))->not->toContain('09:44')
        ->and(json_encode($redirected))->not->toContain('Karim')
        ->and($claimed)->toHaveKey('error')
        ->and(json_encode($claimed))->not->toContain('09:44');
});

it('lists the whole team for one day', function () {
    $org = teamOrg();

    $result = teamToolbox($org['samir'])->call('get_team_attendance', ['period' => 'today']);
    $json = json_encode($result);

    expect($result['people'])->toBe(2)
        ->and($result['counts_are'])->toBe('people')
        ->and($result['counts']['present'])->toBe(2)
        ->and(array_column($result['members'], 'name'))->toBe(['Ahmed', 'Mona'])
        ->and($result['members'][0]['day']['check_in'])->toBe('08:55')
        ->and($result['members'][1]['day']['check_out'])->toBe('16:02')
        ->and($json)->not->toContain('Karim')
        ->and($json)->not->toContain('Omar')
        ->and($json)->not->toContain('09:44')
        ->and($json)->not->toContain('10:20');
});

it('adds a longer period up per person', function () {
    $org = teamOrg();

    $result = teamToolbox($org['samir'])->call('get_team_attendance', ['period' => 'this_month']);

    expect($result['members'][0])->not->toHaveKey('day')
        ->and($result['members'][0]['summary']['present'])->toBe(1)
        ->and($result['members'][1]['summary']['present'])->toBe(1);
});

it('asks which one when two people on the team match', function () {
    $org = teamOrg();
    teamPerson('Mohamed Ali', ['manager_id' => $org['samir']->id], '2010');
    teamPerson('Mohamed Hassan', ['manager_id' => $org['samir']->id], '2011');
    rebuildTeamDays();

    $toolbox = teamToolbox($org['samir']);
    $ambiguous = $toolbox->call('get_team_member_attendance', ['member' => 'Mohamed', 'period' => 'today']);
    $byEmail = $toolbox->call('get_team_member_attendance', ['member' => 'mohamed.hassan@samirgroup.com', 'period' => 'today']);
    $byNumber = $toolbox->call('get_team_member_attendance', ['member' => '2010', 'period' => 'today']);

    expect($ambiguous)->toHaveKey('error')
        ->and($ambiguous)->not->toHaveKey('days')
        ->and(array_column($ambiguous['candidates'], 'name'))->toBe(['Mohamed Ali', 'Mohamed Hassan'])
        ->and($byEmail['employee']['name'])->toBe('Mohamed Hassan')
        ->and($byNumber['employee']['name'])->toBe('Mohamed Ali');
});

it('finds the team of a manager signed in with a linked mailbox', function () {
    $org = teamOrg();
    $secondary = teamPerson('Samir SG', ['email' => 'samir@samirgroup.net', 'linked_primary_employee_id' => $org['samir']->id]);

    $result = teamToolbox($secondary)->call('get_team_member_attendance', ['member' => 'Ahmed', 'period' => 'today']);

    expect($result['days'][0]['check_in'])->toBe('08:55');
});

it('names a team member with no fingerprint code instead of dropping them', function () {
    $org = teamOrg();
    teamPerson('New Starter', ['manager_id' => $org['samir']->id]);

    $toolbox = teamToolbox($org['samir']);
    $team = $toolbox->call('get_team_attendance', ['period' => 'today']);
    $member = $toolbox->call('get_team_member_attendance', ['member' => 'New Starter', 'period' => 'today']);

    expect($team['no_fingerprint_code'])->toBe(['New Starter'])
        ->and($team['no_fingerprint_code_count'])->toBe(1)
        ->and($team['people'])->toBe(3)
        ->and($member['error'])->toContain('not linked');
});

it('says so when nobody reports to the one asking', function () {
    $org = teamOrg();

    $team = teamToolbox($org['karim'])->call('get_team_attendance', ['period' => 'today']);
    $member = teamToolbox($org['karim'])->call('get_team_member_attendance', ['member' => 'Ahmed', 'period' => 'today']);
    $noRecord = teamToolbox(null)->call('get_team_attendance', ['period' => 'today']);

    expect($team['error'])->toContain('Nobody reports to you')
        ->and($member['error'])->toContain('Nobody reports to you')
        ->and(json_encode($member))->not->toContain('08:55')
        ->and($noRecord)->toHaveKey('error');
});

it('lets a whole-company owner read anyone who has not left', function () {
    teamOrg();
    $nadia = teamPerson('Nadia', ['job_title' => 'General Manager']);
    teamOwner($nadia);

    $toolbox = teamToolbox($nadia);
    $member = $toolbox->call('get_team_member_attendance', ['member' => 'Karim', 'period' => 'today']);
    $team = $toolbox->call('get_team_attendance', ['period' => 'today']);

    expect($member['days'][0]['check_in'])->toBe('09:44')
        ->and($member['employee'])->not->toHaveKey('reports_to_you_as')
        ->and($member['visible_because'])->toContain('whole company')
        ->and($team['can_see'])->toContain('everyone in the company')
        ->and($team['people'])->toBe(6)
        ->and(json_encode($team))->not->toContain('Tarek')
        ->and(json_encode($team))->not->toContain('06:48');
});

it('keeps a branch owner to their own branches', function () {
    teamOrg();
    teamRiyadhPerson();
    $faisal = teamPerson('Faisal', ['branch_id' => 2]);
    teamOwner($faisal, [2]);

    $toolbox = teamToolbox($faisal);
    $inBranch = $toolbox->call('get_team_member_attendance', ['member' => 'Rana', 'period' => 'today']);
    $elsewhere = $toolbox->call('get_team_member_attendance', ['member' => 'Ahmed', 'period' => 'today']);
    $team = $toolbox->call('get_team_attendance', ['period' => 'today']);

    expect($inBranch['days'][0]['check_in'])->toBe('09:31')
        ->and($inBranch['visible_because'])->toContain('their branch')
        ->and($elsewhere)->toHaveKey('error')
        ->and(json_encode($elsewhere))->not->toContain('08:55')
        ->and(array_column($team['members'], 'name'))->toBe(['Rana'])
        ->and($team['can_see'])->toContain('Riyadh');
});

it('adds an owner\'s branches to their own direct reports', function () {
    $org = teamOrg();
    teamRiyadhPerson();
    teamOwner($org['samir'], [2]);

    $team = teamToolbox($org['samir'])->call('get_team_attendance', ['period' => 'today']);

    expect(array_column($team['members'], 'name'))->toBe(['Ahmed', 'Mona', 'Rana'])
        ->and($team['members'][0]['reports_to_you_as'])->toBe('manager')
        ->and($team['members'][2])->not->toHaveKey('reports_to_you_as')
        ->and($team['can_see'])->toBe('the people who report to you, and everyone in Riyadh (attendance owner list)');
});

it('narrows the group by branch, department and kind of day', function () {
    teamOrg();
    teamRiyadhPerson();
    $nadia = teamPerson('Nadia');
    teamOwner($nadia);
    $toolbox = teamToolbox($nadia);

    $riyadh = $toolbox->call('get_team_attendance', ['period' => 'today', 'branch' => 'riyadh']);
    $finance = $toolbox->call('get_team_attendance', ['period' => 'today', 'department' => 'Finance']);
    $late = $toolbox->call('get_team_attendance', ['period' => 'today', 'only' => 'late']);

    expect(array_column($riyadh['members'], 'name'))->toBe(['Rana'])
        ->and(array_column($finance['members'], 'name'))->toBe(['Ahmed', 'Omar'])
        ->and(array_column($late['members'], 'name'))->toBe(['Karim', 'Omar', 'Rana'])
        // Only the late ones are listed; the counts still cover everyone.
        ->and($late['people'])->toBe(7)
        ->and($late['counts']['present'])->toBe(7)
        ->and($late['counts']['late_days'])->toBe(3);
});

it('puts the most such days first over a period', function () {
    $org = teamOrg();
    teamPunch($org['karim'], '09:50', '17:00', '2026-09-09');
    rebuildTeamDays();
    $nadia = teamPerson('Nadia');
    teamOwner($nadia);

    $late = teamToolbox($nadia)->call('get_team_attendance', ['period' => 'this_month', 'only' => 'late']);

    expect(array_column($late['members'], 'name'))->toBe(['Karim', 'Omar'])
        ->and($late['members'][0]['summary']['late_days'])->toBe(2)
        ->and($late['counts_are'])->toBe('days, added up over everyone');
});

it('refuses an unknown kind of day, and a branch outside what they can see', function () {
    $org = teamOrg();
    $toolbox = teamToolbox($org['samir']);

    $badOnly = $toolbox->call('get_team_attendance', ['period' => 'today', 'only' => 'sleepy']);
    $badBranch = $toolbox->call('get_team_attendance', ['period' => 'today', 'branch' => 'Riyadh']);

    expect($badOnly['error'])->toContain('only must be one of')
        ->and($badBranch['error'])->toContain('Nobody whose attendance you can see')
        ->and($badBranch['branches'])->toBe(['Jeddah'])
        ->and(json_encode($badBranch))->not->toContain('08:55');
});

it('tells two people apart by branch', function () {
    teamOrg();
    $inJeddah = teamPerson('Hassan', ['branch_id' => 1, 'email' => 'hassan.j@samirgroup.com'], '3001');
    $inRiyadh = teamPerson('Hassan', ['branch_id' => 2, 'email' => 'hassan.r@samirgroup.com'], '3002');
    teamPunch($inJeddah, '08:40', '17:00');
    teamPunch($inRiyadh, '08:20', '17:00');
    rebuildTeamDays();
    $nadia = teamPerson('Nadia');
    teamOwner($nadia);
    $toolbox = teamToolbox($nadia);

    $both = $toolbox->call('get_team_member_attendance', ['member' => 'Hassan', 'period' => 'today']);
    $one = $toolbox->call('get_team_member_attendance', ['member' => 'Hassan', 'branch' => 'Riyadh', 'period' => 'today']);

    expect($both['candidates'])->toHaveCount(2)
        ->and($both)->not->toHaveKey('days')
        ->and($one['days'][0]['check_in'])->toBe('08:20');
});

it('finds the owner row of someone signed in with a linked mailbox', function () {
    teamOrg();
    $nadia = teamPerson('Nadia');
    teamOwner($nadia);
    $mailbox = teamPerson('Nadia SG', ['email' => 'nadia@samirgroup.net', 'linked_primary_employee_id' => $nadia->id]);

    $result = teamToolbox($mailbox)->call('get_team_member_attendance', ['member' => 'Karim', 'period' => 'today']);

    expect($result['days'][0]['check_in'])->toBe('09:44');
});

it('gives someone who has left nobody\'s attendance', function () {
    teamOrg();
    $gone = teamPerson('Hisham', ['status' => 'terminated', 'terminated_date' => '2026-09-01']);
    teamOwner($gone);
    $report = teamPerson('Yousef', ['manager_id' => $gone->id], '3010');
    teamPunch($report, '08:05', '17:00');
    rebuildTeamDays();

    $team = teamToolbox($gone)->call('get_team_attendance', ['period' => 'today']);
    $member = teamToolbox($gone)->call('get_team_member_attendance', ['member' => 'Yousef', 'period' => 'today']);

    expect($team)->toHaveKey('error')
        ->and($team)->not->toHaveKey('members')
        ->and($member)->toHaveKey('error')
        ->and(json_encode($member))->not->toContain('08:05');
});
