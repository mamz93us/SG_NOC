<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company announcement, shown in the home portal's slider and archive.
 */
class Announcement extends Model
{
    protected $fillable = [
        'title', 'title_ar', 'body', 'body_ar',
        'link_url', 'link_label',
        'severity', 'pinned',
        'is_published', 'published_at', 'expires_at',
        'audience', 'audience_branch_id', 'audience_department_id',
        'created_by', 'created_by_name',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'expires_at' => 'datetime',
        'audience_branch_id' => 'integer',
        'audience_department_id' => 'integer',
    ];

    public const SEVERITIES = ['info', 'success', 'urgent'];

    public const AUDIENCES = ['all', 'branch', 'department'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'audience_branch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'audience_department_id');
    }

    public function reads(): HasMany
    {
        return $this->hasMany(AnnouncementRead::class);
    }

    /**
     * Published, already live, not yet expired.
     *
     * `published_at` in the future means scheduled, so it must not appear yet —
     * that is the whole reason the column is separate from `is_published`.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->where(function (Builder $q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Only what this employee should see.
     *
     * An employee with no branch or department still sees the `all` notices —
     * a missing HR record must not blank someone's noticeboard.
     */
    public function scopeForEmployee(Builder $query, ?Employee $employee): Builder
    {
        return $query->where(function (Builder $q) use ($employee) {
            $q->where('audience', 'all');

            if ($employee?->branch_id) {
                $q->orWhere(fn (Builder $b) => $b
                    ->where('audience', 'branch')
                    ->where('audience_branch_id', $employee->branch_id));
            }

            if ($employee?->department_id) {
                $q->orWhere(fn (Builder $b) => $b
                    ->where('audience', 'department')
                    ->where('audience_department_id', $employee->department_id));
            }
        });
    }

    /** Newest first, with pinned notices held at the top. */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->orderByDesc('pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function isUrgent(): bool
    {
        return $this->severity === 'urgent';
    }

    /** Plain-text lead-in for the slider and the card preview. */
    public function excerpt(int $length = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $this->body)));

        return mb_strlen($text) > $length
            ? mb_substr($text, 0, $length - 1).'…'
            : $text;
    }

    public function severityBadgeClass(): string
    {
        return match ($this->severity) {
            'urgent' => 'sev-urgent',
            'success' => 'sev-success',
            default => 'sev-info',
        };
    }

    /** Live for this person right now — used by the archive and the slider. */
    public static function liveFor(?Employee $employee): Builder
    {
        return static::query()->live()->forEmployee($employee)->ranked();
    }
}
