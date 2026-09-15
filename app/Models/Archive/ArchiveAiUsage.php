<?php

namespace App\Models\Archive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Every AI call that cost something.
 *
 * Written per call so the budget is measured rather than assumed. The
 * alternative — trusting an estimate made before a batch started — is how a
 * feature that reads 40,000 pages gets discovered on a bill instead of on a
 * page.
 *
 * Excluded from the automatic audit: it is a meter, not an event.
 */
class ArchiveAiUsage extends Model
{
    protected $table = 'archive_ai_usage';

    public const FEATURE_READ = 'read';

    public const FEATURE_EXTRACT = 'extract';

    public const FEATURE_ASK_DOCUMENT = 'ask_document';

    public const FEATURE_ASK_ARCHIVE = 'ask_archive';

    public const FEATURE_FILL = 'fill';

    protected $fillable = [
        'day',
        'user_id',
        'archive_id',
        'feature',
        'pages',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
    ];

    protected $casts = [
        'day' => 'date',
        'pages' => 'integer',
        'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer',
        'cost_usd' => 'decimal:5',
    ];

    protected $attributes = [
        'pages' => 0,
        'prompt_tokens' => 0,
        'completion_tokens' => 0,
        'cost_usd' => 0,
    ];

    /**
     * Record what a call cost. Never allowed to break the call it is measuring.
     */
    public static function record(
        string $feature,
        float $costUsd,
        int $pages = 0,
        ?int $userId = null,
        ?int $archiveId = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
    ): void {
        try {
            static::create([
                'day' => now()->toDateString(),
                'user_id' => $userId,
                'archive_id' => $archiveId,
                'feature' => $feature,
                'pages' => $pages,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cost_usd' => round($costUsd, 5),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[archive] AI usage not recorded: '.$e->getMessage());
        }
    }

    public static function spentThisMonth(): float
    {
        try {
            return (float) static::query()
                ->whereBetween('day', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                ->sum('cost_usd');
        } catch (\Throwable) {
            return 0.0;
        }
    }

    /**
     * Pages one person has had read today.
     *
     * The per-person cap exists so that one enthusiastic afternoon of asking
     * questions cannot spend the month's budget on its own.
     */
    public static function pagesToday(?int $userId): int
    {
        if (! $userId) {
            return 0;
        }

        try {
            return (int) static::query()
                ->where('user_id', $userId)
                ->where('day', now()->toDateString())
                ->sum('pages');
        } catch (\Throwable) {
            return 0;
        }
    }
}
