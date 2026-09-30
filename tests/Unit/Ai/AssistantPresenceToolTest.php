<?php

use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\User;
use App\Services\Ai\AssistantToolbox;
use App\Services\Ai\KnowledgeRetriever;
use App\Services\Attendance\Presence;
use App\Services\Ticketing\TicketRequestService;
use App\Services\Vacation\VacationImporter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * get_team_presence: whether the people someone may see are in the building
 * right now. The same list as every other team tool — a manager's own direct
 * reports — so what is defended is the same: nobody outside it, however the
 * question is worded. Everyone punches at a time no one else does, so a leak
 * shows up as a time in the JSON.
 *
 * 2026-09-10 is a Thursday, a work day; the clock is frozen at 11:00.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-10 11:00:00');
    CarbonImmutable::setTestNow('2026-09-10 11:00:00');
    config(['cache.default' => 'array']);

    foreach (['vacation_absences', 'vacation_balances', 'vacation_employees', 'vacation_imports',
        'attendance_owners', 'attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'employees', 'departments', 'branches'] as $table) {
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
    (require database_path('migrations/2026_09_14_160001_create_vacation_tables.php'))->up();

    DB::table('branches')->insert([['id' => 1, 'name' => 'JED', 'city' => 'Jeddah'], ['id' => 2, 'name' => 'RYD', 'city' => 'Riyadh']]);
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
function presencePerson(string $name, string $code, array $attributes = []): Employee
{
    $employee = Employee::create($attributes + [
        'name' => $name,
        'email' => strtolower($name).'@samirgroup.com',
        'oracle_emp_no' => $code,
        'branch_id' => 1,
        'hired_date' => '2020-01-01',
        'status' => 'active',
    ]);

    $source = BiotimeSource::firstOrCreate(['name' => 'BioTime KSA'], [
        'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
        'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
        'last_sync_at' => '2026-09-10 10:58:00', 'last_sync_status' => 'ok',
    ]);

    BiotimeEmployee::create(['biotime_source_id' => $source->id, 'emp_code' => $code, 'employee_id' => $employee->id]);

    return $employee;
}

/** A punch at "Y-m-d H:i" (or "H:i" today) on a named door reader. */
function presencePunch(Employee $employee, string $time, ?string $terminal, ?string $state = '255'): void
{
    AttendancePunch::create([
        'biotime_source_id' => BiotimeSource::first()->id,
        'biotime_id' => (int) AttendancePunch::max('biotime_id') + 1,
        'biotime_employee_id' => BiotimeEmployee::where('employee_id', $employee->id)->value('id'),
        'employee_id' => $employee->id,
        'emp_code' => $employee->oracle_emp_no,
        'punch_time' => (strlen($time) === 5 ? '2026-09-10 ' : '').$time.':00',
        'punch_state' => $state,
        'terminal_alias' => $terminal,
        'synced_at' => now(),
    ]);
}

function presenceToolbox(Employee $employee): AssistantToolbox
{
    return new AssistantToolbox(
        new User(['name' => $employee->name, 'email' => $employee->email]),
        $employee,
        null,
        app(KnowledgeRetriever::class),
        app(TicketRequestService::class),
    );
}

/**
 * Samir manages Ahmed, Mona, Omar and Huda, all in Jeddah. Karim reports to
 * nobody here and is in the building; he must never show.
 *
 * @return array<string, Employee>
 */
function presenceOrg(): array
{
    $samir = presencePerson('Samir', '3000');
    $ahmed = presencePerson('Ahmed', '3001', ['manager_id' => $samir->id, 'department_id' => 1]);
    $mona = presencePerson('Mona', '3002', ['manager_id' => $samir->id, 'department_id' => 2]);
    $omar = presencePerson('Omar', '3003', ['manager_id' => $samir->id, 'department_id' => 1]);
    $huda = presencePerson('Huda', '3004', ['manager_id' => $samir->id, 'department_id' => 2]);
    $karim = presencePerson('Karim', '3005');

    // Yesterday's punches decide nothing about today.
    presencePunch($ahmed, '2026-09-09 08:40', 'Jeddah_IN');
    presencePunch($ahmed, '08:55', 'Jeddah_IN');
    // BioTime writes 0, "check in", on the OUT reader: the reader's name decides.
    presencePunch($mona, '08:30', 'Jeddah_IN', '0');
    presencePunch($mona, '10:15', 'Jeddah_OUT', '0');
    presencePunch($omar, '09:04', 'WHJED', '0');
    presencePunch($karim, '07:47', 'Jeddah_IN');

    app(VacationImporter::class)->importAbsences('samirgroup', [
        ['person_number' => '3004', 'type' => 'Annual Leave', 'start' => '2026-09-08', 'end' => '2026-09-17'],
    ]);

    return compact('samir', 'ahmed', 'mona', 'omar', 'huda', 'karim');
}

/** @return array<string, array<string, mixed>> the listed members, by name */
function presenceByName(array $result): array
{
    return collect($result['members'])->keyBy('name')->all();
}

// ─── Reading a door ─────────────────────────────────────────────

test('the direction comes from the door reader, and CHECKTYPE letters where there is none', function () {
    expect(Presence::direction('Riyadh_IN', '0'))->toBe('in');
    expect(Presence::direction('Riyadh_Out', '1'))->toBe('out');
    expect(Presence::direction('Jeddah_OUT', '0'))->toBe('out');
    expect(Presence::direction('Out', null))->toBe('out');
    expect(Presence::direction('Main Entrance', null))->toBe('in');
    expect(Presence::direction('CheckIn', null))->toBe('in');
    expect(Presence::direction(null, 'I'))->toBe('in');
    expect(Presence::direction('', 'O'))->toBe('out');

    // A reader named for neither decides nothing, and a numeric state is not trusted.
    expect(Presence::direction('WHRYD', '0'))->toBeNull();
    expect(Presence::direction('RYD_NEW_Office', '1'))->toBeNull();
    expect(Presence::direction('Maintenance', null))->toBeNull();
});

test('an unmarked reader gives a probable status from the number of punches', function () {
    $one = Presence::from([['time' => '2026-09-10 08:00:00', 'terminal' => 'WHRYD']]);
    $two = Presence::from([
        ['time' => '2026-09-10 08:00:00', 'terminal' => 'WHRYD'],
        ['time' => '2026-09-10 10:00:00', 'terminal' => 'WHRYD'],
    ]);

    expect($one->status)->toBe(Presence::PROBABLY_IN);
    expect($two->status)->toBe(Presence::PROBABLY_LEFT);
    expect($two->arrived)->toBe('2026-09-10 08:00:00');
    expect(Presence::from([])->status)->toBe(Presence::NO_PUNCH);
});

// ─── The tool ───────────────────────────────────────────────────

test('a manager sees who of their people is in the building now, and nobody else', function () {
    $org = presenceOrg();

    $result = presenceToolbox($org['samir'])->call('get_team_presence', []);
    $people = presenceByName($result);

    expect(array_keys($people))->toBe(['Ahmed', 'Huda', 'Mona', 'Omar']);
    expect($people['Ahmed'])->toMatchArray(['status' => Presence::IN, 'arrived' => '08:55', 'last_door' => 'Jeddah_IN']);
    expect($people['Mona'])->toMatchArray(['status' => Presence::LEFT, 'arrived' => '08:30', 'last_punch' => '10:15']);
    expect($people['Omar']['status'])->toBe(Presence::PROBABLY_IN);
    expect($people['Huda']['status'])->toBe(Presence::NO_PUNCH);
    expect($people['Huda']['oracle_leave_today']['type'])->toBe('Annual Leave');
    expect($people['Ahmed']['due'])->toBe('09:00–17:00');
    // Out at 10:15 with the shift running to 17:00: said to be possibly a step out.
    expect($people['Mona']['caution'])->toContain('17:00');
    expect($people['Ahmed'])->not->toHaveKey('caution');

    expect($result['counts'])->toMatchArray([
        Presence::IN => 1, Presence::PROBABLY_IN => 1, Presence::LEFT => 1, Presence::NO_PUNCH => 1,
        'not_in_and_on_leave_or_trip' => 1,
    ]);

    // Karim punched in at 07:47; that time must appear nowhere.
    expect(json_encode($result))->not->toContain('Karim')->not->toContain('07:47');
});

test('one person comes with every punch of the day', function () {
    $org = presenceOrg();

    $result = presenceToolbox($org['samir'])->call('get_team_presence', ['member' => 'Mona']);

    expect($result['members'])->toHaveCount(1);
    expect($result['members'][0]['punches_today'])->toBe([
        ['time' => '08:30', 'door' => 'Jeddah_IN', 'direction' => 'in'],
        ['time' => '10:15', 'door' => 'Jeddah_OUT', 'direction' => 'out'],
    ]);
});

test('asking about someone outside the team is not found, whatever is claimed', function () {
    $org = presenceOrg();

    $result = presenceToolbox($org['samir'])->call('get_team_presence', ['member' => 'Karim']);

    expect($result)->toHaveKey('error');
    expect(json_encode($result))->not->toContain('07:47');
});

test('someone with nobody reporting to them sees no one', function () {
    $org = presenceOrg();

    expect(presenceToolbox($org['karim'])->call('get_team_presence', []))->toHaveKey('error');
});

test('only narrows the list and leaves the counts whole', function () {
    $org = presenceOrg();

    $in = presenceToolbox($org['samir'])->call('get_team_presence', ['only' => 'in_building']);
    $out = presenceToolbox($org['samir'])->call('get_team_presence', ['only' => 'not_in', 'department' => 'Sales']);

    expect(array_keys(presenceByName($in)))->toBe(['Ahmed', 'Omar']);
    expect($in['counts'][Presence::LEFT])->toBe(1);
    expect(array_keys(presenceByName($out)))->toBe(['Huda', 'Mona']);
    expect(presenceToolbox($org['samir'])->call('get_team_presence', ['only' => 'everywhere']))->toHaveKey('error');
});

test('a night shift still inside yesterday\'s window is in, not "no punch today"', function () {
    $org = presenceOrg();
    $nadia = presencePerson('Nadia', '3006', ['manager_id' => $org['samir']->id]);
    presencePunch($nadia, '2026-09-09 21:50', 'Jeddah_IN');

    DB::table('attendance_days')->insert([
        'subject_key' => 'emp:'.$nadia->id, 'work_date' => '2026-09-09', 'employee_id' => $nadia->id,
        'window_start' => '2026-09-09 14:00:00', 'window_end' => '2026-09-10 12:00:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $result = presenceToolbox($org['samir'])->call('get_team_presence', ['member' => 'Nadia']);

    expect($result['members'][0])->toMatchArray(['status' => Presence::IN, 'arrived' => '2026-09-09 21:50']);
});

test('a fingerprint system that has not been read lately is said to be behind', function () {
    $org = presenceOrg();
    BiotimeSource::query()->update(['last_sync_at' => '2026-09-10 09:30:00']);

    $result = presenceToolbox($org['samir'])->call('get_team_presence', []);

    expect(implode(' ', $result['notes']))->toContain('last copied at 2026-09-10 09:30');
});
