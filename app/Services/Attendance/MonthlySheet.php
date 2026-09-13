<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One person's whole month: every calendar date, what their shift said about
 * it, what attendance_days recorded, and the raw punches behind it.
 *
 * Reads only — it never writes or recomputes anything. A figure that looks
 * wrong here is fixed by rebuilding the days (Check-in / Check-out → Rebuild
 * days), not by changing this.
 *
 * Punches are bucketed by each day's WINDOW, not by calendar date, so an
 * overnight shift's 02:00 check-out stays on the day it belongs to. The window
 * stored on the row is preferred over a fresh one: it is what the day was
 * actually built from, so the sheet and the day page never disagree.
 */
class MonthlySheet
{
    public function __construct(private ShiftResolver $shifts) {}

    /**
     * @param  string  $month  Y-m
     * @return list<MonthlyDay>
     */
    public function build(Employee $employee, string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');
        $end = $start->endOfMonth();
        $today = CarbonImmutable::today()->toDateString();
        $branchId = $employee->branch_id ? (int) $employee->branch_id : null;

        $rows = AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->with('shift:id,name,start_time,end_time')
            ->get()
            ->keyBy(fn (AttendanceDay $d) => $d->work_date->toDateString());

        // Pass 1: the schedule and the punch window for each date.
        $dates = [];
        $previousShift = $this->shifts->forEmployee($employee->id, $start->subDay()->toDateString());

        for ($day = $start; $day->toDateString() <= $end->toDateString(); $day = $day->addDay()) {
            $date = $day->toDateString();
            $shift = $this->shifts->forEmployee($employee->id, $date);
            $row = $rows->get($date);

            [$windowStart, $windowEnd] = AttendanceDayBuilder::window($date, $shift, $previousShift);

            $dates[$date] = [
                'shift' => $shift,
                'row' => $row,
                // The row's branch is the one the day was judged against — the
                // employee's, or the branch of the area they punched in when
                // they have none. Egypt and KSA keep different holidays.
                'holiday' => $this->shifts->holidayFor($row?->branch_id ?? $branchId, $date),
                'from' => $row?->window_start?->format(AttendanceDayBuilder::FORMAT) ?? $windowStart->format(AttendanceDayBuilder::FORMAT),
                'to' => $row?->window_end?->format(AttendanceDayBuilder::FORMAT) ?? $windowEnd->format(AttendanceDayBuilder::FORMAT),
            ];

            $previousShift = $shift;
        }

        $punches = $this->punches($employee, $dates);

        // Pass 2: one MonthlyDay per date.
        $days = [];
        foreach ($dates as $date => $meta) {
            $days[] = new MonthlyDay(
                date: $date,
                kind: $this->kind($employee, $date, $meta['shift'], $meta['holiday']),
                day: $meta['row'],
                punches: $punches->filter(fn (AttendancePunch $p) => $this->inWindow($p, $meta['from'], $meta['to']))->values(),
                shift: $meta['shift'],
                holiday: $meta['holiday'],
                future: $date > $today,
            );
        }

        return $days;
    }

    /**
     * Several people's dates from $from to $to, for adding up: one query for
     * all of their attendance_days rows, and no punches.
     *
     * Every MonthlyTotals figure but `punches` comes from the day rows and the
     * calendar, so an overview of a team, a branch or the whole company needs
     * no punch at all. Building each person's whole month with build() instead
     * took 10 s for the 566 people on NOC2 (2026-09-13). Each date's kind and
     * row are exactly what build() gives; only `punches` is empty.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, list<MonthlyDay>> keyed by employee id
     */
    public function daysWithoutPunches(Collection $employees, string $from, string $to): array
    {
        $today = CarbonImmutable::today()->toDateString();

        $rows = AttendanceDay::query()
            ->whereIn('employee_id', $employees->pluck('id')->all())
            ->whereBetween('work_date', [$from, $to])
            ->with('shift:id,name,start_time,end_time')
            ->get()
            ->groupBy('employee_id');

        $result = [];

        foreach ($employees as $employee) {
            $mine = $rows->get($employee->id, collect())->keyBy(fn (AttendanceDay $d) => $d->work_date->toDateString());
            $branchId = $employee->branch_id ? (int) $employee->branch_id : null;
            $days = [];

            for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
                $date = $day->toDateString();
                $shift = $this->shifts->forEmployee($employee->id, $date);
                $row = $mine->get($date);
                $holiday = $this->shifts->holidayFor($row?->branch_id ?? $branchId, $date);

                $days[] = new MonthlyDay(
                    date: $date,
                    kind: $this->kind($employee, $date, $shift, $holiday),
                    day: $row,
                    punches: collect(),
                    shift: $shift,
                    holiday: $holiday,
                    future: $date > $today,
                );
            }

            $result[(int) $employee->id] = $days;
        }

        return $result;
    }

    /**
     * Every punch the month's windows can reach, in one query — an overnight
     * shift on the last day reaches into the next month.
     *
     * @param  array<string, array{from: string, to: string, ...}>  $dates
     * @return Collection<int, AttendancePunch>
     */
    private function punches(Employee $employee, array $dates): Collection
    {
        if ($dates === []) {
            return collect();
        }

        $from = min(array_column($dates, 'from'));
        $to = max(array_column($dates, 'to'));

        return AttendancePunch::query()
            ->where('employee_id', $employee->id)
            ->where('punch_time', '>=', $from)
            ->where('punch_time', '<', $to)
            ->with('source:id,name')
            ->orderBy('punch_time')
            ->orderBy('id')
            ->get();
    }

    private function inWindow(AttendancePunch $punch, string $from, string $to): bool
    {
        $time = $punch->punch_time->format(AttendanceDayBuilder::FORMAT);

        return $time >= $from && $time < $to;
    }

    /** What the calendar said about this date, before any punch is considered. */
    private function kind(Employee $employee, string $date, ?ShiftRule $shift, ?string $holiday): string
    {
        if ($employee->hired_date && $date < $employee->hired_date->toDateString()) {
            return MonthlyDay::KIND_BEFORE_HIRE;
        }

        if ($employee->terminated_date && $date > $employee->terminated_date->toDateString()) {
            return MonthlyDay::KIND_AFTER_TERMINATION;
        }

        if ($holiday !== null) {
            return MonthlyDay::KIND_HOLIDAY;
        }

        if ($shift === null) {
            return MonthlyDay::KIND_NO_SHIFT;
        }

        return $shift->isOffDay($date) ? MonthlyDay::KIND_OFF : MonthlyDay::KIND_WORK;
    }
}
