<?php

namespace App\Services\People;

use App\Models\Vacation\VacationAbsence;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\MonthlyDay;
use App\Services\Attendance\MonthlyTotals;
use Carbon\CarbonImmutable;

/**
 * A person's year, month by month: the monthly sheet's own totals
 * (MonthlyTotals over the same MonthlyDay list) with Oracle's leave records
 * laid over the days. Pure — it counts what it is given, so the profile can
 * never disagree with the Monthly sheet about attendance.
 *
 * Leave is counted on the attendance calendar: a date is a leave day when the
 * person was due at work that day (a shift work day, not a holiday). With no
 * shift assigned, the book's weekend decides instead.
 *
 * Every work day that has ended without the person present lands in exactly
 * one place:
 *   away — Oracle leave or a business trip covers it, or HR excused it;
 *   absent — recorded absent and nothing covers it;
 *   not recorded — no attendance row and nothing covers it.
 * An absence Oracle covers is also listed in $absentOnLeave, because HR still
 * has to excuse it; a present day inside leave is listed in $presentOnLeave.
 */
final class ProfileYear
{
    /**
     * @param  array<string, ProfileMonth>  $months  keyed Y-m, in order
     * @param  list<array{date: string, type: string, trip: bool, day_id: ?int}>  $absentOnLeave
     * @param  list<array{date: string, type: string, day_id: ?int}>  $presentOnLeave
     */
    public function __construct(
        public readonly array $months,
        public readonly ProfileMonth $total,
        public readonly array $absentOnLeave = [],
        public readonly array $presentOnLeave = [],
    ) {}

    /**
     * @param  list<MonthlyDay>  $days  consecutive dates, e.g. a whole year
     * @param  iterable<VacationAbsence>  $leave  the person's leave records; withdrawn ones are ignored
     * @param  list<int>  $weekend  day-of-week numbers (0 = Sunday) for dates with no shift
     */
    public static function build(array $days, iterable $leave, array $weekend = []): self
    {
        $dates = array_map(fn (MonthlyDay $day) => $day->date, $days);
        $coverage = $dates === [] ? [] : self::coverage($leave, min($dates), max($dates));

        $byMonth = [];
        foreach ($days as $day) {
            $byMonth[substr($day->date, 0, 7)][] = $day;
        }
        ksort($byMonth);

        $months = [];
        $absentOnLeave = [];
        $presentOnLeave = [];

        foreach ($byMonth as $month => $monthDays) {
            $count = ['leave' => 0, 'trip' => 0, 'away' => 0, 'absent' => 0, 'absentOnLeave' => 0, 'notRecorded' => 0];
            $types = [];

            foreach ($monthDays as $day) {
                $leaveRecord = null;
                $tripRecord = null;
                foreach ($coverage[$day->date] ?? [] as $record) {
                    if ($record->isBusinessTrip()) {
                        $tripRecord ??= $record;
                    } else {
                        $leaveRecord ??= $record;
                    }
                }

                $due = $day->isWorkDay()
                    || ($day->kind === MonthlyDay::KIND_NO_SHIFT && ! in_array(CarbonImmutable::parse($day->date)->dayOfWeek, $weekend, true));

                if ($due && $leaveRecord) {
                    $count['leave']++;
                    $types[$leaveRecord->absence_type] = ($types[$leaveRecord->absence_type] ?? 0) + 1;
                }
                if ($due && $tripRecord) {
                    $count['trip']++;
                }

                // Booked leave counts above; a day that has not come yet is nobody's absence.
                if ($day->future) {
                    continue;
                }

                $covering = $leaveRecord ?? $tripRecord;
                $row = $day->day;

                if ($row?->status === AttendanceDayBuilder::STATUS_PRESENT) {
                    if ($leaveRecord) {
                        $presentOnLeave[] = ['date' => $day->date, 'type' => $leaveRecord->absence_type, 'day_id' => $row->id];
                    }
                } elseif ($row?->status === AttendanceDayBuilder::STATUS_ABSENT) {
                    if ($covering) {
                        $count['away']++;
                        $count['absentOnLeave']++;
                        $absentOnLeave[] = ['date' => $day->date, 'type' => $covering->absence_type, 'trip' => $covering->isBusinessTrip(), 'day_id' => $row->id];
                    } else {
                        $count['absent']++;
                    }
                } elseif ($row?->status === AttendanceDayBuilder::STATUS_EXCUSED) {
                    $count['away']++;
                } elseif ($row === null && $day->isWorkDay()) {
                    if ($covering) {
                        $count['away']++;
                    } else {
                        $count['notRecorded']++;
                    }
                }
            }

            arsort($types);

            $months[$month] = new ProfileMonth(
                month: $month,
                attendance: MonthlyTotals::fromDays($monthDays),
                leaveDays: $count['leave'],
                tripDays: $count['trip'],
                awayDays: $count['away'],
                absentDays: $count['absent'],
                absentOnLeaveDays: $count['absentOnLeave'],
                notRecordedDays: $count['notRecorded'],
                leaveByType: $types,
            );
        }

        return new self($months, self::total($months, $days), $absentOnLeave, $presentOnLeave);
    }

    /**
     * Which active leave records cover each date from $from to $to.
     *
     * @param  iterable<VacationAbsence>  $leave
     * @return array<string, list<VacationAbsence>> Y-m-d => records
     */
    public static function coverage(iterable $leave, string $from, string $to): array
    {
        $map = [];

        foreach ($leave as $record) {
            if ($record->removed_at !== null) {
                continue;
            }

            $start = max($record->start_date->toDateString(), $from);
            $end = min($record->end_date->toDateString(), $to);

            for ($date = CarbonImmutable::parse($start); $date->toDateString() <= $end; $date = $date->addDay()) {
                $map[$date->toDateString()][] = $record;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, ProfileMonth>  $months
     * @param  list<MonthlyDay>  $days
     */
    private static function total(array $months, array $days): ProfileMonth
    {
        $sum = fn (string $field) => array_sum(array_map(fn (ProfileMonth $month) => $month->{$field}, $months));

        $types = [];
        foreach ($months as $month) {
            foreach ($month->leaveByType as $type => $count) {
                $types[$type] = ($types[$type] ?? 0) + $count;
            }
        }
        arsort($types);

        return new ProfileMonth(
            month: $months === [] ? '' : substr((string) array_key_first($months), 0, 4),
            attendance: MonthlyTotals::fromDays($days),
            leaveDays: $sum('leaveDays'),
            tripDays: $sum('tripDays'),
            awayDays: $sum('awayDays'),
            absentDays: $sum('absentDays'),
            absentOnLeaveDays: $sum('absentOnLeaveDays'),
            notRecordedDays: $sum('notRecordedDays'),
            leaveByType: $types,
        );
    }
}
