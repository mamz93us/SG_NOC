<?php

namespace App\Services\Attendance;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Turns one person's punches on one work day into a check-in and check-out,
 * and — when they have a shift — late arrival, early leave and overtime.
 *
 * The rule, for every user: check-in is the EARLIEST punch of the day and
 * check-out the LATEST. Punches in between are kept but do not move either.
 * BioTime's punch_state is deliberately not an input — staff rarely press the
 * in/out key, so it says little about which punch was which.
 *
 * With a shift:
 *  - late        = minutes from shift start to check-in, once past the grace;
 *  - early leave = minutes from check-out to shift end, once past the grace;
 *  - overtime    = minutes after shift end, once it reaches the shift minimum;
 *                  every worked minute on a day off or a holiday.
 *
 * Pure: no database and no clock of its own ("now" is passed in), so every
 * rule is covered by tests/Unit/Attendance.
 */
final class AttendanceDayBuilder
{
    public const FORMAT = 'Y-m-d H:i:s';

    /** Punches this close to the previous one are the same finger twice. */
    public const DUPLICATE_WINDOW_SECONDS = 120;

    /** Device clocks drift; a few minutes ahead of ours is not an error. */
    public const FUTURE_TOLERANCE_MINUTES = 10;

    /** With no shift, longer than this between first and last punch is a forgotten check-out. */
    public const DEFAULT_MAX_MINUTES = 960;

    /** An overnight shift's day keeps punches up to this long after the shift ends. */
    public const NIGHT_TAIL_HOURS = 6;

    public const STATUS_PRESENT = 'present';

    public const STATUS_ABSENT = 'absent';

    public const STATUS_EXCUSED = 'excused';

    /** Nothing to record: a day off, or a work day that has not ended yet. */
    public const STATUS_NONE = 'none';

    public const FLAG_MISSING_CHECK_OUT = 'missing_check_out';

    public const FLAG_FUTURE_PUNCH = 'future_punch';

    public const FLAG_UNMAPPED = 'unmapped_employee';

    public const FLAG_OVER_MAX_HOURS = 'over_max_hours';

    public const FLAG_ABSENT = 'absent';

    public const FLAG_BEFORE_HIRE = 'before_hire';

    public const FLAG_AFTER_TERMINATION = 'after_termination';

    public const FLAG_DUPLICATES = 'duplicate_punches';

    public const FLAG_NOT_EMPLOYEE = 'not_an_employee';

    public const FLAG_LATE = 'late';

    public const FLAG_EARLY_LEAVE = 'early_leave';

    public const FLAG_OVERTIME = 'overtime';

    public const FLAG_WORKED_OFF_DAY = 'worked_off_day';

    public const FLAG_WORKED_HOLIDAY = 'worked_holiday';

    public const FLAG_OTHER_BRANCH = 'other_branch';

    public const FLAG_ADJUSTED = 'adjusted';

    public const FLAG_EXCUSED = 'excused';

    /** Data errors: they must be fixed before a period is sent. Everything else is a warning or a fact. */
    public const ERRORS = [
        self::FLAG_MISSING_CHECK_OUT,
        self::FLAG_FUTURE_PUNCH,
        self::FLAG_UNMAPPED,
        self::FLAG_OVER_MAX_HOURS,
        self::FLAG_ABSENT,
        self::FLAG_BEFORE_HIRE,
        self::FLAG_AFTER_TERMINATION,
    ];

    public const LABELS = [
        self::FLAG_MISSING_CHECK_OUT => 'Missing check-out',
        self::FLAG_FUTURE_PUNCH => 'Punch in the future',
        self::FLAG_UNMAPPED => 'Unmapped employee',
        self::FLAG_OVER_MAX_HOURS => 'Too many hours',
        self::FLAG_ABSENT => 'Absent',
        self::FLAG_BEFORE_HIRE => 'Punch before hire date',
        self::FLAG_AFTER_TERMINATION => 'Punch after termination',
        self::FLAG_DUPLICATES => 'Duplicate punches',
        self::FLAG_NOT_EMPLOYEE => 'Not an employee',
        self::FLAG_LATE => 'Late',
        self::FLAG_EARLY_LEAVE => 'Early leave',
        self::FLAG_OVERTIME => 'Overtime',
        self::FLAG_WORKED_OFF_DAY => 'Worked on day off',
        self::FLAG_WORKED_HOLIDAY => 'Worked on holiday',
        self::FLAG_OTHER_BRANCH => 'Punched at another branch',
        self::FLAG_ADJUSTED => 'Corrected by HR',
        self::FLAG_EXCUSED => 'Excused',
    ];

    /**
     * @param  iterable<string|DateTimeInterface>  $punches  every punch in this day's window, any order
     * @param  CarbonImmutable  $now  the current wall-clock time where the device is
     * @param  list<string>  $extraFlags  flags decided elsewhere (unmapped code, punch after termination…)
     */
    public function build(string $workDate, iterable $punches, CarbonImmutable $now, array $extraFlags = [], ?DayContext $context = null): DayResult
    {
        $context ??= new DayContext;
        $shift = $context->shift;

        $times = [];
        foreach ($punches as $punch) {
            $times[] = $punch instanceof DateTimeInterface
                ? CarbonImmutable::instance($punch)
                : CarbonImmutable::parse($punch);
        }
        usort($times, fn (CarbonImmutable $a, CarbonImmutable $b) => $a->getTimestamp() <=> $b->getTimestamp());

        $flags = $extraFlags;

        // Collapse repeats: a second touch within the window of the previous
        // kept punch is not a new event.
        $distinct = [];
        foreach ($times as $time) {
            $previous = end($distinct);
            if ($previous !== false
                && $time->getTimestamp() - $previous->getTimestamp() < self::DUPLICATE_WINDOW_SECONDS) {
                continue;
            }
            $distinct[] = $time;
        }

        if (count($distinct) < count($times)) {
            $flags[] = self::FLAG_DUPLICATES;
        }

        // Check-in is the earliest punch and check-out the LATEST — the last
        // touch of the final burst, not its first (17:00:20 then 17:01:22 at
        // two terminals is out at 17:01:22). Collapsing repeats only decides
        // whether there was more than one real punch at all.
        $firstIn = $times[0] ?? null;
        $lastOut = count($distinct) > 1 ? $times[count($times) - 1] : null;

        // An HR correction replaces the device's times; the punches stay as recorded.
        if ($context->checkIn !== null) {
            $firstIn = CarbonImmutable::parse($context->checkIn);
        }
        if ($context->checkOut !== null) {
            $lastOut = CarbonImmutable::parse($context->checkOut);
        }
        if ($context->checkIn !== null || $context->checkOut !== null) {
            $flags[] = self::FLAG_ADJUSTED;
        }
        if ($firstIn === null && $lastOut !== null) {
            [$firstIn, $lastOut] = [$lastOut, null];
        }

        $scheduledStart = $shift?->scheduledStart($workDate);
        $scheduledEnd = $shift?->scheduledEnd($workDate);
        $isHoliday = $context->holiday !== null;
        $isOffDay = $shift?->isOffDay($workDate) ?? false;
        $isWorkDay = $shift !== null && ! $isOffDay && ! $isHoliday;
        $excused = $context->excuse !== null;

        // ── No punches at all ───────────────────────────────────────
        if ($firstIn === null) {
            $status = self::STATUS_NONE;

            if ($excused) {
                $status = self::STATUS_EXCUSED;
                $flags[] = self::FLAG_EXCUSED;
            } elseif ($isWorkDay && $context->expectedToWork
                && $now->getTimestamp() > $scheduledEnd->addMinutes($shift->graceOut)->getTimestamp()) {
                // Only once the shift is over — before that they may still arrive.
                $status = self::STATUS_ABSENT;
                $flags[] = self::FLAG_ABSENT;
            }

            return new DayResult(
                workDate: $workDate,
                firstIn: null,
                lastOut: null,
                punchCount: 0,
                workedMinutes: null,
                flags: array_values(array_unique($flags)),
                status: $status,
                scheduledStart: $scheduledStart?->format(self::FORMAT),
                scheduledEnd: $scheduledEnd?->format(self::FORMAT),
                excuse: $context->excuse,
            );
        }

        // ── Present ─────────────────────────────────────────────────
        // One punch: it stands as the check-in and the check-out is missing.
        if ($lastOut === null) {
            $flags[] = self::FLAG_MISSING_CHECK_OUT;
        }

        $limit = $now->addMinutes(self::FUTURE_TOLERANCE_MINUTES)->getTimestamp();
        foreach ($times as $time) {
            if ($time->getTimestamp() > $limit) {
                $flags[] = self::FLAG_FUTURE_PUNCH;
                break;
            }
        }

        $worked = $lastOut !== null ? max(0, self::minutesBetween($firstIn, $lastOut)) : null;

        $late = 0;
        $early = 0;
        $overtime = 0;

        if ($isWorkDay) {
            if ($firstIn->getTimestamp() > $scheduledStart->addMinutes($shift->graceIn)->getTimestamp()) {
                $late = self::minutesBetween($scheduledStart, $firstIn);
            }
            if ($lastOut !== null && $lastOut->getTimestamp() < $scheduledEnd->subMinutes($shift->graceOut)->getTimestamp()) {
                $early = self::minutesBetween($lastOut, $scheduledEnd);
            }
            if ($lastOut !== null) {
                $after = self::minutesBetween($scheduledEnd, $lastOut);
                if ($after >= max(1, $shift->minOvertime)) {
                    $overtime = $after;
                }
            }
        } elseif ($isHoliday || $isOffDay) {
            $flags[] = $isHoliday ? self::FLAG_WORKED_HOLIDAY : self::FLAG_WORKED_OFF_DAY;
            $overtime = $worked ?? 0;
        }

        $maxMinutes = $shift ? $shift->maxMinutes : self::DEFAULT_MAX_MINUTES;
        if ($maxMinutes !== null && $worked !== null && $worked > $maxMinutes) {
            $flags[] = self::FLAG_OVER_MAX_HOURS;
        }

        if ($late > 0) {
            $flags[] = self::FLAG_LATE;
        }
        if ($early > 0) {
            $flags[] = self::FLAG_EARLY_LEAVE;
        }
        if ($overtime > 0) {
            $flags[] = self::FLAG_OVERTIME;
        }

        $status = self::STATUS_PRESENT;

        // A whole-day excuse (leave, mission, WFH) settles attendance problems,
        // not data problems: a future punch or an unmapped code still stands.
        if ($excused) {
            $flags = array_diff($flags, [self::FLAG_MISSING_CHECK_OUT, self::FLAG_OVER_MAX_HOURS, self::FLAG_LATE, self::FLAG_EARLY_LEAVE]);
            $flags[] = self::FLAG_EXCUSED;
            $late = 0;
            $early = 0;
            $status = self::STATUS_EXCUSED;
        }

        return new DayResult(
            workDate: $workDate,
            firstIn: $firstIn->format(self::FORMAT),
            lastOut: $lastOut?->format(self::FORMAT),
            punchCount: count($times),
            workedMinutes: $worked,
            flags: array_values(array_unique($flags)),
            status: $status,
            lateMinutes: $late,
            earlyLeaveMinutes: $early,
            overtimeMinutes: $overtime,
            scheduledStart: $scheduledStart?->format(self::FORMAT),
            scheduledEnd: $scheduledEnd?->format(self::FORMAT),
            excuse: $context->excuse,
        );
    }

    /**
     * The stretch of time whose punches belong to $date — start inclusive,
     * end exclusive.
     *
     * Normally the calendar day. An overnight shift's day runs on past
     * midnight to NIGHT_TAIL_HOURS after the shift ends, so a 22:00–06:00
     * shift is one day, not two broken halves; the next day then starts where
     * that one stopped. Windows never overlap and leave no gap, so every punch
     * belongs to exactly one day.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function window(string $date, ?ShiftRule $shift, ?ShiftRule $previousShift = null): array
    {
        [$start, $end] = self::ownWindow($date, $shift);

        $previousDate = CarbonImmutable::parse($date)->subDay()->toDateString();
        if ($previousShift && $previousShift->crossesMidnight() && ! $previousShift->isOffDay($previousDate)) {
            [, $previousEnd] = self::ownWindow($previousDate, $previousShift);
            if ($previousEnd->greaterThan($start)) {
                $start = $previousEnd;
            }
        }

        return [$start, $end];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private static function ownWindow(string $date, ?ShiftRule $shift): array
    {
        $day = CarbonImmutable::parse($date)->startOfDay();

        if ($shift && $shift->crossesMidnight()) {
            return [$day, $shift->scheduledEnd($date)->addHours(self::NIGHT_TAIL_HOURS)];
        }

        return [$day, $day->addDay()];
    }

    private static function minutesBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }
}
