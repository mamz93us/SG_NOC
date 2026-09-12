<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDay;

/**
 * A month added up for one person. Pure — it counts a list of MonthlyDay and
 * touches nothing else, so every figure on the sheet is covered by
 * tests/Unit/Attendance/MonthlySheetTest.php.
 *
 * Worked minutes are check-in to check-out, so a day with a missing check-out
 * contributes nothing to the total: it is a data error to fix, not zero hours
 * worked. `missingCheckOuts` is what says how much of the total is unreliable.
 */
final class MonthlyTotals
{
    public function __construct(
        public readonly int $workDays = 0,
        public readonly int $presentDays = 0,
        public readonly int $absentDays = 0,
        public readonly int $excusedDays = 0,
        public readonly int $offDays = 0,
        public readonly int $holidayDays = 0,
        public readonly int $workedMinutes = 0,
        public readonly int $overtimeMinutes = 0,
        public readonly int $lateDays = 0,
        public readonly int $lateMinutes = 0,
        public readonly int $earlyLeaveDays = 0,
        public readonly int $earlyLeaveMinutes = 0,
        public readonly int $missingCheckOuts = 0,
        public readonly int $punches = 0,
        public readonly int $errorDays = 0,
        public readonly int $workedOffDays = 0,
        public readonly int $unrecordedDays = 0,
    ) {}

    /** @param  list<MonthlyDay>  $days */
    public static function fromDays(array $days): self
    {
        $t = [
            'workDays' => 0, 'presentDays' => 0, 'absentDays' => 0, 'excusedDays' => 0,
            'offDays' => 0, 'holidayDays' => 0, 'workedMinutes' => 0, 'overtimeMinutes' => 0,
            'lateDays' => 0, 'lateMinutes' => 0, 'earlyLeaveDays' => 0, 'earlyLeaveMinutes' => 0,
            'missingCheckOuts' => 0, 'punches' => 0, 'errorDays' => 0, 'workedOffDays' => 0,
            'unrecordedDays' => 0,
        ];

        foreach ($days as $day) {
            // A date that has not arrived is not a day off owed or a day worked.
            if (! $day->future) {
                match ($day->kind) {
                    MonthlyDay::KIND_WORK => $t['workDays']++,
                    MonthlyDay::KIND_OFF => $t['offDays']++,
                    MonthlyDay::KIND_HOLIDAY => $t['holidayDays']++,
                    default => null,
                };
            }

            $t['punches'] += $day->punches->count();

            $row = $day->day;
            if (! $row) {
                // A work day that has ended with no row at all: either the
                // absence has not been written yet — `attendance:process` runs
                // hourly — or the person does not punch on BioTime.
                if ($day->isWorkDay() && ! $day->future) {
                    $t['unrecordedDays']++;
                }

                continue;
            }

            match ($row->status) {
                AttendanceDayBuilder::STATUS_PRESENT => $t['presentDays']++,
                AttendanceDayBuilder::STATUS_ABSENT => $t['absentDays']++,
                AttendanceDayBuilder::STATUS_EXCUSED => $t['excusedDays']++,
                default => null,
            };

            $t['workedMinutes'] += (int) $row->worked_minutes;
            $t['overtimeMinutes'] += (int) $row->overtime_minutes;
            $t['lateMinutes'] += (int) $row->late_minutes;
            $t['earlyLeaveMinutes'] += (int) $row->early_leave_minutes;

            if ($row->late_minutes > 0) {
                $t['lateDays']++;
            }
            if ($row->early_leave_minutes > 0) {
                $t['earlyLeaveDays']++;
            }
            if ($row->has_error) {
                $t['errorDays']++;
            }
            if ($day->has(AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT)) {
                $t['missingCheckOuts']++;
            }
            if ($day->has(AttendanceDayBuilder::FLAG_WORKED_OFF_DAY) || $day->has(AttendanceDayBuilder::FLAG_WORKED_HOLIDAY)) {
                $t['workedOffDays']++;
            }
        }

        return new self(...$t);
    }

    /** 162:45 — hours run past 24, so h:mm, never a time of day. */
    public function workedLabel(): string
    {
        return AttendanceDay::hoursLabel($this->workedMinutes);
    }

    public function overtimeLabel(): string
    {
        return AttendanceDay::hoursLabel($this->overtimeMinutes);
    }
}
