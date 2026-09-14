<?php

namespace App\Services\Vacation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * How many days a leave record covers: every calendar day, and the days that
 * are not the book's weekend. Pure, so it is tested without a database.
 *
 * Public holidays are not taken out — the NOC does not hold Oracle's holiday
 * calendar — so a record spanning Eid can show more work days than Oracle
 * deducted. Oracle's balance is the figure that counts.
 */
final class VacationDays
{
    /**
     * @param  list<int>  $weekend  Carbon day-of-week numbers, 0 = Sunday … 6 = Saturday
     * @return array{calendar: int, work: int}
     */
    public static function count(CarbonImmutable $start, CarbonImmutable $end, array $weekend): array
    {
        $weekend = array_values(array_unique(array_map('intval', $weekend)));
        foreach ($weekend as $day) {
            if ($day < 0 || $day > 6) {
                throw new InvalidArgumentException("A weekend day must be 0 (Sunday) to 6 (Saturday), not {$day}.");
            }
        }

        $start = $start->startOfDay();
        $end = $end->startOfDay();

        if ($end->lt($start)) {
            return ['calendar' => 0, 'work' => 0];
        }

        $calendar = $start->diff($end)->days + 1;

        // Whole weeks hold every weekday once; only the days left over need looking at.
        $work = intdiv($calendar, 7) * (7 - count($weekend));
        for ($offset = 0; $offset < $calendar % 7; $offset++) {
            if (! in_array(($start->dayOfWeek + $offset) % 7, $weekend, true)) {
                $work++;
            }
        }

        return ['calendar' => $calendar, 'work' => $work];
    }
}
