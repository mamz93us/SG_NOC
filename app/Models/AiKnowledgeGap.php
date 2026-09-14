<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question the AI Assistant could not answer, with a hit counter, and the
 * loop that closes it: a person answers it at AI Assistant ▸ Knowledge gaps,
 * or `ai:knowledge-gaps` finds an article published since that answers it.
 * The columns are described in the 2026_09_14_130001 migration.
 */
class AiKnowledgeGap extends Model
{
    /** How the question was caught. */
    public const NO_RESULTS = 'no_results';

    public const NOT_ANSWERED = 'not_answered';

    public const NOT_HELPFUL = 'not_helpful';

    /** How it was closed. */
    public const ANSWERED = 'answered';

    public const COVERED = 'covered';

    public const DISMISSED = 'dismissed';

    protected $fillable = [
        'query_normalized', 'query_sample', 'hit_count', 'locale', 'source', 'embedding', 'last_conversation_id', 'group_id',
        'last_seen_at', 'resolved_at', 'resolution', 'article_id', 'resolved_by', 'best_score', 'best_article_id', 'checked_at',
    ];

    protected $hidden = ['embedding'];

    protected $casts = [
        'hit_count' => 'integer',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'checked_at' => 'datetime',
        'best_score' => 'float',
        'article_id' => 'integer',
        'best_article_id' => 'integer',
        'resolved_by' => 'integer',
        'group_id' => 'integer',
        'last_conversation_id' => 'integer',
    ];

    public function article(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'article_id');
    }

    public function bestArticle(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeArticle::class, 'best_article_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    /**
     * Record (or bump) a question the assistant could not answer.
     *
     * Asked again after an answer or an article closed it, it opens again:
     * whatever closed it did not reach this asker. A dismissed one stays
     * dismissed and only counts the ask.
     */
    public static function record(string $rawQuery, string $source = self::NO_RESULTS, ?int $conversationId = null): ?self
    {
        $normalized = mb_substr(mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $rawQuery))), 0, 500);

        if ($normalized === '') {
            return null;
        }

        $gap = static::firstOrNew(['query_normalized' => $normalized]);
        $gap->query_sample = mb_substr(trim($rawQuery), 0, 2000);
        // The question's own language. This used to be the portal's, so an
        // Arabic question asked with the page in English was filed as "en".
        $gap->locale = preg_match('/\p{Arabic}/u', $rawQuery) ? 'ar' : 'en';
        $gap->source = $source;
        $gap->hit_count = ($gap->hit_count ?? 0) + 1;
        $gap->last_seen_at = now();
        $gap->last_conversation_id = $conversationId ?? $gap->last_conversation_id;

        if ($gap->resolved_at !== null && $gap->resolution !== self::DISMISSED) {
            $gap->resolved_at = null;
            $gap->resolution = null;
            $gap->article_id = null;
            $gap->resolved_by = null;
        }

        $gap->save();

        return $gap;
    }

    /**
     * A reply rated Not helpful puts the question it answered on the list —
     * when the reply came from a knowledge search. One about the employee's
     * own attendance or tickets is not a gap in the documentation.
     */
    public static function recordNotHelpful(AiMessage $reply): ?self
    {
        $question = AiMessage::query()
            ->where('conversation_id', $reply->conversation_id)
            ->where('role', AiMessage::ROLE_USER)
            ->where('id', '<', $reply->id)
            ->orderByDesc('id')
            ->first();

        if (! $question) {
            return null;
        }

        $searched = AiMessage::query()
            ->where('conversation_id', $reply->conversation_id)
            ->where('role', AiMessage::ROLE_ASSISTANT)
            ->where('id', '>', $question->id)
            ->where('id', '<=', $reply->id)
            ->get()
            ->contains(fn (AiMessage $message) => collect($message->tool_calls ?? [])->contains(
                fn ($call) => ($call['function']['name'] ?? null) === 'search_knowledge'
            ));

        return $searched ? static::record((string) $question->content, self::NOT_HELPFUL, (int) $reply->conversation_id) : null;
    }

    public function resolve(string $resolution, ?int $articleId = null, ?int $userId = null): void
    {
        $this->forceFill([
            'resolved_at' => now(),
            'resolution' => $resolution,
            'article_id' => $articleId,
            'resolved_by' => $userId,
        ])->save();
    }

    public function reopen(): void
    {
        $this->forceFill([
            'resolved_at' => null,
            'resolution' => null,
            'article_id' => null,
            'resolved_by' => null,
        ])->save();
    }
}
