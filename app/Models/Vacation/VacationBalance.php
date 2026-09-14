<?php

namespace App\Models\Vacation;

use App\Models\Attendance\Concerns\StoresPlainDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's annual leave balance for one year, exactly as Oracle reported
 * it on `as_of`:
 *
 *   carryover  last year's balance brought into this year (CARRYOVER)
 *   accrued    this year's leave earned up to as_of (ACCRUALS) — it grows every
 *              month, so it is only ever as fresh as the last import
 *   used       days taken this year (ABSENCES, which Oracle writes negative)
 *   balance    what is left (TOTAL_BALANCE)
 *
 * `balance` is Oracle's figure and is never recomputed from the parts: for a
 * few people it includes an adjustment the sheet has no column for, which
 * otherAdjustments() shows instead of hiding. All four are null for someone
 * Oracle has not enrolled in a leave plan yet.
 */
class VacationBalance extends Model
{
    use StoresPlainDates;

    /** Oracle rounds each part to 2 decimals, so the parts can miss the total by 0.01. */
    public const ROUNDING = 0.02;

    protected array $plainDates = ['as_of'];

    protected $fillable = [
        'vacation_employee_id',
        'year',
        'carryover',
        'accrued',
        'used',
        'balance',
        'as_of',
        'vacation_import_id',
    ];

    protected $casts = [
        'vacation_employee_id' => 'integer',
        'year' => 'integer',
        'carryover' => 'float',
        'accrued' => 'float',
        'used' => 'float',
        'balance' => 'float',
        'as_of' => 'date',
    ];

    public function vacationEmployee(): BelongsTo
    {
        return $this->belongsTo(VacationEmployee::class);
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(VacationImport::class, 'vacation_import_id');
    }

    public function hasBalance(): bool
    {
        return $this->balance !== null;
    }

    /**
     * What Oracle's balance holds beyond carryover + accrued − used; 0 when the
     * gap is only rounding, null with no balance at all.
     */
    public function otherAdjustments(): ?float
    {
        if ($this->balance === null) {
            return null;
        }

        $difference = round($this->balance - ((float) $this->carryover + (float) $this->accrued - (float) $this->used), 2);

        return abs($difference) >= self::ROUNDING ? $difference : 0.0;
    }

    public function isStale(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return $this->as_of !== null
            && $this->as_of->lt($today->subDays((int) config('vacations.stale_after_days', 35)));
    }

    /** "14.67", "10", "-3.5"; a dash for nothing. */
    public static function days(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
