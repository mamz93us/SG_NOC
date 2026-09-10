<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;

/**
 * The parts of a shift the day builder needs, as a plain value — so the rules
 * can be unit tested without a database. Built from AttendanceShift::toRule().
 *
 * Times are wall-clock 'H:i'. A shift whose end is not after its start
 * (22:00–06:00) runs overnight and ends the next day.
 */
final class ShiftRule
{
    /**
     * @param  list<int>  $offDays  ISO weekdays: 1 = Monday … 7 = Sunday
     */
    public function __construct(
        public readonly string $start,
        public readonly string $end,
        public readonly int $graceIn = 0,
        public readonly int $graceOut = 0,
        public readonly ?int $maxMinutes = AttendanceDayBuilder::DEFAULT_MAX_MINUTES,
        public readonly int $minOvertime = 30,
        public readonly array $offDays = [],
        public readonly ?int $id = null,
        public readonly string $name = '',
    ) {}

    public function crossesMidnight(): bool
    {
        return $this->end <= $this->start;
    }

    public function isOffDay(string $date): bool
    {
        return in_array(CarbonImmutable::parse($date)->dayOfWeekIso, $this->offDays, true);
    }

    public function scheduledStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date.' '.$this->start);
    }

    public function scheduledEnd(string $date): CarbonImmutable
    {
        $end = CarbonImmutable::parse($date.' '.$this->end);

        return $this->crossesMidnight() ? $end->addDay() : $end;
    }
}
