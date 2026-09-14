<?php

namespace App\Models\Vacation;

use App\Models\Attendance\Concerns\StoresPlainDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One leave record from Oracle: its type as Oracle names it, and its dates.
 *
 * The sheets carry no duration, so calendar_days and work_days are counted
 * here (Services\Vacation\VacationDays, the book's weekend). `duration` is for
 * a feed that sends Oracle's own figure; when present it wins.
 *
 * Business trips arrive in the same export. They are absences from the office,
 * not leave, and are kept apart wherever days are added up.
 *
 * A record Oracle stops listing is stamped removed_at (withdrawn or changed),
 * never deleted.
 */
class VacationAbsence extends Model
{
    use StoresPlainDates;

    public const STATUS_TAKEN = 'taken';

    public const STATUS_ONGOING = 'ongoing';

    public const STATUS_UPCOMING = 'upcoming';

    public const STATUS_WITHDRAWN = 'withdrawn';

    protected array $plainDates = ['start_date', 'end_date'];

    protected $fillable = [
        'vacation_employee_id',
        'absence_type',
        'start_date',
        'end_date',
        'calendar_days',
        'work_days',
        'duration',
        'first_import_id',
        'last_import_id',
        'removed_at',
    ];

    protected $casts = [
        'vacation_employee_id' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'calendar_days' => 'integer',
        'work_days' => 'integer',
        'duration' => 'float',
        'removed_at' => 'datetime',
    ];

    public function vacationEmployee(): BelongsTo
    {
        return $this->belongsTo(VacationEmployee::class);
    }

    /** Still listed by Oracle. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull($query->qualifyColumn('removed_at'));
    }

    /** Records with at least one day between $from and $to (Y-m-d, inclusive). */
    public function scopeOverlapping(Builder $query, string $from, string $to): Builder
    {
        return $query->where($query->qualifyColumn('start_date'), '<=', $to)->where($query->qualifyColumn('end_date'), '>=', $from);
    }

    /** Leave only, or business trips only. */
    public function scopeTrips(Builder $query, bool $trips = true): Builder
    {
        return $query->whereRaw('LOWER('.$query->qualifyColumn('absence_type').') '.($trips ? 'LIKE' : 'NOT LIKE').' ?', ['%business trip%']);
    }

    public static function isTripType(string $type): bool
    {
        return str_contains(mb_strtolower($type), 'business trip');
    }

    public function isBusinessTrip(): bool
    {
        return static::isTripType((string) $this->absence_type);
    }

    /** Oracle's own duration when a feed sent one, else the work days counted here. */
    public function days(): float
    {
        return $this->duration ?? (float) $this->work_days;
    }

    public function status(?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();

        return match (true) {
            $this->removed_at !== null => self::STATUS_WITHDRAWN,
            $this->start_date->gt($today) => self::STATUS_UPCOMING,
            $this->end_date->lt($today) => self::STATUS_TAKEN,
            default => self::STATUS_ONGOING,
        };
    }

    public function statusLabel(?CarbonImmutable $today = null): string
    {
        return match ($this->status($today)) {
            self::STATUS_WITHDRAWN => 'No longer in Oracle',
            self::STATUS_UPCOMING => 'Upcoming',
            self::STATUS_ONGOING => 'On it now',
            default => 'Taken',
        };
    }

    public function statusBadgeClass(?CarbonImmutable $today = null): string
    {
        return match ($this->status($today)) {
            self::STATUS_WITHDRAWN => 'bg-secondary-subtle text-secondary-emphasis border text-decoration-line-through',
            self::STATUS_UPCOMING => 'bg-info-subtle text-info-emphasis border',
            self::STATUS_ONGOING => 'bg-success',
            default => 'bg-light text-dark border',
        };
    }

    /** A colour per kind of absence; Oracle's type names are shown as they are. */
    public static function typeBadgeClass(string $type): string
    {
        $type = mb_strtolower($type);

        return match (true) {
            static::isTripType($type) => 'bg-secondary',
            str_contains($type, 'annual') => 'bg-primary',
            str_contains($type, 'sick') => 'bg-warning text-dark',
            str_contains($type, 'unpaid') => 'bg-dark',
            default => 'bg-info text-dark',
        };
    }
}
