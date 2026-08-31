<?php

namespace App\Services\Home;

use Carbon\CarbonImmutable;

/**
 * Days until the next payday, for the ring on the "My Payroll" card.
 *
 * This is a countdown and nothing more — no salary figure is read, shown or
 * stored. The rule comes from config/home_portal.php: either a fixed day of the
 * month, or the last working day (Sun–Thu, the Egypt/KSA week).
 */
class PaydayCalculator
{
    /**
     * @return array{date:CarbonImmutable, days_left:int, cycle_days:int, progress:float}
     */
    public function next(?CarbonImmutable $from = null): array
    {
        $today = ($from ?? CarbonImmutable::now())->startOfDay();

        $next = $this->paydayIn($today);

        // Today's payday still counts as today (0 days left); once it is past,
        // roll to next month.
        if ($next->lt($today)) {
            $next = $this->paydayIn($today->addMonthNoOverflow()->startOfMonth());
        }

        $previous = $this->paydayIn($next->subMonthNoOverflow()->startOfMonth());

        $daysLeft = (int) $today->diffInDays($next, false);
        $cycleDays = max(1, (int) $previous->diffInDays($next, false));
        $elapsed = $cycleDays - $daysLeft;

        return [
            'date' => $next,
            'days_left' => max(0, $daysLeft),
            'cycle_days' => $cycleDays,
            // 0..1 through the pay cycle, for the ring's stroke-dashoffset.
            'progress' => max(0.0, min(1.0, $elapsed / $cycleDays)),
        ];
    }

    /** The payday falling in the month of $ref. */
    private function paydayIn(CarbonImmutable $ref): CarbonImmutable
    {
        if ((bool) config('home_portal.payday.last_working_day', false)) {
            return $this->lastWorkingDay($ref);
        }

        $day = (int) config('home_portal.payday.day', 25);
        $day = max(1, min(31, $day));

        // A 31st payday in February has to land somewhere — the last day of
        // that month is the only sensible reading.
        return $ref->startOfMonth()->addDays(min($day, $ref->daysInMonth) - 1);
    }

    /**
     * Last Sunday–Thursday of the month. Friday and Saturday are the weekend
     * across the group's offices, so salaries land before them.
     */
    private function lastWorkingDay(CarbonImmutable $ref): CarbonImmutable
    {
        $day = $ref->endOfMonth()->startOfDay();

        while (in_array($day->dayOfWeek, [CarbonImmutable::FRIDAY, CarbonImmutable::SATURDAY], true)) {
            $day = $day->subDay();
        }

        return $day;
    }
}
