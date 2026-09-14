<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A website the AI Assistant is allowed to read, crawled by
 * `ai:crawl-websites` — see Services\Ai\Web\WebCrawler, and the
 * 2026_09_14_140001 migration for the columns.
 */
class AiWebSource extends Model
{
    public const QUEUED = 'queued';

    public const CRAWLING = 'crawling';

    public const IDLE = 'idle';

    public const FAILED = 'failed';

    protected $fillable = [
        'name', 'start_url', 'scope_url', 'max_pages', 'max_depth', 'refresh_days', 'allow_internal', 'publish',
        'category', 'audience', 'audience_branch_id', 'audience_department_id',
        'status', 'error', 'pages_found', 'pages_indexed', 'prompt_tokens', 'completion_tokens',
        'last_crawled_at', 'next_crawl_at', 'created_by',
    ];

    protected $casts = [
        'max_pages' => 'integer',
        'max_depth' => 'integer',
        'refresh_days' => 'integer',
        'allow_internal' => 'boolean',
        'publish' => 'boolean',
        'audience_branch_id' => 'integer',
        'audience_department_id' => 'integer',
        'pages_found' => 'integer',
        'pages_indexed' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'last_crawled_at' => 'datetime',
        'next_crawl_at' => 'datetime',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(AiWebPage::class, 'source_id');
    }

    /** Waiting for ai:crawl-websites: queued, part-way through a round, or due to be read again. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereIn('status', [self::QUEUED, self::CRAWLING])
            ->orWhere(fn (Builder $due) => $due->whereNotNull('next_crawl_at')->where('next_crawl_at', '<=', now())));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::QUEUED => 'Waiting to be read',
            self::CRAWLING => 'Reading',
            self::IDLE => 'Up to date',
            self::FAILED => 'Failed',
            default => ucfirst((string) $this->status),
        };
    }
}
