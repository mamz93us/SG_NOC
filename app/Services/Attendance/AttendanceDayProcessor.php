<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Attendance\BiotimeArea;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Writes attendance_days from attendance_punches, shifts, holidays and HR
 * corrections. The day table is derived and can be rebuilt at any time; raw
 * punches are never changed here.
 *
 * A subject is `emp:{employee_id}` once a code is linked — so a person who
 * punches on two BioTime databases gets one day — or `bt:{biotime_employee_id}`
 * while the code is unmapped (no shift, no absence: it is not yet anyone).
 */
class AttendanceDayProcessor
{
    private const FORMAT = AttendanceDayBuilder::FORMAT;

    private ShiftResolver $shifts;

    /** @var array<string, ?BiotimeArea> */
    private array $areas = [];

    /** @var array<string, AttendanceAdjustment>|null preloaded for the range being rebuilt */
    private ?array $adjustments = null;

    public function __construct(private AttendanceDayBuilder $builder, ?ShiftResolver $shifts = null)
    {
        $this->shifts = $shifts ?? new ShiftResolver;
    }

    public function shifts(): ShiftResolver
    {
        return $this->shifts;
    }

    /**
     * @param  array<string, array<string, true>>  $touched  subject_key => [Y-m-d => true]
     */
    public function rebuild(array $touched): int
    {
        $count = 0;
        foreach ($this->withPreviousDays($touched) as $subjectKey => $dates) {
            foreach (array_keys($dates) as $date) {
                $this->rebuildDay($subjectKey, (string) $date);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Rebuilds every day in the range — days with punches, and absences for
     * people expected to punch — and drops rows that no longer apply.
     *
     * @param  list<int>|null  $employeeIds  limit to these people (null = everyone, unmapped codes included)
     */
    public function rebuildRange(string $from, string $to, ?array $employeeIds = null): int
    {
        $this->preloadAdjustments($from, $to);
        $written = [];
        $count = 0;

        try {
            // One day past the range: an overnight shift on $to ends on $to + 1.
            $pairs = AttendancePunch::query()
                ->whereBetween('punch_time', [$from.' 00:00:00', CarbonImmutable::parse($to)->addDay()->toDateString().' 23:59:59'])
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->selectRaw('employee_id, biotime_employee_id, DATE(punch_time) as d')
                ->groupByRaw('employee_id, biotime_employee_id, DATE(punch_time)')
                ->get();

            $touched = [];
            foreach ($pairs as $pair) {
                $key = $pair->employee_id ? 'emp:'.$pair->employee_id : 'bt:'.$pair->biotime_employee_id;
                $touched[$key][(string) $pair->d] = true;
            }
            $touched = $this->withPreviousDays($touched);

            foreach ($touched as $subjectKey => $dates) {
                foreach (array_keys($dates) as $date) {
                    if ($this->rebuildDay($subjectKey, (string) $date)) {
                        $written[$subjectKey.'|'.$date] = true;
                    }
                    $count++;
                }
            }

            $count += $this->noPunchDays($from, $to, $touched, $employeeIds, $written);
        } finally {
            $this->adjustments = null;
        }

        // Anything in the range this run did not write no longer applies — an
        // absence on what is now a holiday, an unmapped code's days after it
        // was linked to someone.
        $stale = [];
        AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->select(['id', 'subject_key', 'work_date'])
            ->chunkById(1000, function ($rows) use (&$stale, $written) {
                foreach ($rows as $row) {
                    if (! isset($written[$row->subject_key.'|'.$row->work_date->toDateString()])) {
                        $stale[] = $row->id;
                    }
                }
            });

        foreach (array_chunk($stale, 1000) as $ids) {
            AttendanceDay::whereIn('id', $ids)->delete();
        }

        return $count;
    }

    /**
     * After a code's employee link changed: re-point its punches and rebuild
     * every day it touches, under the old owner, the new owner and itself.
     */
    public function relink(BiotimeEmployee $biotimeEmployee, ?int $oldEmployeeId): void
    {
        AttendancePunch::where('biotime_employee_id', $biotimeEmployee->id)
            ->update(['employee_id' => $biotimeEmployee->employee_id]);

        $dates = AttendancePunch::where('biotime_employee_id', $biotimeEmployee->id)
            ->selectRaw('DATE(punch_time) as d')
            ->distinct()
            ->pluck('d')
            ->map(fn ($d) => (string) $d)
            ->all();

        $subjects = array_unique(array_filter([
            'bt:'.$biotimeEmployee->id,
            $oldEmployeeId ? 'emp:'.$oldEmployeeId : null,
            $biotimeEmployee->employee_id ? 'emp:'.$biotimeEmployee->employee_id : null,
        ]));

        $touched = [];
        foreach ($subjects as $subjectKey) {
            foreach ($dates as $date) {
                $touched[$subjectKey][$date] = true;
            }
        }

        // Who is linked, and to which codes, just changed.
        $this->shifts->forget();
        $this->rebuild($touched);
    }

    /**
     * @param  Collection<int, AttendancePunch>|null  $punches  pass an empty collection when the
     *                                                          caller already knows there are none
     * @return bool whether a row exists for the day afterwards
     */
    public function rebuildDay(string $subjectKey, string $date, ?Collection $punches = null): bool
    {
        [$type, $id] = explode(':', $subjectKey, 2);
        $id = (int) $id;
        $isEmployee = $type === 'emp';

        $shift = $isEmployee ? $this->shifts->forEmployee($id, $date) : null;
        $previousShift = $isEmployee
            ? $this->shifts->forEmployee($id, CarbonImmutable::parse($date)->subDay()->toDateString())
            : null;

        [$windowStart, $windowEnd] = AttendanceDayBuilder::window($date, $shift, $previousShift);
        $windowStart = $windowStart->format(self::FORMAT);
        $windowEnd = $windowEnd->format(self::FORMAT);

        $knownEmpty = $punches !== null;
        if ($punches === null) {
            $query = AttendancePunch::query()
                ->where('punch_time', '>=', $windowStart)
                ->where('punch_time', '<', $windowEnd);
            $isEmployee
                ? $query->where('employee_id', $id)
                : $query->where('biotime_employee_id', $id)->whereNull('employee_id');

            $punches = $query->orderBy('punch_time')
                ->get(['id', 'biotime_source_id', 'emp_code', 'punch_time', 'area_alias']);
        }

        $employee = $isEmployee ? $this->shifts->employee($id) : null;
        $adjustment = $isEmployee ? $this->adjustment($id, $date) : null;
        $first = $punches->first();
        $area = $first ? $this->area((int) $first->biotime_source_id, $first->area_alias) : null;
        $employeeBranch = $employee?->branch_id ? (int) $employee->branch_id : null;

        $extraFlags = $isEmployee
            ? $this->employeeFlags($employee, $date, $punches)
            : [BiotimeEmployee::find($id)?->isConfirmedNotEmployee()
                ? AttendanceDayBuilder::FLAG_NOT_EMPLOYEE
                : AttendanceDayBuilder::FLAG_UNMAPPED];

        $context = new DayContext(
            shift: $shift,
            holiday: $isEmployee ? $this->shifts->holidayFor($employeeBranch ?? $area?->branch_id, $date) : null,
            expectedToWork: $isEmployee && $this->shifts->expectedToWork($id, $date),
            checkIn: $adjustment?->check_in?->format(self::FORMAT),
            checkOut: $adjustment?->check_out?->format(self::FORMAT),
            excuse: $adjustment?->excuse,
        );

        $result = $this->builder->build(
            $date,
            $punches->map(fn (AttendancePunch $p) => $p->punch_time->format(self::FORMAT))->all(),
            $this->wallNow($area?->timezone),
            $extraFlags,
            $context,
        );

        if ($result->status === AttendanceDayBuilder::STATUS_NONE) {
            if (! $knownEmpty) {
                AttendanceDay::where('subject_key', $subjectKey)->where('work_date', $date)->delete();
            }

            return false;
        }

        $codes = $punches->isNotEmpty()
            ? $punches->pluck('emp_code')->unique()->implode(', ')
            : ($isEmployee ? $this->shifts->codesFor($id) : '');

        AttendanceDay::updateOrCreate(
            ['subject_key' => $subjectKey, 'work_date' => $date],
            [
                'status' => $result->status,
                'employee_id' => $isEmployee ? $id : null,
                'biotime_employee_id' => $isEmployee ? null : $id,
                'branch_id' => $employeeBranch ?? $area?->branch_id,
                'attendance_shift_id' => $shift?->id,
                'emp_codes' => mb_substr($codes, 0, 150) ?: null,
                'scheduled_start' => $result->scheduledStart,
                'scheduled_end' => $result->scheduledEnd,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                'first_in' => $result->firstIn,
                'last_out' => $result->lastOut,
                'punch_count' => min($result->punchCount, 65535),
                'worked_minutes' => $result->workedMinutes,
                'late_minutes' => $result->lateMinutes,
                'early_leave_minutes' => $result->earlyLeaveMinutes,
                'overtime_minutes' => $result->overtimeMinutes,
                'excuse' => $result->excuse,
                'attendance_adjustment_id' => $adjustment?->id,
                'flags' => $result->flags,
                'has_error' => $result->hasError(),
                'computed_at' => now(),
            ]
        );

        return true;
    }

    /**
     * Absences (and excused days) for linked people with no punches on a day.
     * Only days up to today — the builder itself waits for the shift to end.
     *
     * @param  array<string, array<string, true>>  $touched  days already rebuilt from punches
     * @param  array<string, true>  $written
     */
    private function noPunchDays(string $from, string $to, array $touched, ?array $employeeIds, array &$written): int
    {
        $last = min($to, CarbonImmutable::today()->toDateString());
        $ids = $this->shifts->linkedEmployeeIds();
        if ($employeeIds !== null) {
            $ids = array_values(array_intersect($ids, array_map('intval', $employeeIds)));
        }

        if ($ids === [] || $from > $last) {
            return 0;
        }

        $this->shifts->preloadEmployees($ids);
        $none = collect();
        $count = 0;

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $last; $day = $day->addDay()) {
            $date = $day->toDateString();

            foreach ($ids as $id) {
                $key = 'emp:'.$id;
                if (isset($touched[$key][$date])) {
                    continue;
                }

                // Safe to skip the punch query: any punch in this day's window
                // would have put the day in $touched.
                if ($this->rebuildDay($key, $date, $none)) {
                    $written[$key.'|'.$date] = true;
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * A punch just after midnight can belong to the previous day's overnight
     * shift, so with any overnight shift in use each touched day also
     * rebuilds the day before it.
     *
     * @param  array<string, array<string, true>>  $touched
     * @return array<string, array<string, true>>
     */
    private function withPreviousDays(array $touched): array
    {
        if (! $this->shifts->hasNightShifts()) {
            return $touched;
        }

        foreach ($touched as $subjectKey => $dates) {
            if (! str_starts_with($subjectKey, 'emp:')) {
                continue;
            }
            foreach (array_keys($dates) as $date) {
                $touched[$subjectKey][CarbonImmutable::parse((string) $date)->subDay()->toDateString()] = true;
            }
        }

        return $touched;
    }

    /** @return list<string> */
    private function employeeFlags(?Employee $employee, string $date, Collection $punches): array
    {
        if (! $employee || $punches->isEmpty()) {
            return [];
        }

        $flags = [];

        if ($employee->hired_date && $date < $employee->hired_date->toDateString()) {
            $flags[] = AttendanceDayBuilder::FLAG_BEFORE_HIRE;
        }
        if ($employee->terminated_date && $date > $employee->terminated_date->toDateString()) {
            $flags[] = AttendanceDayBuilder::FLAG_AFTER_TERMINATION;
        }

        if ($employee->branch_id) {
            foreach ($punches as $punch) {
                $branch = $this->area((int) $punch->biotime_source_id, $punch->area_alias)?->branch_id;
                if ($branch && $branch !== (int) $employee->branch_id) {
                    $flags[] = AttendanceDayBuilder::FLAG_OTHER_BRANCH;
                    break;
                }
            }
        }

        return $flags;
    }

    private function preloadAdjustments(string $from, string $to): void
    {
        $this->adjustments = [];

        AttendanceAdjustment::query()
            ->whereNull('revoked_at')
            ->whereBetween('work_date', [CarbonImmutable::parse($from)->subDay()->toDateString(), CarbonImmutable::parse($to)->addDay()->toDateString()])
            ->orderBy('id')
            ->get()
            ->each(function (AttendanceAdjustment $adjustment) {
                $this->adjustments[$adjustment->employee_id.'|'.$adjustment->work_date->toDateString()] = $adjustment;
            });
    }

    private function adjustment(int $employeeId, string $date): ?AttendanceAdjustment
    {
        if ($this->adjustments !== null) {
            return $this->adjustments[$employeeId.'|'.$date] ?? null;
        }

        return AttendanceAdjustment::query()
            ->where('employee_id', $employeeId)
            ->where('work_date', $date)
            ->whereNull('revoked_at')
            ->latest('id')
            ->first();
    }

    /**
     * "Now" on the device's wall clock, as a zone-less time comparable with
     * punch_time (which is parsed in the app's default zone, like this).
     */
    private function wallNow(?string $timezone): CarbonImmutable
    {
        $timezone = $timezone ?: config('app.timezone');

        return CarbonImmutable::parse(CarbonImmutable::now($timezone)->format(self::FORMAT));
    }

    private function area(int $sourceId, ?string $alias): ?BiotimeArea
    {
        if ($alias === null || $alias === '') {
            return null;
        }

        $key = $sourceId.'|'.$alias;
        if (! array_key_exists($key, $this->areas)) {
            $this->areas[$key] = BiotimeArea::where('biotime_source_id', $sourceId)->where('area_alias', $alias)->first();
        }

        return $this->areas[$key];
    }
}
