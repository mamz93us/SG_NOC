<?php

namespace App\Services\Attendance;

/**
 * What AttendanceDayBuilder decided for one person on one day.
 * Times are wall-clock 'Y-m-d H:i:s' strings, exactly as the device recorded them.
 */
final class DayResult
{
    /**
     * @param  list<string>  $flags
     */
    public function __construct(
        public readonly string $workDate,
        public readonly ?string $firstIn,
        public readonly ?string $lastOut,
        public readonly int $punchCount,
        public readonly ?int $workedMinutes,
        public readonly array $flags,
        public readonly string $status = AttendanceDayBuilder::STATUS_PRESENT,
        public readonly int $lateMinutes = 0,
        public readonly int $earlyLeaveMinutes = 0,
        public readonly int $overtimeMinutes = 0,
        public readonly ?string $scheduledStart = null,
        public readonly ?string $scheduledEnd = null,
        public readonly ?string $excuse = null,
    ) {}

    public function hasError(): bool
    {
        return array_intersect($this->flags, AttendanceDayBuilder::ERRORS) !== [];
    }
}
