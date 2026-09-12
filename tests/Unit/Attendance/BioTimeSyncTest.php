<?php

use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendanceHoliday;
use App\Models\Attendance\AttendancePeriod;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\AttendanceShift;
use App\Models\Attendance\AttendanceShiftAssignment;
use App\Models\Attendance\AttendanceTask;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Attendance\BiotimeTerminal;
use App\Models\Employee;
use App\Models\NocEvent;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendanceDayProcessor;
use App\Services\Attendance\AttendancePeriodService;
use App\Services\Attendance\BioTimeConnection;
use App\Services\Attendance\BioTimeSyncService;
use App\Services\Attendance\EmployeeLinker;
use App\Services\Attendance\Readers\AccessTransactionReader;
use App\Services\Attendance\Readers\CheckInOutReader;
use App\Services\Attendance\Readers\IclockTransactionReader;
use App\Services\Attendance\Readers\PunchReader;
use App\Services\Attendance\Readers\PunchReaders;
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
    // ZKBio access control: hex string ids, times with milliseconds, no area.
    Schema::connection('biotime_fake')->create('acc_transaction', function (Blueprint $t) {
        $t->string('id')->primary();
        $t->string('create_time');
        $t->string('event_time')->nullable();
        $t->string('dev_alias')->nullable();
        $t->string('dev_id')->nullable();
        $t->string('dev_sn')->nullable();
        $t->string('pin')->nullable();
    });
    // ZKTeco legacy: no id at all, and no employee code — only USERID, which
    // USERINFO turns into a badge number.
    Schema::connection('biotime_fake')->create('CHECKINOUT', function (Blueprint $t) {
        $t->integer('USERID');
        $t->string('CHECKTIME');
        $t->string('CHECKTYPE')->nullable();
        $t->string('VERIFYCODE')->nullable();
        $t->string('SENSORID')->nullable();
        $t->string('sn')->nullable();
    });
    Schema::connection('biotime_fake')->create('USERINFO', function (Blueprint $t) {
        $t->integer('USERID')->primary();
        $t->string('BADGENUMBER')->nullable();
        $t->string('SSN')->nullable();
        $t->string('CardNo')->nullable();
        $t->string('NAME')->nullable();
    });

    foreach (['attendance_exports', 'attendance_periods', 'attendance_tasks', 'attendance_adjustments', 'attendance_holidays', 'attendance_shift_assignments', 'attendance_shifts',
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
    foreach (glob(database_path('migrations/2026_09_1*_*.php')) as $migration) {
        $name = basename($migration);
        if ((str_contains($name, 'biotime') || str_contains($name, 'attendance')) && ! str_contains($name, 'permission')) {
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

    return new BioTimeSyncService($bioTime ?? bioTimeOn('biotime_fake'), new EmployeeLinker($processor), $processor, testReaders());
}

/**
 * The real readers, except that times are compared as the fake database
 * stores them. SQL Server gets 'YYYYMMDD hh:mm:ss' literals; SQLite compares
 * text, which only works in the stored 'YYYY-MM-DD hh:mm:ss' form.
 */
function testReaders(): PunchReaders
{
    return new class extends PunchReaders
    {
        public function for(BiotimeSource $source): PunchReader
        {
            return match ($source->source_type) {
                BiotimeSource::TYPE_ACCESS => new class extends AccessTransactionReader
                {
                    protected function literal(string $time): string
                    {
                        return $time;
                    }
                },
                BiotimeSource::TYPE_CHECKINOUT => new class extends CheckInOutReader
                {
                    protected function literal(string $time): string
                    {
                        return $time;
                    }
                },
                default => new class extends IclockTransactionReader
                {
                    protected function literal(string $time): string
                    {
                        return $time;
                    }
                },
            };
        }
    };
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

// ── ZKBio access control (acc_transaction) ───────────────────────────

function accessSource(array $attributes = []): BiotimeSource
{
    return BiotimeSource::create($attributes + [
        'name' => 'ZKBio Access',
        'source_type' => BiotimeSource::TYPE_ACCESS,
        'time_column' => 'create_time',
        'host' => 'sql.test',
        'port' => 1433,
        'database' => 'zkbiosecurity',
        'username' => 'noc_attendance',
        'password' => 'secret',
        'trust_server_certificate' => true,
        'enabled' => true,
    ]);
}

function accessPunch(string $id, string $time, string $pin, string $alias = 'IN', string $sn = 'CMWD230660072'): void
{
    DB::connection('biotime_fake')->table('acc_transaction')->insert([
        'id' => $id,
        'create_time' => $time,
        'dev_alias' => $alias,
        'dev_id' => '8a8180b18fdb06a6018ffd31f13e0014',
        'dev_sn' => $sn,
        'pin' => $pin,
    ]);
}

it('reads an access-control table: pin is the employee, create_time the punch', function () {
    attendanceEmployee(50, 'Pin 775', '775', 1);
    accessPunch('8a8180b196ccb1a201975cb05722750b', '2025-06-11 04:52:29.987', '775');
    accessPunch('8a8180b196ccb1a201975cb06abe750c', '2025-06-11 04:52:35.007', '775');
    accessPunch('8a8180b196ccb1a201975cb07e53750d', '2025-06-11 04:52:40.020', '775');
    accessPunch('8a8180b196ccb1a201975cf21145750f', '2025-06-11 05:00:00.000', ''); // a door event: no pin
    accessPunch('8a8180b196ccb1a20197604b1a2c7599', '2025-06-11 13:05:00.100', '775', 'Out', 'CMWD230660073');
    $source = accessSource();

    $result = bioTimeSync()->sync($source);

    expect($result['rows'])->toBe(5)
        ->and($result['skipped'])->toBe(1)
        ->and(AttendancePunch::count())->toBe(4);

    $punch = AttendancePunch::orderBy('punch_time')->first();
    expect($punch->external_id)->toBe('8a8180b196ccb1a201975cb05722750b')
        ->and($punch->biotime_id)->toBeNull()
        ->and($punch->terminal_alias)->toBe('IN')
        ->and($punch->terminal_sn)->toBe('CMWD230660072')
        ->and($punch->employee_id)->toBe(50);

    $day = AttendanceDay::sole();
    expect($day->first_in->format('H:i:s'))->toBe('04:52:29')
        ->and($day->last_out->format('H:i:s'))->toBe('13:05:00')
        ->and($day->flags)->toBe([AttendanceDayBuilder::FLAG_DUPLICATES]);

    $source->refresh();
    expect($source->last_time)->toBe('2025-06-11 13:05:00.100')
        ->and($source->last_ref)->toBe('8a8180b196ccb1a20197604b1a2c7599');
});

it('pages through rows that share a timestamp without losing or repeating any', function () {
    attendanceEmployee(50, 'Pin 775', '775', 1);
    foreach ([
        ['a1', '2025-06-11 08:00:00.000'],
        ['a2', '2025-06-11 08:00:05.000'],
        ['a3', '2025-06-11 08:00:05.000'],
        ['a4', '2025-06-11 08:00:05.000'],
        ['a5', '2025-06-11 17:00:00.000'],
    ] as [$id, $time]) {
        accessPunch($id, $time, '775');
    }
    $source = accessSource();
    $sync = bioTimeSync();
    $sync->chunk = 2; // a4's second is split across two pages

    expect($sync->sync($source)['rows'])->toBe(5)
        ->and(AttendancePunch::count())->toBe(5)
        ->and($source->fresh()->last_ref)->toBe('a5');

    expect($sync->sync($source)['rows'])->toBe(0);

    accessPunch('a6', '2025-06-11 17:30:00.000', '775');
    expect($sync->sync($source)['rows'])->toBe(1)
        ->and(AttendancePunch::count())->toBe(6);
});

it('converts a database that stores UTC to the source time zone', function () {
    attendanceEmployee(50, 'Pin 775', '775', 1);
    accessPunch('u1', '2025-06-11 04:52:29.987', '775');
    $source = accessSource(['stores_utc' => true, 'timezone' => 'Asia/Riyadh']);

    bioTimeSync()->sync($source);

    expect(AttendancePunch::sole()->punch_time->format('Y-m-d H:i:s'))->toBe('2025-06-11 07:52:29');
});

it('settles a pin that matches two employees by the terminal branch', function () {
    attendanceEmployee(40, 'Pin 428 (SamirGroup)', '428', 1);
    attendanceEmployee(41, 'Pin 428 (SSS Egypt)', '428', 2);
    $source = accessSource();
    BiotimeTerminal::create(['biotime_source_id' => $source->id, 'terminal_sn' => 'CMWD230660073', 'branch_id' => 2]);
    accessPunch('x1', '2025-06-11 04:22:31.827', '428', 'Out', 'CMWD230660073');

    bioTimeSync()->sync($source);

    $code = BiotimeEmployee::sole();
    expect($code->employee_id)->toBe(41)
        ->and($code->match_method)->toBe(BiotimeEmployee::METHOD_AUTO_BRANCH)
        ->and($code->terminals)->toBe(['CMWD230660073']);
});

it('counts access-control punches per day the same on both sides for reconcile', function () {
    attendanceEmployee(50, 'Pin 775', '775', 1);
    accessPunch('c1', '2025-06-11 08:00:00.000', '775');
    accessPunch('c2', '2025-06-11 17:00:00.000', '775');
    accessPunch('c3', '2025-06-11 17:00:10.000', '');
    accessPunch('c4', '2025-06-12 08:05:00.000', '775');
    $source = accessSource();
    $sync = bioTimeSync();
    $sync->sync($source);

    $remote = $sync->remoteDailyCounts($source->fresh(), '2025-06-10');

    expect($remote)->toBe(['2025-06-11' => 2, '2025-06-12' => 1])
        ->and($sync->localDailyCounts($source, '2025-06-10'))->toBe($remote);
});

// ── ZKTeco legacy (CHECKINOUT + USERINFO) and the code prefix ────────

function legacySource(array $attributes = []): BiotimeSource
{
    return BiotimeSource::create($attributes + [
        'name' => 'ZKTeco Cairo',
        'source_type' => BiotimeSource::TYPE_CHECKINOUT,
        'code_column' => 'BADGENUMBER',
        'host' => 'sql.test',
        'port' => 1433,
        'database' => 'att2000',
        'username' => 'noc_attendance',
        'password' => 'secret',
        'trust_server_certificate' => true,
        'enabled' => true,
    ]);
}

function legacyUser(int $userId, ?string $badge, string $name = 'Cairo Staff'): void
{
    DB::connection('biotime_fake')->table('USERINFO')->insert([
        'USERID' => $userId, 'BADGENUMBER' => $badge, 'SSN' => null, 'CardNo' => null, 'NAME' => $name,
    ]);
}

function legacyPunch(int $userId, string $time, string $type = 'I', string $sn = 'ZK-CAI-01'): void
{
    DB::connection('biotime_fake')->table('CHECKINOUT')->insert([
        'USERID' => $userId, 'CHECKTIME' => $time, 'CHECKTYPE' => $type, 'SENSORID' => '1', 'sn' => $sn,
    ]);
}

it('reads the legacy table, joining USERINFO for the badge number', function () {
    attendanceEmployee(60, 'Mohamed (Cairo)', '55512', 2);
    legacyUser(23, '512', 'Mohamed Fathy');
    legacyPunch(23, '2026-09-09 08:31:00');
    legacyPunch(23, '2026-09-09 17:02:00', 'O');

    $result = bioTimeSync()->sync(legacySource(['code_prefix' => '55', 'default_branch_id' => 2]));

    expect($result['rows'])->toBe(2)->and(AttendancePunch::count())->toBe(2);

    $punch = AttendancePunch::orderBy('punch_time')->first();
    expect($punch->emp_code)->toBe('512')
        ->and($punch->external_id)->toBe('23:20260909083100')
        ->and($punch->biotime_id)->toBeNull()
        ->and($punch->punch_state)->toBe('I')
        ->and($punch->terminal_sn)->toBe('ZK-CAI-01')
        ->and($punch->employee_id)->toBe(60);

    $code = BiotimeEmployee::sole();
    expect($code->employee_id)->toBe(60)
        ->and($code->match_method)->toBe(BiotimeEmployee::METHOD_AUTO)
        ->and($code->device_name)->toBe('Mohamed Fathy')
        ->and($code->device_user_id)->toBe('23');

    $day = AttendanceDay::sole();
    expect($day->first_in->format('H:i'))->toBe('08:31')
        ->and($day->last_out->format('H:i'))->toBe('17:02');
});

it('shows every identity column on the test page, keyed to match the sample rows', function () {
    legacyUser(23, '512', 'Mohamed Fathy');
    legacyPunch(23, '2026-09-09 08:31:00');

    $result = bioTimeSync()->test(legacySource(['code_prefix' => '55']));

    // The page renders one cell per column name, read out of the sample row by
    // that name — so the two must agree, or every cell comes out blank.
    expect($result['columns'])->toBe(['USERID', 'CHECKTIME', 'CHECKTYPE', 'sn', 'BADGENUMBER', 'SSN', 'CardNo', 'NAME'])
        ->and(array_keys($result['sample'][0]))->toBe($result['columns'])
        ->and($result['sample'][0]['BADGENUMBER'])->toBe('512')
        ->and($result['sample'][0]['NAME'])->toBe('Mohamed Fathy')
        ->and($result['locations'])->toBe(['ZK-CAI-01'])
        ->and(implode(' ', $result['notes']))->toContain('55512');
});

it('a code prefix matches the Cairo employee, never the Saudi one holding the bare number', function () {
    attendanceEmployee(70, 'Saudi 512', '512', 1);
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    legacyUser(23, '512');
    legacyPunch(23, '2026-09-09 08:00:00');

    bioTimeSync()->sync(legacySource(['code_prefix' => '55']));

    $code = BiotimeEmployee::with('source')->sole();
    expect($code->employee_id)->toBe(71)
        ->and($code->match_method)->toBe(BiotimeEmployee::METHOD_AUTO)
        // The prefixed form REPLACES the bare one: searching both would match
        // two employees at once and land the code on HR's pile.
        ->and($code->lookupCodes())->toBe(['55512']);
});

it('without a prefix the same badge lands on the Saudi employee — the collision the prefix removes', function () {
    attendanceEmployee(70, 'Saudi 512', '512', 1);
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    legacyUser(23, '512');
    legacyPunch(23, '2026-09-09 08:00:00');

    bioTimeSync()->sync(legacySource());

    expect(BiotimeEmployee::sole()->employee_id)->toBe(70);
});

it('strips leading zeros before the prefix: badge 0512 is Oracle 55512', function () {
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    legacyUser(23, '0512');
    legacyPunch(23, '2026-09-09 08:00:00');

    bioTimeSync()->sync(legacySource(['code_prefix' => '55']));

    expect(BiotimeEmployee::sole()->employee_id)->toBe(71);
});

it('pages a shared CHECKTIME by NUMERIC user id, so the watermark still passes 9 then 10', function () {
    legacyUser(9, '9');
    legacyUser(10, '10');
    legacyPunch(9, '2026-09-09 08:00:00');
    legacyPunch(9, '2026-09-09 17:00:00', 'O');
    legacyPunch(10, '2026-09-09 17:00:00', 'O');
    $source = legacySource();
    $sync = bioTimeSync();
    $sync->chunk = 2; // the crowded second is split across two pages

    // Compared as text '10' sorts BEFORE '9', so the watermark would stop at
    // user 9 and re-read user 10's punch on every sync from then on.
    expect($sync->sync($source)['rows'])->toBe(3)
        ->and(AttendancePunch::count())->toBe(3)
        ->and($source->fresh()->last_ref)->toBe('10');

    expect($sync->sync($source)['rows'])->toBe(0);
});

it('re-reading a day upserts, because the synthetic external_id is stable', function () {
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    legacyUser(23, '512');
    legacyPunch(23, '2026-09-09 08:30:00');
    legacyPunch(23, '2026-09-09 17:00:00', 'O');
    $source = legacySource(['code_prefix' => '55']);
    $sync = bioTimeSync();
    $sync->sync($source);

    $sync->sync($source, '2026-09-09');

    expect(AttendancePunch::count())->toBe(2);
});

it('keeps a punch whose USERINFO row is gone, under uid:{USERID}', function () {
    legacyPunch(77, '2026-09-09 08:00:00');

    bioTimeSync()->sync(legacySource(['code_prefix' => '55']));

    expect(AttendancePunch::sole()->emp_code)->toBe('uid:77')
        ->and(BiotimeEmployee::sole()->match_method)->toBe(BiotimeEmployee::METHOD_NONE);
});

it('re-reads recent days, so a punch a terminal uploaded late is not left behind the watermark', function () {
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    legacyUser(23, '512');
    legacyPunch(23, '2026-09-10 08:30:00');
    $source = legacySource(['code_prefix' => '55', 'lookback_days' => 2]);
    $sync = bioTimeSync();
    $sync->sync($source);
    expect(AttendancePunch::count())->toBe(1);

    // The terminal was offline all morning; this scan arrives now, with the
    // time it really happened — behind the watermark, where only a re-read
    // of the day can find it.
    legacyPunch(23, '2026-09-10 07:55:00');

    // The window is re-read whole, so both rows are counted; they upsert, and
    // only the late one is actually new.
    expect($sync->sync($source)['rows'])->toBe(2)
        ->and(AttendancePunch::count())->toBe(2)
        ->and(AttendanceDay::sole()->first_in->format('H:i'))->toBe('07:55');
});

it('without that lookback the late punch is invisible: the watermark is already past it', function () {
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    legacyUser(23, '512');
    legacyPunch(23, '2026-09-10 08:30:00');
    $source = legacySource(['code_prefix' => '55']); // lookback_days = 0
    $sync = bioTimeSync();
    $sync->sync($source);

    legacyPunch(23, '2026-09-10 07:55:00');

    expect($sync->sync($source)['rows'])->toBe(0)
        ->and(AttendancePunch::count())->toBe(1)
        ->and(AttendanceDay::sole()->first_in->format('H:i'))->toBe('08:30');
});

it('re-matches every automatic code when the prefix changes, and leaves HR\'s alone', function () {
    attendanceEmployee(70, 'Saudi 512', '512', 1);
    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    attendanceEmployee(72, 'Someone HR chose', '513', 1);
    legacyUser(23, '512');
    legacyUser(24, '513');
    legacyPunch(23, '2026-09-09 08:00:00');
    legacyPunch(24, '2026-09-09 08:05:00');
    $source = legacySource();
    bioTimeSync()->sync($source);

    $linker = new EmployeeLinker(new AttendanceDayProcessor(new AttendanceDayBuilder));
    $linker->linkManually(BiotimeEmployee::where('emp_code', '513')->sole(), Employee::find(72), 1);

    $source->forceFill(['code_prefix' => '55'])->save();

    // retryUnlinked() would look at neither: both already have an employee.
    expect($linker->retryUnlinked($source))->toBe(0)
        ->and($linker->rematch($source))->toBe(1);

    expect(BiotimeEmployee::where('emp_code', '512')->sole()->employee_id)->toBe(71)
        ->and(BiotimeEmployee::where('emp_code', '513')->sole()->employee_id)->toBe(72);
});

it('judges a Cairo day by the branch shift — and one with no branch by the company shift', function () {
    assignShift();                                                                  // everyone: 09:00–17:00
    $cairo = assignShift(['name' => 'Cairo', 'start_time' => '08:30'], 'branch', 2); // branch 2: 08:30–17:00

    attendanceEmployee(71, 'Cairo 512', '55512', 2);
    attendanceEmployee(72, 'Cairo 513, branch unset', '55513', null);
    legacyUser(23, '512');
    legacyUser(24, '513');
    foreach ([23, 24] as $userId) {
        legacyPunch($userId, '2026-09-09 08:45:00');
        legacyPunch($userId, '2026-09-09 17:00:00', 'O');
    }

    bioTimeSync()->sync(legacySource(['code_prefix' => '55']));

    $day = AttendanceDay::where('employee_id', 71)->sole();
    expect($day->attendance_shift_id)->toBe($cairo->id)
        ->and($day->scheduled_start->format('H:i'))->toBe('08:30')
        ->and($day->late_minutes)->toBe(15);

    // The branch scope resolves off the EMPLOYEE's branch, never the punch
    // location — so an employee with none silently falls back to the company
    // shift and is measured against 09:00 all month, with nothing flagged.
    $unfiled = AttendanceDay::where('employee_id', 72)->sole();
    expect($unfiled->attendance_shift_id)->not->toBe($cairo->id)
        ->and($unfiled->scheduled_start->format('H:i'))->toBe('09:00')
        ->and($unfiled->late_minutes)->toBe(0);
});

// ── Background work (attendance:work) ────────────────────────────────

it('runs a queued recalculation in the background', function () {
    assignShift();
    linkedEmployeeWithTuesday();
    AttendanceTask::queue('rebuild', ['from' => '2026-09-09', 'to' => '2026-09-09', 'employee_ids' => null], 'Recalculate 9 Sep');

    $this->artisan('attendance:work')->assertSuccessful();

    expect(AttendanceTask::sole()->status)->toBe(AttendanceTask::DONE)
        ->and(AttendanceTask::sole()->result)->toContain('day(s) recalculated')
        ->and(AttendanceDay::where('work_date', '2026-09-09')->value('status'))->toBe('absent');
});

it('queues the same work only once however often it is asked for', function () {
    AttendanceTask::queue('sync', ['source_id' => 1], 'Sync A');
    AttendanceTask::queue('sync', ['source_id' => 1], 'Sync A');
    AttendanceTask::queue('sync', ['source_id' => 2], 'Sync B');

    expect(AttendanceTask::count())->toBe(2);
});

it('runs a queued sync against the source', function () {
    app()->instance(BioTimeConnection::class, bioTimeOn('biotime_fake'));
    app()->instance(PunchReaders::class, testReaders());
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');
    $source = biotimeSource();
    AttendanceTask::queue('sync', ['source_id' => $source->id], 'Sync BioTime KSA');

    $this->artisan('attendance:work')->assertSuccessful();

    expect(AttendanceTask::sole()->status)->toBe(AttendanceTask::DONE)
        ->and(AttendancePunch::count())->toBe(1);
});

it('records a failed task and still runs the next one', function () {
    assignShift();
    linkedEmployeeWithTuesday();
    AttendanceTask::queue('sync', ['source_id' => 999], 'Sync a deleted source');
    AttendanceTask::queue('rebuild', ['from' => '2026-09-09', 'to' => '2026-09-09', 'employee_ids' => null], 'Recalculate 9 Sep');

    $this->artisan('attendance:work')->assertSuccessful();

    $tasks = AttendanceTask::orderBy('id')->get();
    expect($tasks[0]->status)->toBe(AttendanceTask::FAILED)
        ->and($tasks[0]->result)->toBe('That source no longer exists.')
        ->and($tasks[1]->status)->toBe(AttendanceTask::DONE);
});

// ── Phase 3: periods, approval, lock, Oracle export ──────────────────

function attendancePeriod(string $from = '2026-09-08', string $to = '2026-09-09', ?int $branchId = null): AttendancePeriod
{
    return AttendancePeriod::create([
        'name' => 'Test period', 'branch_id' => $branchId, 'date_from' => $from, 'date_to' => $to, 'status' => AttendancePeriod::OPEN,
    ]);
}

/** Employee 10 with a clean 9 Sep: 08:55 to 17:05. */
function cleanNinthOfSeptember(): BiotimeSource
{
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00');
    biotimePunch(2, '1001', '2026-09-09 17:05:00');
    $source = biotimeSource();
    bioTimeSync()->sync($source);

    return $source;
}

it('will not approve a period while a day in it has a data error', function () {
    attendanceEmployee(10, 'Ahmed', '1001', 1);
    biotimePunch(1, '1001', '2026-09-09 08:55:00'); // a lone punch: missing check-out
    bioTimeSync()->sync(biotimeSource());
    $period = attendancePeriod();
    $service = new AttendancePeriodService;

    $readiness = $service->readiness($period);
    expect($readiness['errors'])->toBe(1)
        ->and($readiness['by_flag'])->toBe([AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT => 1])
        ->and($readiness['blockers'])->not->toBeEmpty();

    expect(fn () => $service->approve($period, null))->toThrow(RuntimeException::class);
    expect($period->fresh()->status)->toBe(AttendancePeriod::OPEN)
        ->and(AttendanceDay::sole()->locked)->toBeFalse();
});

it('will not approve a period whose last day is not over', function () {
    cleanNinthOfSeptember();
    $period = attendancePeriod('2026-09-08', '2026-09-10'); // "today" in these tests

    expect((new AttendancePeriodService)->readiness($period)['blockers'])->toHaveCount(1);
});

it('locks approved days: later punches and rebuilds leave them as approved, reopening applies them', function () {
    $source = cleanNinthOfSeptember();
    $period = attendancePeriod();
    $service = new AttendancePeriodService;

    $service->approve($period, null);

    expect(AttendanceDay::sole()->locked)->toBeTrue()
        ->and($period->fresh()->status)->toBe(AttendancePeriod::APPROVED)
        ->and(AttendanceTask::where('type', 'export')->count())->toBe(1);

    // A terminal uploads a late punch after approval (the clock moves on).
    Carbon::setTestNow('2026-09-10 12:05:00');
    CarbonImmutable::setTestNow('2026-09-10 12:05:00');
    biotimePunch(3, '1001', '2026-09-09 19:30:00');
    bioTimeSync()->sync($source);
    attendanceProcessor()->rebuildRange('2026-09-08', '2026-09-09');

    expect(AttendancePunch::count())->toBe(3)
        ->and(AttendanceDay::sole()->last_out->format('H:i'))->toBe('17:05')
        ->and($service->punchesAfterApproval($period->fresh()))->toBe(1);

    $service->reopen($period->fresh(), null, 'Late upload from the IN terminal');
    attendanceProcessor()->rebuildRange('2026-09-08', '2026-09-09');

    expect($period->fresh()->status)->toBe(AttendancePeriod::OPEN)
        ->and(AttendanceDay::sole()->locked)->toBeFalse()
        ->and(AttendanceDay::sole()->last_out->format('H:i'))->toBe('19:30');
});

it('prepares the Oracle payload for an approved period without sending it', function () {
    cleanNinthOfSeptember();
    $period = attendancePeriod();
    $service = new AttendancePeriodService;
    $service->approve($period, null);

    $export = $service->export($period->fresh(), null);

    expect($export->status)->toBe('prepared')
        ->and($export->sender)->toBe('StubOracleAttendanceSender')
        ->and($export->record_count)->toBe(1)
        ->and($period->fresh()->status)->toBe(AttendancePeriod::APPROVED);

    expect(json_decode($export->payload, true)['records'][0])->toMatchArray([
        'oracle_emp_no' => '1001',
        'employee_name' => 'Ahmed',
        'date' => '2026-09-09',
        'status' => 'present',
        'check_in' => '2026-09-09 08:55:00',
        'check_out' => '2026-09-09 17:05:00',
        'worked_minutes' => 490,
        'corrected' => false,
    ]);
});

it('runs a queued export in the background', function () {
    cleanNinthOfSeptember();
    $period = attendancePeriod();
    (new AttendancePeriodService)->approve($period, null);

    $this->artisan('attendance:work')->assertSuccessful();

    expect(AttendanceTask::where('type', 'export')->sole()->status)->toBe(AttendanceTask::DONE)
        ->and($period->exports()->count())->toBe(1);
});

it('finds periods that would cover the same people on the same days', function () {
    DB::table('branches')->insert(['id' => 3, 'name' => 'Riyadh']);
    AttendancePeriod::create(['name' => 'Jeddah Sep', 'branch_id' => 1, 'date_from' => '2026-09-01', 'date_to' => '2026-09-30']);

    expect(AttendancePeriod::overlapping('2026-09-15', '2026-10-15', 1)->exists())->toBeTrue()   // same branch
        ->and(AttendancePeriod::overlapping('2026-09-15', '2026-10-15', null)->exists())->toBeTrue() // all branches
        ->and(AttendancePeriod::overlapping('2026-09-15', '2026-10-15', 3)->exists())->toBeFalse()   // another branch
        ->and(AttendancePeriod::overlapping('2026-10-01', '2026-10-31', 1)->exists())->toBeFalse();  // next month
});
