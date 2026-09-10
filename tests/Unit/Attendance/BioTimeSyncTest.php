<?php

use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendanceHoliday;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Employee;
use App\Models\NocEvent;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use App\Services\Attendance\EmployeeLinker;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * biotime:sync end to end — ingest, employee linking and day building —
 * against a fake BioTime: a second in-memory SQLite database holding an
 * iclock_transaction table with the columns the real query reads.
 *
 * Binds Tests\TestCase by hand WITHOUT RefreshDatabase: several migrations in
 * this repo are MySQL-only and cannot run on SQLite (see TunnelWatchdogTest),
 * so the attendance migrations are run directly and the handful of NOC tables
 * they read are created here.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    // Absences depend on the clock: fix it. 2026-09-10 is a Thursday.
    Carbon::setTestNow('2026-09-10 12:00:00');
    CarbonImmutable::setTestNow('2026-09-10 12:00:00');

    config(['cache.default' => 'array']);

    config(['database.connections.biotime_fake' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('biotime_fake');
    Schema::connection('biotime_fake')->create('iclock_transaction', function (Blueprint $t) {
        $t->integer('id')->primary();
        $t->string('emp_code')->nullable();
        $t->dateTime('punch_time')->nullable();
        $t->string('punch_state')->nullable();
        $t->string('terminal_sn')->nullable();
        $t->string('terminal_alias')->nullable();
        $t->string('area_alias')->nullable();
    });

    foreach (['attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
        'attendance_days', 'attendance_punches', 'biotime_employees', 'biotime_terminals', 'biotime_areas',
        'biotime_sources', 'noc_events', 'azure_branch_mappings', 'employees', 'branches'] as $table) {
        Schema::dropIfExists($table);
    }

    Schema::create('branches', function (Blueprint $t) {
        $t->unsignedInteger('id')->primary();
        $t->string('name');
        $t->timestamps();
    });
    Schema::create('employees', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('oracle_emp_no')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('linked_primary_employee_id')->nullable();
        $t->unsignedInteger('department_id')->nullable();
        $t->date('hired_date')->nullable();
        $t->date('terminated_date')->nullable();
        $t->string('status')->default('active');
        $t->timestamps();
    });
    Schema::create('azure_branch_mappings', function (Blueprint $t) {
        $t->id();
        $t->string('keyword');
        $t->unsignedInteger('branch_id');
        $t->timestamps();
    });
    Schema::create('noc_events', function (Blueprint $t) {
        $t->id();
        foreach (['module', 'entity_type', 'entity_id', 'source_type', 'severity', 'title', 'status'] as $column) {
            $t->string($column)->nullable();
        }
        $t->text('message')->nullable();
        $t->unsignedInteger('branch_id')->nullable();
        $t->unsignedBigInteger('source_id')->nullable();
        $t->integer('cooldown_minutes')->nullable();
        $t->unsignedBigInteger('acknowledged_by')->nullable();
        $t->unsignedBigInteger('resolved_by')->nullable();
        foreach (['first_seen', 'last_seen', 'resolved_at', 'email_sent_at'] as $column) {
            $t->timestamp($column)->nullable();
        }
        $t->timestamps();
    });

    // Every attendance migration except the permission grant (no role_permissions here).
    foreach (glob(database_path('migrations/2026_09_10_*.php')) as $migration) {
        if (! str_contains($migration, 'permission')) {
            (require $migration)->up();
        }
    }

    DB::table('branches')->insert([['id' => 1, 'name' => 'Jeddah'], ['id' => 2, 'name' => 'Cairo']]);
    DB::table('azure_branch_mappings')->insert(['keyword' => 'Jeddah', 'branch_id' => 1]);
});

/** A BioTimeConnection that answers from the fake database instead of SQL Server. */
function bioTimeOn(string $connection): BioTimeConnection
{
    return new class($connection) extends BioTimeConnection
    {
        public function __construct(private string $fake) {}

        public function connection(BiotimeSource $source): ConnectionInterface
        {
            return DB::connection($this->fake);
        }
    };
}

function bioTimeSync(?BioTimeConnection $bioTime = null): BioTimeSyncService
{
    $processor = new AttendanceDayProcessor(new AttendanceDayBuilder);

    return new BioTimeSyncService($bioTime ?? bioTimeOn('biotime_fake'), new EmployeeLinker($processor), $processor);
}

function biotimePunch(int $id, string $code, string $time, ?string $area = 'Jeddah HQ'): void
{
    DB::connection('biotime_fake')->table('iclock_transaction')->insert([
        'id' => $id,
        'emp_code' => $code,
        'punch_time' => $time,
        'punch_state' => '0',
        'terminal_sn' => 'SN-'.($area ?? 'none'),
        'terminal_alias' => 'Main gate',
        'area_alias' => $area,
    ]);
}

function biotimeSource(string $name = 'BioTime KSA'): BiotimeSource
{
    return BiotimeSource::create([
        'name' => $name,
        'host' => 'sql.test',
        'port' => 1433,
        'database' => 'biotime',
        'username' => 'noc_attendance',
        'password' => 'secret',
        'trust_server_certificate' => true,
        'enabled' => true,
    ]);
}

function attendanceEmployee(int $id, string $name, ?string $oracleNo, ?int $branchId): void
{
    DB::table('employees')->insert([
        'id' => $id, 'name' => $name, 'oracle_emp_no' => $oracleNo, 'branch_id' => $branchId, 'status' => 'active',
    ]);
}

it('pulls punches, links the code by Oracle number and builds first-in / last-out', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');
    biotimePunch(2, '1001', '2026-09-09 12:30:00');
    biotimePunch(3, '1001', '2026-09-09 17:05:00');
    $source = biotimeSource();

    $result = bioTimeSync()->sync($source);

    expect($result['rows'])->toBe(3)
        ->and($result['new_codes'])->toBe(1)
        ->and($result['last_id'])->toBe(3)
        ->and($source->fresh()->last_sync_status)->toBe('ok');

    $code = BiotimeEmployee::sole();
    expect($code->employee_id)->toBe(10)
        ->and($code->match_method)->toBe(BiotimeEmployee::METHOD_AUTO)
        ->and(AttendancePunch::where('employee_id', 10)->count())->toBe(3);

    $day = AttendanceDay::sole();
    expect($day->subject_key)->toBe('emp:10')
        ->and($day->work_date->toDateString())->toBe('2026-09-09')
        ->and($day->first_in->format('H:i'))->toBe('08:55')
        ->and($day->last_out->format('H:i'))->toBe('17:05')
        ->and($day->worked_minutes)->toBe(490)
        ->and($day->branch_id)->toBe(1)
        ->and($day->has_error)->toBeFalse();
});

it('matches a BioTime code with leading zeros to the Oracle number', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '001001', '2026-09-09 08:55:00');

    bioTimeSync()->sync(biotimeSource());

    expect(BiotimeEmployee::sole()->employee_id)->toBe(10);
});

it('is idempotent and reads only ids past the watermark', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');
    biotimePunch(2, '1001', '2026-09-09 16:00:00');
    $source = biotimeSource();
    $sync = bioTimeSync();

    $sync->sync($source);
    expect($sync->sync($source)['rows'])->toBe(0)
        ->and(AttendancePunch::count())->toBe(2);

    biotimePunch(3, '1001', '2026-09-09 17:05:00');
    expect($sync->sync($source)['rows'])->toBe(1)
        ->and(AttendanceDay::sole()->last_out->format('H:i'))->toBe('17:05');
});

it('picks up punches a terminal uploads late, carrying an old time', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');
    $source = biotimeSource();
    $sync = bioTimeSync();
    $sync->sync($source);

    // An offline terminal comes back: NEW ids, LAST WEEK's times.
    biotimePunch(2, '1001', '2026-09-02 08:10:00');
    biotimePunch(3, '1001', '2026-09-02 17:20:00');
    $sync->sync($source);

    $day = AttendanceDay::where('work_date', '2026-09-02')->sole();
    expect($day->first_in->format('H:i'))->toBe('08:10')
        ->and($day->last_out->format('H:i'))->toBe('17:20');
});

it('settles a colliding Oracle number by the branch of the punch area', function () {
    attendanceEmployee(20, 'Mona (SSS Egypt)', '2002', 2);
    attendanceEmployee(21, 'Mona (SamirGroup)', '2002', 1);
    biotimePunch(1, '2002', '2026-09-09 09:00:00', 'Jeddah HQ'); // keyword "Jeddah" → branch 1
    biotimePunch(2, '2002', '2026-09-09 17:00:00', 'Jeddah HQ');

    bioTimeSync()->sync(biotimeSource());

    $code = BiotimeEmployee::sole();
    expect($code->employee_id)->toBe(21)
        ->and($code->match_method)->toBe(BiotimeEmployee::METHOD_AUTO_BRANCH)
        ->and($code->candidate_ids)->toEqualCanonicalizing([20, 21]);
});

it('leaves an unresolvable collision for HR and flags the day until HR links it', function () {
    attendanceEmployee(20, 'Mona (SSS Egypt)', '2002', 2);
    attendanceEmployee(21, 'Mona (SamirGroup)', '2002', 1);
    biotimePunch(1, '2002', '2026-09-09 09:00:00', 'Riyadh Office'); // no branch known for this area
    biotimePunch(2, '2002', '2026-09-09 17:00:00', 'Riyadh Office');

    bioTimeSync()->sync(biotimeSource());

    $code = BiotimeEmployee::sole();
    expect($code->employee_id)->toBeNull()
        ->and($code->match_method)->toBe(BiotimeEmployee::METHOD_AMBIGUOUS);

    $day = AttendanceDay::sole();
    expect($day->subject_key)->toBe('bt:'.$code->id)
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_UNMAPPED)
        ->and($day->has_error)->toBeTrue();

    $linker = new EmployeeLinker(new AttendanceDayProcessor(new AttendanceDayBuilder));
    $linker->linkManually($code, Employee::find(20), null);

    $day = AttendanceDay::sole();
    expect($day->subject_key)->toBe('emp:20')
        ->and($day->has_error)->toBeFalse()
        ->and(AttendancePunch::where('employee_id', 20)->count())->toBe(2);

    // The automatic rule never overrides HR.
    expect($linker->autoLink($code->fresh()))->toBeFalse()
        ->and($code->fresh()->employee_id)->toBe(20);
});

it('flags a lone punch as a missing check-out', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');

    bioTimeSync()->sync(biotimeSource());

    $day = AttendanceDay::sole();
    expect($day->first_in->format('H:i'))->toBe('08:55')
        ->and($day->last_out)->toBeNull()
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT])
        ->and($day->has_error)->toBeTrue();
});

it('keeps two BioTime databases apart even when their ids overlap', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');
    biotimePunch(2, '1001', '2026-09-09 17:05:00');
    $sync = bioTimeSync();

    $sync->sync(biotimeSource('BioTime KSA'));
    $sync->sync(biotimeSource('BioTime Egypt')); // same ids 1 and 2

    expect(AttendancePunch::count())->toBe(4)
        ->and(BiotimeEmployee::count())->toBe(2)
        // Same person on both databases: still one day.
        ->and(AttendanceDay::count())->toBe(1)
        ->and(AttendanceDay::sole()->punch_count)->toBe(4);
});

it('records a failing source, alerts after three failures in a row, and clears on success', function () {
    $source = biotimeSource();
    $broken = new class extends BioTimeConnection
    {
        public function connection(BiotimeSource $source): ConnectionInterface
        {
            throw new RuntimeException('Login failed for user noc_attendance');
        }
    };

    foreach (range(1, 3) as $attempt) {
        expect(fn () => bioTimeSync($broken)->sync($source))->toThrow(RuntimeException::class);
        expect(NocEvent::where('status', 'open')->count())->toBe($attempt < 3 ? 0 : 1);
    }

    expect($source->fresh()->consecutive_failures)->toBe(3)
        ->and($source->fresh()->last_sync_error)->toBe('Login failed for user noc_attendance');

    bioTimeSync()->sync($source);

    expect($source->fresh()->consecutive_failures)->toBe(0)
        ->and(NocEvent::where('status', 'open')->count())->toBe(0);
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

// ── Phase 2: shifts, absences, holidays, corrections ─────────────────

/** A shift assigned to everyone from 1 Sep: 09:00–17:00, 10 min grace, Friday/Saturday off. */
function assignShift(array $attributes = [], string $scope = 'all', ?int $scopeId = null): AttendanceShift
{
    $shift = AttendanceShift::create($attributes + [
        'name' => 'Office',
        'start_time' => '09:00',
        'end_time' => '17:00',
        'grace_in_minutes' => 10,
        'grace_out_minutes' => 0,
        'max_hours' => 16,
        'min_overtime_minutes' => 30,
        'off_days' => [5, 6],
        'is_active' => true,
    ]);

    AttendanceShiftAssignment::create([
        'attendance_shift_id' => $shift->id,
        'scope_type' => $scope,
        'scope_id' => $scopeId,
        'effective_from' => '2026-09-01',
    ]);

    return $shift;
}

function attendanceProcessor(): AttendanceDayProcessor
{
    return new AttendanceDayProcessor(new AttendanceDayBuilder);
}

/** Employee 10, linked to BioTime by a normal day on Tuesday 8 Sep. */
function linkedEmployeeWithTuesday(): void
{
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-08 08:55:00');
    biotimePunch(2, '1001', '2026-09-08 17:05:00');
    bioTimeSync()->sync(biotimeSource());
}

it('measures the day against the assigned shift', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    $shift = assignShift();
    biotimePunch(1, '1001', '2026-09-09 09:25:00');
    biotimePunch(2, '1001', '2026-09-09 18:00:00');

    bioTimeSync()->sync(biotimeSource());

    $day = AttendanceDay::sole();
    expect($day->attendance_shift_id)->toBe($shift->id)
        ->and($day->scheduled_start->format('H:i'))->toBe('09:00')
        ->and($day->late_minutes)->toBe(25)
        ->and($day->overtime_minutes)->toBe(60)
        ->and($day->early_leave_minutes)->toBe(0)
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_LATE, AttendanceDayBuilder::FLAG_OVERTIME)
        ->and($day->has_error)->toBeFalse();
});

it('records an absence on a work day with no punches, but not on days off', function () {
    assignShift();
    linkedEmployeeWithTuesday();

    attendanceProcessor()->rebuildRange('2026-09-05', '2026-09-09');

    $statuses = AttendanceDay::orderBy('work_date')->get()
        ->mapWithKeys(fn ($d) => [$d->work_date->toDateString() => $d->status])
        ->all();

    // Sat 5th is a day off; Sun–Mon and Wed have no punches.
    expect($statuses)->toBe([
        '2026-09-06' => 'absent',
        '2026-09-07' => 'absent',
        '2026-09-08' => 'present',
        '2026-09-09' => 'absent',
    ]);
});

it('does not keep an absence on a day that becomes a holiday', function () {
    assignShift();
    linkedEmployeeWithTuesday();
    attendanceProcessor()->rebuildRange('2026-09-09', '2026-09-09');
    expect(AttendanceDay::where('work_date', '2026-09-09')->value('status'))->toBe('absent');

    AttendanceHoliday::create(['holiday_date' => '2026-09-09', 'name' => 'National Day']);
    attendanceProcessor()->rebuildRange('2026-09-09', '2026-09-09');

    expect(AttendanceDay::where('work_date', '2026-09-09')->exists())->toBeFalse();
});

it('lets an excuse clear an absence, and brings the absence back when revoked', function () {
    assignShift();
    linkedEmployeeWithTuesday();
    $processor = attendanceProcessor();

    $excuse = AttendanceAdjustment::create([
        'employee_id' => 10, 'work_date' => '2026-09-09', 'excuse' => 'annual_leave', 'reason' => 'Approved leave',
    ]);
    $processor->rebuildDay('emp:10', '2026-09-09');

    $day = AttendanceDay::where('work_date', '2026-09-09')->sole();
    expect($day->status)->toBe('excused')
        ->and($day->excuse)->toBe('annual_leave')
        ->and($day->has_error)->toBeFalse();

    $excuse->forceFill(['revoked_at' => now()])->save();
    $processor->rebuildDay('emp:10', '2026-09-09');

    $day = AttendanceDay::where('work_date', '2026-09-09')->sole();
    expect($day->status)->toBe('absent')
        ->and($day->has_error)->toBeTrue();
});

it('fills a missing check-out from an HR correction without touching the punch', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    assignShift();
    biotimePunch(1, '1001', '2026-09-09 08:58:00');
    bioTimeSync()->sync(biotimeSource());
    expect(AttendanceDay::sole()->flags)->toContain(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT);

    AttendanceAdjustment::create([
        'employee_id' => 10, 'work_date' => '2026-09-09', 'check_out' => '2026-09-09 17:00:00', 'reason' => 'Forgot to punch out',
    ]);
    attendanceProcessor()->rebuildDay('emp:10', '2026-09-09');

    $day = AttendanceDay::sole();
    expect($day->last_out->format('H:i'))->toBe('17:00')
        ->and($day->worked_minutes)->toBe(482)
        ->and($day->flags)->toContain(AttendanceDayBuilder::FLAG_ADJUSTED)
        ->and($day->flags)->not->toContain(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT)
        ->and($day->has_error)->toBeFalse()
        ->and(AttendancePunch::count())->toBe(1);
});

it('keeps an overnight shift as one day across midnight', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    assignShift(['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00', 'off_days' => []]);
    biotimePunch(1, '1001', '2026-09-08 21:58:00');
    biotimePunch(2, '1001', '2026-09-09 06:05:00');

    bioTimeSync()->sync(biotimeSource());

    $night = AttendanceDay::where('work_date', '2026-09-08')->sole();
    expect($night->first_in->format('Y-m-d H:i'))->toBe('2026-09-08 21:58')
        ->and($night->last_out->format('Y-m-d H:i'))->toBe('2026-09-09 06:05')
        ->and($night->worked_minutes)->toBe(487)
        ->and($night->has_error)->toBeFalse()
        ->and($night->punchesQuery()->count())->toBe(2);

    // The 06:05 punch is not counted again on the 9th.
    expect((int) AttendanceDay::where('work_date', '2026-09-09')->value('punch_count'))->toBe(0);
});

it('flags punches after the termination date as an error', function () {
    DB::table('employees')->insert([
        'id' => 30, 'name' => 'Former', 'oracle_emp_no' => '3003', 'branch_id' => 1,
        'status' => 'terminated', 'terminated_date' => '2026-09-05',
    ]);
    biotimePunch(1, '3003', '2026-09-09 09:00:00');
    biotimePunch(2, '3003', '2026-09-09 17:00:00');

    bioTimeSync()->sync(biotimeSource());

    $day = AttendanceDay::sole();
    expect($day->flags)->toContain(AttendanceDayBuilder::FLAG_AFTER_TERMINATION)
        ->and($day->has_error)->toBeTrue();
});

it('warns when someone punches at another branch', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 2); // Cairo
    biotimePunch(1, '1001', '2026-09-09 09:00:00', 'Jeddah HQ'); // a Jeddah terminal
    biotimePunch(2, '1001', '2026-09-09 17:00:00', 'Jeddah HQ');

    bioTimeSync()->sync(biotimeSource());

    $day = AttendanceDay::sole();
    expect($day->flags)->toContain(AttendanceDayBuilder::FLAG_OTHER_BRANCH)
        ->and($day->has_error)->toBeFalse();
});
