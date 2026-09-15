<?php

namespace App\Services\People;

use App\Services\Attendance\MonthlyTotals;

/**
 * One month of a person's profile — or, for ProfileYear::$total, the whole
 * year: the monthly sheet's own totals with Oracle's leave laid over them.
 */
final class ProfileMonth
{
    /**
     * @param  string  $month  Y-m, or Y for a year's total
     * @param  int  $leaveDays  work days Oracle leave covers, business trips apart; booked days ahead included
     * @param  int  $tripDays  work days a business trip covers
     * @param  int  $awayDays  work days that have ended, not present: on leave, on a trip, or excused by HR
     * @param  int  $absentDays  absences nothing explains
     * @param  int  $absentOnLeaveDays  absences Oracle has as leave or a trip — HR still has to excuse them
     * @param  int  $notRecordedDays  ended work days with no attendance row and nothing explaining them
     * @param  array<string, int>  $leaveByType  Oracle leave type => work days, most first
     */
    public function __construct(
        public readonly string $month,
        public readonly MonthlyTotals $attendance,
        public readonly int $leaveDays = 0,
        public readonly int $tripDays = 0,
        public readonly int $awayDays = 0,
        public readonly int $absentDays = 0,
        public readonly int $absentOnLeaveDays = 0,
        public readonly int $notRecordedDays = 0,
        public readonly array $leaveByType = [],
    ) {}

    /** Present ÷ (present + absences nothing explains); null before any such day. */
    public function attendanceRate(): ?float
    {
        $due = $this->attendance->presentDays + $this->absentDays;

        return $due > 0 ? $this->attendance->presentDays / $due : null;
    }
}
