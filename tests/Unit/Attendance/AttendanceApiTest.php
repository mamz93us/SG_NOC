<?php

use App\Http\Middleware\HrApiKeyMiddleware;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\HrApiKey;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\AttendancePeriodService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oracle's attendance API — real requests through the router, with the key
 * middleware and the throttle in the way.
 *
 * Lives under Unit/ with Tests\TestCase bound by hand, like VoiceMeshApiTest:
 * a full migrate cannot run on SQLite, so the tables come from the attendance
 * migrations and the two hr_api_keys ones.
 *
 * 2026-09-10 is a Thursday; "now" is frozen there at 18:00, after the Office
 * shift (09:00–17:00) has ended. Friday and Saturday are days off.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-10 18:00:00');
    CarbonImmutable::setTestNow('2026-09-10 18:00:00');
    config(['cache.default' => 'array']);

    foreach (['hr_api_keys', 'attendance_exports', 'attendance_periods', 'attendance_tasks',
        'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'employees', 'departments', 'branches', 'users'] as $table) {
        Schema::dropIfExists($table);
    }

    // hr_api_keys.created_by references users; SQLite refuses the insert
    // without the parent table even when the column is null.
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        $t->string('name');
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
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });

    foreach (array_merge(
        glob(database_path('migrations/2026_09_10_*.php')),
        glob(database_path('migrations/2026_09_11_*.php')),
        glob(database_path('migrations/2026_09_12_*biotime*.php')),
        glob(database_path('migrations/*_hr_api_keys_table.php')),
    ) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert([['id' => 1, 'name' => 'Jeddah'], ['id' => 2, 'name' => 'Cairo']]);

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

function oracleApiSource(): BiotimeSource
{
    return BiotimeSource::firstOrCreate(['name' => 'BioTime KSA'], [
        'host' => 'sql.test', 'port' => 1433, 'database' => 'biotime',
        'username' => 'noc', 'password' => 'secret', 'trust_server_certificate' => true, 'enabled' => true,
    ]);
}

/** An employee linked to a fingerprint code — their Oracle number unless another badge is given. */
function oracleApiPerson(string $name, ?string $oracle, ?string $badge = null, int $branch = 1): Employee
{
    $employee = Employee::create([
        'name' => $name, 'oracle_emp_no' => $oracle, 'branch_id' => $branch,
        'hired_date' => '2020-01-01', 'status' => 'active',
    ]);

    BiotimeEmployee::create([
        'biotime_source_id' => oracleApiSource()->id,
        'emp_code' => $badge ?? $oracle,
        'employee_id' => $employee->id,
    ]);

    return $employee;
}

/** A punch by an employee, or by a fingerprint code linked to nobody. */
function oracleApiPunch(Employee|BiotimeEmployee $who, string $time, string $state = '0'): void
{
    $code = $who instanceof Employee ? BiotimeEmployee::where('employee_id', $who->id)->firstOrFail() : $who;
    $id = (int) AttendancePunch::max('biotime_id') + 1;

    AttendancePunch::create([
        'biotime_source_id' => oracleApiSource()->id,
        'biotime_id' => $id,
        'external_id' => (string) $id,
        'biotime_employee_id' => $code->id,
        'employee_id' => $code->employee_id,
        'emp_code' => $code->emp_code,
        'punch_time' => $time,
        'punch_state' => $state,
        'terminal_sn' => 'JED-01',
        'terminal_alias' => 'JED Main Entrance',
        'area_alias' => 'Jeddah HQ',
        'synced_at' => now(),
    ]);
}

function oracleApiRebuild(): void
{
    (new AttendanceDayProcessor(new AttendanceDayBuilder))->rebuildRange('2026-09-01', '2026-09-30');
}

/** A raw key with this scope; null is a General key. */
function oracleApiKey(?string $scope = 'attendance'): string
{
    return HrApiKey::generate('Oracle', null, null, $scope)[0];
}

// ── keys ─────────────────────────────────────────────────────────

it('refuses a request with no key, or a revoked one', function () {
    $this->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertStatus(401)
        ->assertJson(['error' => 'API key required.']);

    [$raw, $key] = HrApiKey::generate('Oracle', null, null, 'attendance');
    $key->revoke();

    $this->withToken($raw)
        ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertStatus(401);
});

it('accepts an attendance key or a General key, in either header', function () {
    $this->withToken(oracleApiKey('attendance'))
        ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertOk();

    $this->flushHeaders()
        ->withHeader('X-HR-Api-Key', oracleApiKey(null))
        ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertOk();
});

it('refuses a key issued for another API', function () {
    foreach (['hr', 'signature'] as $scope) {
        $this->flushHeaders()
            ->withHeader('X-HR-Api-Key', oracleApiKey($scope))
            ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
            ->assertStatus(403);
    }
});

it('keeps an attendance key out of the HR API, and the legacy .env key out of this one', function () {
    config(['services.hr_api.key' => 'legacy-env-key']);

    // The middleware on its own: no HR tables needed to see who gets through.
    $call = function (string $key, string ...$scope) {
        $request = Request::create('/api/hr/employees');
        $request->headers->set('X-HR-Api-Key', $key);

        return (new HrApiKeyMiddleware)->handle($request, fn () => response('through'), ...$scope);
    };

    expect($call(oracleApiKey('attendance'))->getStatusCode())->toBe(403)
        ->and($call(oracleApiKey('signature'))->getStatusCode())->toBe(403)
        ->and($call(oracleApiKey('hr'))->getContent())->toBe('through')
        ->and($call(oracleApiKey(null))->getContent())->toBe('through')
        ->and($call('legacy-env-key')->getContent())->toBe('through')
        ->and($call('legacy-env-key', 'attendance')->getStatusCode())->toBe(401);
});

// ── the request ──────────────────────────────────────────────────

it('answers a bad request in JSON even without an Accept header', function () {
    $this->withHeader('X-HR-Api-Key', oracleApiKey())
        ->get('/api/attendance?from=2026-09-30&to=2026-09-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->get('/api/attendance?to=2026-09-01')
        ->assertStatus(422)
        ->assertJsonValidationErrors('from');
});

it('holds everyone to 31 days and one person to 366', function () {
    oracleApiPerson('Ahmed', '1001');
    $this->withToken(oracleApiKey());

    $this->getJson('/api/attendance?from=2026-08-31&to=2026-09-30')->assertOk();
    $this->getJson('/api/attendance?from=2026-08-30&to=2026-09-30')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');

    $this->getJson('/api/attendance/employees/1001?from=2025-09-10&to=2026-09-10')->assertOk();
    $this->getJson('/api/attendance/employees/1001?from=2025-09-09&to=2026-09-10')
        ->assertStatus(422)
        ->assertJsonValidationErrors('to');
});

// ── everyone ─────────────────────────────────────────────────────

it('returns each day with its check-in, check-out and every punch', function () {
    $ahmed = oracleApiPerson('Ahmed', '1001');
    oracleApiPunch($ahmed, '2026-09-10 08:55:00', '0');
    oracleApiPunch($ahmed, '2026-09-10 13:02:00', '2');
    oracleApiPunch($ahmed, '2026-09-10 17:40:00', '1');
    oracleApiRebuild();

    $body = $this->withToken(oracleApiKey())
        ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->json();

    expect($body['total'])->toBe(1)
        ->and($body['records'])->toHaveCount(1);

    $record = $body['records'][0];

    // Everything the period export carries, so pushed and pulled data match.
    expect($record)->toHaveKeys(AttendancePeriodService::RECORD_FIELDS)
        ->and($record['employee_id'])->toBe($ahmed->id)
        ->and($record['oracle_emp_no'])->toBe('1001')
        ->and($record['date'])->toBe('2026-09-10')
        ->and($record['status'])->toBe('present')
        // The device's wall clock as punched: no zone, no conversion.
        ->and($record['check_in'])->toBe('2026-09-10 08:55:00')
        ->and($record['check_out'])->toBe('2026-09-10 17:40:00')
        ->and($record['overtime_minutes'])->toBe(40)
        ->and($record['approved'])->toBeFalse()
        ->and($record['punches'])->toBe([
            ['time' => '2026-09-10 08:55:00', 'state' => '0', 'state_label' => 'Check in', 'terminal' => 'JED Main Entrance', 'area' => 'Jeddah HQ'],
            ['time' => '2026-09-10 13:02:00', 'state' => '2', 'state_label' => 'Break out', 'terminal' => 'JED Main Entrance', 'area' => 'Jeddah HQ'],
            ['time' => '2026-09-10 17:40:00', 'state' => '1', 'state_label' => 'Check out', 'terminal' => 'JED Main Entrance', 'area' => 'Jeddah HQ'],
        ]);
});

it('returns an absence with no times and no punches', function () {
    oracleApiPerson('Mona', '1002');
    oracleApiRebuild();

    $record = $this->withToken(oracleApiKey())
        ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertOk()
        ->json('records.0');

    expect($record['status'])->toBe('absent')
        ->and($record['check_in'])->toBeNull()
        ->and($record['check_out'])->toBeNull()
        ->and($record['has_error'])->toBeTrue()
        ->and($record['flags'])->toContain('absent')
        ->and($record['punches'])->toBe([]);
});

it('leaves out the days Oracle cannot take, and counts them', function () {
    $ahmed = oracleApiPerson('Ahmed', '1001');
    $nabil = oracleApiPerson('Nabil', null, '7001');
    $visitor = BiotimeEmployee::create(['biotime_source_id' => oracleApiSource()->id, 'emp_code' => '9999']);

    foreach ([$ahmed, $nabil, $visitor] as $who) {
        oracleApiPunch($who, '2026-09-10 09:00:00');
        oracleApiPunch($who, '2026-09-10 17:00:00');
    }
    oracleApiRebuild();

    $body = $this->withToken(oracleApiKey())
        ->getJson('/api/attendance?from=2026-09-10&to=2026-09-10')
        ->assertOk()
        ->json();

    expect(array_column($body['records'], 'oracle_emp_no'))->toBe(['1001'])
        ->and($body['excluded'])->toBe(['days_without_oracle_number' => 1, 'days_not_linked_to_an_employee' => 1]);
});

it('pages through everyone in a stable order', function () {
    foreach (['1001', '1002', '1003'] as $code) {
        oracleApiPerson("Person {$code}", $code);
    }
    oracleApiRebuild();
    $this->withToken(oracleApiKey());

    $first = $this->getJson('/api/attendance?from=2026-09-10&to=2026-09-10&per_page=2')->assertOk()->json();
    $second = $this->getJson('/api/attendance?from=2026-09-10&to=2026-09-10&per_page=2&page=2')->assertOk()->json();

    expect([$first['total'], $first['last_page'], $first['count'], $second['count']])->toBe([3, 2, 2, 1])
        ->and(array_merge(array_column($first['records'], 'oracle_emp_no'), array_column($second['records'], 'oracle_emp_no')))
        ->toBe(['1001', '1002', '1003']);
});

it('keeps an overnight check-out on the day it belongs to', function () {
    $karim = oracleApiPerson('Karim', '1004');
    AttendanceShiftAssignment::create([
        'attendance_shift_id' => AttendanceShift::create([
            'name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00',
            'grace_in_minutes' => 10, 'grace_out_minutes' => 5, 'max_hours' => 12,
            'min_overtime_minutes' => 30, 'off_days' => [5, 6], 'is_active' => true,
        ])->id,
        'scope_type' => 'employee',
        'scope_id' => $karim->id,
        'effective_from' => '2020-01-01',
    ]);

    oracleApiPunch($karim, '2026-09-08 21:55:00');
    oracleApiPunch($karim, '2026-09-09 06:04:00');
    oracleApiRebuild();

    $records = collect($this->withToken(oracleApiKey())
        ->getJson('/api/attendance/employees/1004?from=2026-09-08&to=2026-09-09')
        ->assertOk()
        ->json('records'))->keyBy('date');

    expect($records['2026-09-08']['check_in'])->toBe('2026-09-08 21:55:00')
        ->and($records['2026-09-08']['check_out'])->toBe('2026-09-09 06:04:00')
        ->and(array_column($records['2026-09-08']['punches'], 'time'))->toBe(['2026-09-08 21:55:00', '2026-09-09 06:04:00'])
        // The next calendar day does not get the 06:04 check-out a second time.
        ->and($records['2026-09-09']['punches'])->toBe([]);
});

// ── one employee ─────────────────────────────────────────────────

it('returns one employee\'s days and nobody else\'s', function () {
    $ahmed = oracleApiPerson('Ahmed', '1001');
    $mona = oracleApiPerson('Mona', '1002');
    oracleApiPunch($ahmed, '2026-09-10 08:55:00');
    oracleApiPunch($ahmed, '2026-09-10 17:30:00');
    oracleApiPunch($mona, '2026-09-10 07:11:00');
    oracleApiRebuild();

    $body = $this->withToken(oracleApiKey())
        ->getJson('/api/attendance/employees/1001?from=2026-09-01&to=2026-09-10')
        ->assertOk()
        ->json();

    expect($body['employee'])->toMatchArray(['employee_id' => $ahmed->id, 'oracle_emp_no' => '1001', 'name' => 'Ahmed', 'branch' => 'Jeddah'])
        ->and(array_values(array_unique(array_column($body['records'], 'oracle_emp_no'))))->toBe(['1001'])
        ->and(collect($body['records'])->firstWhere('date', '2026-09-10')['check_out'])->toBe('2026-09-10 17:30:00')
        ->and(json_encode($body))->not->toContain('07:11')
        ->and($body)->not->toHaveKey('excluded');
});

it('answers 404 for an Oracle number nobody holds', function () {
    $this->withToken(oracleApiKey())
        ->get('/api/attendance/employees/424242?from=2026-09-01&to=2026-09-10')
        ->assertStatus(404)
        ->assertJson(['ok' => false]);
});

it('will not choose between two employees sharing an Oracle number', function () {
    $jeddah = oracleApiPerson('Ahmed Jeddah', '512', '512', 1);
    $cairo = oracleApiPerson('Ahmed Cairo', '512', '55512', 2);
    oracleApiPunch($cairo, '2026-09-10 08:30:00');
    oracleApiPunch($cairo, '2026-09-10 16:30:00');
    oracleApiRebuild();
    $this->withToken(oracleApiKey());

    $conflict = $this->getJson('/api/attendance/employees/512?from=2026-09-10&to=2026-09-10')
        ->assertStatus(409)
        ->json();

    expect(array_column($conflict['candidates'], 'employee_id'))->toBe([$jeddah->id, $cairo->id])
        ->and($conflict)->not->toHaveKey('records');

    $this->getJson('/api/attendance/employees/512?from=2026-09-10&to=2026-09-10&branch_id=2')
        ->assertOk()
        ->assertJsonPath('employee.employee_id', $cairo->id)
        ->assertJsonPath('records.0.check_in', '2026-09-10 08:30:00');

    $this->getJson("/api/attendance/employees/512?from=2026-09-10&to=2026-09-10&employee_id={$jeddah->id}")
        ->assertOk()
        ->assertJsonPath('employee.name', 'Ahmed Jeddah');
});

it('ignores a linked secondary account carrying the same number', function () {
    $primary = oracleApiPerson('Sara', '10432');
    Employee::create([
        'name' => 'Sara (second mailbox)', 'oracle_emp_no' => '10432', 'branch_id' => 1,
        'linked_primary_employee_id' => $primary->id,
    ]);

    $this->withToken(oracleApiKey())
        ->getJson('/api/attendance/employees/10432?from=2026-09-10&to=2026-09-10')
        ->assertOk()
        ->assertJsonPath('employee.employee_id', $primary->id);
});

it('marks the days of an approved period', function () {
    oracleApiPerson('Ahmed', '1001');
    oracleApiRebuild();
    AttendanceDay::query()->where('work_date', '2026-09-09')->update(['locked' => true]);

    $records = collect($this->withToken(oracleApiKey())
        ->getJson('/api/attendance/employees/1001?from=2026-09-09&to=2026-09-10')
        ->assertOk()
        ->json('records'))->keyBy('date');

    expect($records['2026-09-09']['approved'])->toBeTrue()
        ->and($records['2026-09-10']['approved'])->toBeFalse();
});
