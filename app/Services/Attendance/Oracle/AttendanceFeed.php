<?php

namespace App\Services\Attendance\Oracle;

use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendancePunch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendancePeriodService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What Oracle pulls through GET /api/attendance: one record per employee per
 * day, as attendance_days holds it, with the raw punches behind it.
 *
 * Reads only. Every figure comes off the day row and nothing is recomputed,
 * so the API, the day page, the monthly sheet and the period export cannot
 * disagree. A record is the export's (AttendancePeriodService::record) plus
 * what a live read needs and an approved export does not: whether the day is
 * approved yet, its flags, and its punches.
 *
 * Punches are bucketed by each day's stored WINDOW, not by calendar date, so
 * an overnight shift's 06:00 check-out stays on the day it belongs to — the
 * rule AttendanceDay::punchesQuery() and MonthlySheet follow.
 */
class AttendanceFeed
{
    /** Days Oracle can take: linked to an employee who has an Oracle number. Ordered, so pages are stable. */
    public function days(string $from, string $to): Builder
    {
        return AttendanceDay::query()
            ->whereBetween('work_date', [$from, $to])
            ->whereIn('employee_id', $this->withOracleNumber())
            ->with(['employee:id,name,oracle_emp_no', 'shift:id,name', 'branch:id,name'])
            ->orderBy('work_date')
            ->orderBy('employee_id');
    }

    /**
     * Days in the range Oracle cannot take, counted so a missing person is
     * explained rather than silently absent.
     *
     * @return array{days_without_oracle_number: int, days_not_linked_to_an_employee: int}
     */
    public function excluded(string $from, string $to): array
    {
        $inRange = fn () => AttendanceDay::query()->whereBetween('work_date', [$from, $to]);

        return [
            'days_without_oracle_number' => $inRange()
                ->whereNotNull('employee_id')
                ->whereNotIn('employee_id', $this->withOracleNumber())
                ->count(),
            // A fingerprint code HR has not mapped yet, or confirmed is not an employee.
            'days_not_linked_to_an_employee' => $inRange()->whereNull('employee_id')->count(),
        ];
    }

    /**
     * @param  iterable<AttendanceDay>  $days  with employee, shift and branch loaded
     * @return list<array<string, mixed>>
     */
    public function records(iterable $days): array
    {
        $days = collect($days)->values();
        $punches = $this->punchesByDay($days);

        return $days->map(fn (AttendanceDay $day) => $this->record($day, $punches[$day->id] ?? []))->all();
    }

    /**
     * @param  list<AttendancePunch>  $punches  the day's, by time
     * @return array<string, mixed>
     */
    public function record(AttendanceDay $day, array $punches): array
    {
        // employee_id first: unlike oracle_emp_no, it is unique to one person.
        return ['employee_id' => $day->employee_id]
            + AttendancePeriodService::record($day)
            + [
                'approved' => (bool) $day->locked,
                'has_error' => (bool) $day->has_error,
                'flags' => array_values($day->flags ?? []),
                'punches' => array_map(fn (AttendancePunch $p) => [
                    'time' => $p->punch_time->format(AttendanceDayBuilder::FORMAT),
                    'state' => $p->punch_state,
                    'state_label' => ($label = $p->stateLabel()) === '—' ? null : $label,
                    'terminal' => $p->terminal_alias ?: $p->terminal_sn,
                    'area' => $p->area_alias,
                ], $punches),
            ];
    }

    /**
     * Every day's punches in one query: all punches of the days' employees
     * between the earliest window start and the latest window end, each then
     * handed to the day whose window holds it.
     *
     * @param  Collection<int, AttendanceDay>  $days
     * @return array<int, list<AttendancePunch>> day id => punches, by time
     */
    private function punchesByDay(Collection $days): array
    {
        if ($days->isEmpty()) {
            return [];
        }

        $windows = $days->mapWithKeys(fn (AttendanceDay $d) => [$d->id => $d->window()]);

        $punches = AttendancePunch::query()
            ->whereIn('employee_id', $days->pluck('employee_id')->unique()->values()->all())
            ->where('punch_time', '>=', $windows->min(fn (array $w) => $w[0]))
            ->where('punch_time', '<', $windows->max(fn (array $w) => $w[1]))
            ->orderBy('punch_time')
            ->orderBy('id')
            ->get(['id', 'employee_id', 'punch_time', 'punch_state', 'terminal_sn', 'terminal_alias', 'area_alias'])
            ->groupBy('employee_id');

        $byDay = [];

        foreach ($days->groupBy('employee_id') as $employeeId => $theirDays) {
            $theirs = $punches->get($employeeId, collect())->values()->all();
            $times = array_map(fn (AttendancePunch $p) => $p->punch_time->format(AttendanceDayBuilder::FORMAT), $theirs);
            $count = count($times);
            $start = 0;

            // By date, window starts only move forward, so one pass serves every
            // day. Each day still reads on to its own window end rather than
            // consuming punches, exactly as its day page reads them.
            foreach ($theirDays->sortBy(fn (AttendanceDay $d) => $d->work_date->toDateString()) as $day) {
                [$from, $to] = $windows[$day->id];

                while ($start < $count && $times[$start] < $from) {
                    $start++;
                }

                $byDay[$day->id] = [];
                for ($i = $start; $i < $count && $times[$i] < $to; $i++) {
                    $byDay[$day->id][] = $theirs[$i];
                }
            }
        }

        return $byDay;
    }

    private function withOracleNumber(): Builder
    {
        return Employee::query()
            ->select('id')
            ->whereNotNull('oracle_emp_no')
            ->where('oracle_emp_no', '!=', '');
    }
}
