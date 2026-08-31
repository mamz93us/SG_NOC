<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A cached entry from the company's shared Microsoft 365 calendar.
 *
 * Microsoft owns these; this table is a disposable read cache refilled by
 * `company-calendar:sync`. Never written from a request.
 */
class CompanyEvent extends Model
{
    protected $fillable = [
        'external_id', 'subject', 'body_preview', 'location',
        'starts_at', 'ends_at', 'is_all_day', 'organizer', 'web_link', 'synced_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'is_all_day' => 'boolean',
        'synced_at' => 'datetime',
    ];

    /**
     * Events that have not finished yet, soonest first.
     *
     * Filters on the END time so an all-day or multi-day event still counts as
     * "upcoming" while it is actually running — filtering on `starts_at` would
     * drop today's company holiday at one minute past midnight.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query
            ->where(function (Builder $q) {
                $q->where('ends_at', '>=', now())
                    ->orWhere(fn (Builder $e) => $e->whereNull('ends_at')->where('starts_at', '>=', now()));
            })
            ->orderBy('starts_at');
    }

    /** "Thu 4 Sep" or "Thu 4 Sep, 14:00" depending on all-day. */
    public function whenLabel(): string
    {
        if (! $this->starts_at) {
            return '';
        }

        return $this->is_all_day
            ? $this->starts_at->format('D j M')
            : $this->starts_at->format('D j M, H:i');
    }

    public function isToday(): bool
    {
        return $this->starts_at?->isToday() ?? false;
    }
}
