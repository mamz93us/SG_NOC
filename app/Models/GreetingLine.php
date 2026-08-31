<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A warm sub-line for the home portal greeting. See App\Services\Home\Greeter.
 */
class GreetingLine extends Model
{
    protected $fillable = ['text', 'text_ar', 'time_of_day', 'day_of_week', 'is_active', 'sort_order'];

    protected $casts = [
        'is_active' => 'boolean',
        'day_of_week' => 'integer',
        'sort_order' => 'integer',
    ];

    public const TIMES = ['morning', 'afternoon', 'evening'];

    public const DAYS = [
        0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    /**
     * Lines eligible right now: active, and either unscoped or matching this
     * time of day / day of week. A null scope means "any", so a general line
     * always stays in the pool alongside the specific ones.
     */
    public function scopeEligible(Builder $query, string $timeOfDay, int $dayOfWeek): Builder
    {
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('time_of_day')->orWhere('time_of_day', $timeOfDay))
            ->where(fn (Builder $q) => $q->whereNull('day_of_week')->orWhere('day_of_week', $dayOfWeek));
    }

    /**
     * "Morning, Thursday" — the human form of this line's targeting.
     *
     * Deliberately NOT named scopeLabel(): Eloquent routes any scopeX() method
     * through its query-scope machinery, so that name would both shadow a
     * `label()` scope call and mislead the next reader.
     */
    public function describeTargeting(): string
    {
        $parts = [];
        $parts[] = $this->time_of_day ? ucfirst($this->time_of_day) : 'Any time';
        $parts[] = $this->day_of_week === null ? 'any day' : self::DAYS[$this->day_of_week];

        return implode(', ', $parts);
    }
}
