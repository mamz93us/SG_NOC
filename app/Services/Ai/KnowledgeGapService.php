<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use App\Models\AiKnowledgeGap;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The loop behind AI Assistant ▸ Knowledge gaps: embed each unanswered
 * question once, group the wordings of one question, find the articles that
 * now answer them, and close what a person answers.
 *
 * Past that one embedding per question everything compares stored vectors,
 * so checking again costs no Azure calls.
 */
class KnowledgeGapService
{
    /** Two questions this close are one question worded twice; NOC2's list had USB drives asked five ways. */
    public const SAME_QUESTION = 0.80;

    /**
     * An article this close to a question closes it with nobody confirming.
     * Well above the 0.40 search floor on purpose: correct matches measured
     * 0.43–0.77, so an article between the two is offered, not assumed —
     * closing a gap that is still open would hide a question nobody answered.
     */
    public const AUTO_CLOSE = 0.60;

    /** Questions embedded per request. */
    private const EMBED_BATCH = 64;

    public function __construct(private AzureOpenAiClient $client) {}

    /**
     * Embeds the gaps not embedded yet, one request per batch.
     *
     * @param  Collection<int, AiKnowledgeGap>  $gaps
     */
    public function embedMissing(Collection $gaps): int
    {
        $missing = $gaps->filter(fn (AiKnowledgeGap $gap) => ! $gap->embedding)->values();

        foreach ($missing->chunk(self::EMBED_BATCH) as $batch) {
            $batch = $batch->values();
            $vectors = $this->client->embed($batch->map(fn (AiKnowledgeGap $gap) => (string) $gap->query_sample)->all());

            foreach ($batch as $i => $gap) {
                $gap->forceFill(['embedding' => pack('f*', ...($vectors[$i] ?? []))])->save();
            }
        }

        return $missing->count();
    }

    /**
     * Gives open gaps that are wordings of one question the same group_id —
     * the id of the most-asked one — and null to a question alone. Grouping is
     * single-link, so two wordings join through a third close to both.
     *
     * @param  Collection<int, AiKnowledgeGap>  $gaps
     * @return int how many questions (groups) there are
     */
    public function regroup(Collection $gaps, float $threshold = self::SAME_QUESTION): int
    {
        $gaps = $gaps->values();
        $count = $gaps->count();
        $vectors = $gaps->map(fn (AiKnowledgeGap $gap) => $gap->embedding ? self::unit($gap->embedding) : null)->all();
        $parent = $count > 0 ? range(0, $count - 1) : [];

        for ($i = 0; $i < $count; $i++) {
            if ($vectors[$i] === null) {
                continue;
            }

            for ($j = $i + 1; $j < $count; $j++) {
                if ($vectors[$j] === null || self::dot($vectors[$i], $vectors[$j]) < $threshold) {
                    continue;
                }

                $a = self::root($parent, $i);
                $b = self::root($parent, $j);

                if ($a !== $b) {
                    $parent[$b] = $a;
                }
            }
        }

        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $groups[self::root($parent, $i)][] = $gaps[$i];
        }

        foreach ($groups as $members) {
            $lead = collect($members)->sortBy([['hit_count', 'desc'], ['id', 'asc']])->first();
            $groupId = count($members) > 1 ? $lead->id : null;

            foreach ($members as $gap) {
                if ($gap->group_id !== $groupId) {
                    $gap->forceFill(['group_id' => $groupId])->save();
                }
            }
        }

        return count($groups);
    }

    /**
     * Compares open gaps with the article chunks that changed since each was
     * last checked, keeps each one's closest article, and closes the ones an
     * article now answers (AUTO_CLOSE).
     *
     * @param  Collection<int, AiKnowledgeGap>  $gaps
     * @return array{checked: int, closed: int}
     */
    public function recheck(Collection $gaps): array
    {
        $gaps = $gaps->filter(fn (AiKnowledgeGap $gap) => $gap->embedding && $gap->resolved_at === null)->values();

        if ($gaps->isEmpty()) {
            return ['checked' => 0, 'closed' => 0];
        }

        // Taken before the chunks are read, so one written during the check is
        // still newer than checked_at next time.
        $startedAt = now();
        $since = $gaps->contains(fn (AiKnowledgeGap $gap) => $gap->checked_at === null) ? null : $gaps->min('checked_at');

        $chunks = AiKnowledgeChunk::query()
            ->whereNotNull('article_id')
            ->whereNotNull('embedding')
            ->whereHas('article', fn ($article) => $article->where('is_published', true))
            ->when($since, fn ($query) => $query->where('updated_at', '>', $since))
            ->get(['id', 'article_id', 'embedding', 'updated_at'])
            ->map(fn (AiKnowledgeChunk $chunk) => [
                'article_id' => (int) $chunk->article_id,
                'updated_at' => $chunk->updated_at,
                'vector' => self::unit($chunk->embedding),
            ]);

        $closed = 0;

        foreach ($gaps as $gap) {
            $question = self::unit($gap->embedding);
            $bestScore = $gap->best_score;
            $bestArticle = $gap->best_article_id;

            foreach ($chunks as $chunk) {
                if ($gap->checked_at !== null && $chunk['updated_at']->lte($gap->checked_at)) {
                    continue;
                }

                $score = self::dot($question, $chunk['vector']);

                if ($bestScore === null || $score > $bestScore) {
                    $bestScore = $score;
                    $bestArticle = $chunk['article_id'];
                }
            }

            $gap->forceFill([
                'best_score' => $bestScore === null ? null : round($bestScore, 3),
                'best_article_id' => $bestArticle,
                'checked_at' => $startedAt,
            ]);

            if ($bestScore !== null && $bestScore >= self::AUTO_CLOSE) {
                $gap->forceFill([
                    'resolved_at' => now(),
                    'resolution' => AiKnowledgeGap::COVERED,
                    'article_id' => $bestArticle,
                    'resolved_by' => null,
                ]);
                $closed++;
            }

            $gap->save();
        }

        return ['checked' => $gaps->count(), 'closed' => $closed];
    }

    /**
     * Gaps closed by, or pointing at, an article since deleted or unpublished:
     * the closed ones open again, and the pointer is dropped so the next check
     * looks at everything.
     */
    public function reopenOrphans(): int
    {
        $live = AiKnowledgeArticle::where('is_published', true)->pluck('id')->all();

        $reopened = AiKnowledgeGap::query()
            ->whereNotNull('resolved_at')
            ->whereIn('resolution', [AiKnowledgeGap::ANSWERED, AiKnowledgeGap::COVERED])
            ->where(fn ($query) => $query->whereNull('article_id')->orWhereNotIn('article_id', $live))
            ->update([
                'resolved_at' => null, 'resolution' => null, 'article_id' => null, 'resolved_by' => null,
                'best_score' => null, 'best_article_id' => null, 'checked_at' => null,
            ]);

        AiKnowledgeGap::open()
            ->whereNotNull('best_article_id')
            ->whereNotIn('best_article_id', $live)
            ->update(['best_score' => null, 'best_article_id' => null, 'checked_at' => null]);

        return $reopened;
    }

    /**
     * Publishes a person's answer to a group of gaps, closes them, and scores
     * each wording against the new article: proof the assistant now finds it,
     * or a sign that a wording still falls below the search floor.
     *
     * @param  Collection<int, AiKnowledgeGap>  $gaps
     * @return array{article: AiKnowledgeArticle, indexed: bool, scores: array<int, array{question: string, score: ?float}>}
     */
    public function answer(Collection $gaps, array $attributes, ?int $userId, KnowledgeIndexer $indexer): array
    {
        $article = AiKnowledgeArticle::create(array_merge($attributes, ['is_published' => true, 'created_by' => $userId]));
        $indexed = $indexer->indexArticle($article);

        try {
            $this->embedMissing($gaps);
        } catch (Throwable) {
            // The scores are a check on the answer, not the answer: without
            // Azure they are simply not shown.
        }

        $vectors = $article->chunks()->whereNotNull('embedding')->get()
            ->map(fn (AiKnowledgeChunk $chunk) => self::unit($chunk->embedding));

        $scores = [];

        foreach ($gaps as $gap) {
            $score = null;

            if ($gap->embedding && $vectors->isNotEmpty()) {
                $question = self::unit($gap->embedding);
                $score = round((float) $vectors->map(fn (array $vector) => self::dot($question, $vector))->max(), 3);
            }

            $gap->forceFill(['best_score' => $score, 'best_article_id' => $article->id, 'checked_at' => now()]);
            $gap->resolve(AiKnowledgeGap::ANSWERED, $article->id, $userId);

            $scores[] = ['question' => (string) $gap->query_sample, 'score' => $score];
        }

        return ['article' => $article, 'indexed' => $indexed, 'scores' => $scores];
    }

    /** @return array<int, float> the vector scaled to length 1, so a dot product is its cosine */
    public static function unit(string $packed): array
    {
        $vector = array_values(unpack('f*', $packed) ?: []);
        $length = sqrt(array_sum(array_map(fn (float $x) => $x * $x, $vector)));

        return $length > 0 ? array_map(fn (float $x) => $x / $length, $vector) : $vector;
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        $sum = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $sum += $a[$i] * $b[$i];
        }

        return $sum;
    }

    /** @param  array<int, int>  $parent */
    private static function root(array &$parent, int $i): int
    {
        while ($parent[$i] !== $i) {
            $parent[$i] = $parent[$parent[$i]];
            $i = $parent[$i];
        }

        return $i;
    }
}
