<?php

namespace App\Services\Attendance;

/**
 * Everything about a work day that is not a punch: the person's shift,
 * whether it is a holiday, whether they are expected at all, and any HR
 * correction. Resolved from the database by AttendanceDayProcessor.
 */
final class DayContext
{
    public function __construct(
        public readonly ?ShiftRule $shift = null,
        /** Holiday name, when the day is one. */
        public readonly ?string $holiday = null,
        /** Linked to BioTime and employed on this date — only then can they be absent. */
        public readonly bool $expectedToWork = false,
        /** HR-corrected times, 'Y-m-d H:i:s'. */
        public readonly ?string $checkIn = null,
        public readonly ?string $checkOut = null,
        /** HR excused the whole day: an AttendanceAdjustment::EXCUSES key. */
        public readonly ?string $excuse = null,
    ) {}
}
