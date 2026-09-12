<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceDay;
use Illuminate\Support\Collection;

/**
 * One calendar date on a person's monthly sheet.
 *
 * A month sheet shows every date, not only the ones attendance_days holds —
 * days off, holidays and dates outside employment have no row there at all
 * (AttendanceDayProcessor writes nothing for STATUS_NONE), and a sheet with
 * those dates simply missing reads as unexplained gaps.
 *
 * So `kind` is what the CALENDAR said — was this person due at work? — while
 * `day` is what actually happened, and is null when nothing was recorded.
 */
final class MonthlyDay
{
    /** Due at work: absence, lateness and early leave are only counted on these. */
    public const KIND_WORK = 'work';

    /** The shift's weekly day off. */
    public const KIND_OFF = 'off';

    /** A public or company holiday for their branch. */
    public const KIND_HOLIDAY = 'holiday';

    /** No shift assigned, so nothing is expected and nothing can be judged. */
    public const KIND_NO_SHIFT = 'no_shift';

    public const KIND_BEFORE_HIRE = 'before_hire';

    public const KIND_AFTER_TERMINATION = 'after_termination';

    public const KIND_LABELS = [
        self::KIND_WORK => 'Work day',
        self::KIND_OFF => 'Day off',
        self::KIND_HOLIDAY => 'Holiday',
        self::KIND_NO_SHIFT => 'No shift',
        self::KIND_BEFORE_HIRE => 'Before hire',
        self::KIND_AFTER_TERMINATION => 'After termination',
    ];

    /**
     * @param  string  $date  Y-m-d
     * @param  Collection<int, \App\Models\Attendance\AttendancePunch>  $punches  every punch in this date's window, in order
     * @param  bool  $future  after today — nothing is missing yet, it simply has not happened
     */
    public function __construct(
        public readonly string $date,
        public readonly string $kind,
        public readonly ?AttendanceDay $day,
        public readonly Collection $punches,
        public readonly ?ShiftRule $shift = null,
        public readonly ?string $holiday = null,
        public readonly bool $future = false,
    ) {}

    public function isWorkDay(): bool
    {
        return $this->kind === self::KIND_WORK;
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_HOLIDAY && $this->holiday
            ? $this->holiday
            : (self::KIND_LABELS[$this->kind] ?? $this->kind);
    }

    public function status(): ?string
    {
        return $this->day?->status;
    }

    public function has(string $flag): bool
    {
        return in_array($flag, $this->day?->flags ?? [], true);
    }

    public function workedMinutes(): int
    {
        return (int) ($this->day?->worked_minutes ?? 0);
    }

    /**
     * What to show when nothing was recorded: a work day that has ended with no
     * row is either an absence the hourly `attendance:process` has not written
     * yet, or someone not linked to BioTime — never silently blank.
     */
    public function emptyLabel(): string
    {
        if ($this->future) {
            return '—';
        }

        return $this->isWorkDay() ? 'Not recorded' : $this->kindLabel();
    }
}
