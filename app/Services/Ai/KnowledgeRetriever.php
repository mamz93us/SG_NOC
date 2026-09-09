<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeChunk;
use App\Models\AiSetting;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Embeds a question and ranks it against every audience-visible chunk by
 * cosine similarity, computed in PHP over unpacked float32 blobs — see the
 * ai_knowledge_chunks migration for why there is no vector column.
 *
 * An empty result (nothing clears the relevance floor) is not a failure — it
 * is what authorises AssistantAgent to let draft_ticket run, and it is
 * recorded in ai_knowledge_gaps so IT knows what to write next.
 */
class KnowledgeRetriever
{
    /**
     * Fallback when AiSetting::knowledge_match_threshold hasn't been set
     * (fresh install, before the migration backfills it).
     *
     * Not 1.0, or even 0.7: text-embedding-3-small's cosine scores for a
     * genuinely correct query-to-document match commonly land in the
     * 0.4-0.6 range, not 0.8+. Measured directly on production against two
     * real published articles: short, real employee-style queries
     * ("security policy", "how to apply for vacation", "vacation balance")
     * scored 0.43-0.46 against their correct chunk — genuinely unrelated
     * chunks scored under 0.19 for the same queries. A floor of 0.72 (and
     * even the once-revised 0.5) rejected those correct matches outright,
     * silently sending the employee to a needless ticket draft instead of
     * the article that already answered the question. See
     * knowledge_match_threshold on the AI Assistant Instructions page if
     * this needs retuning as more content is added — it no longer requires
     * a deploy to change.
     */
    public const DEFAULT_RELEVANCE_FLOOR = 0.40;

    public function __construct(private AzureOpenAiClient $client) {}

    private function relevanceFloor(): float
    {
        $configured = AiSetting::get()->knowledge_match_threshold;

        return $configured !== null ? (float) $configured : self::DEFAULT_RELEVANCE_FLOOR;
    }

    /** @return Collection<int, array{title:string, heading:?string, content:string, score:float}> */
    public function search(string $query, ?Employee $employee, int $k = 6): Collection
    {
        $query = trim($query);
        if ($query === '') {
            return collect();
        }

        $vectors = $this->client->embed([$query]);
        $queryVector = $vectors[0] ?? [];

        if ($queryVector === []) {
            return collect();
        }

        // Two sources, one chunk table: a hand-written article, or a PDF
        // extracted from the employee document library — both denormalise
        // audience onto the chunk row, so one query and one score covers
        // either kind without the caller needing to know which is which.
        $candidates = AiKnowledgeChunk::query()
            ->where(function ($q) {
                $q->where(fn ($a) => $a->whereNotNull('article_id')->whereHas('article', fn ($x) => $x->published()))
                    ->orWhere(fn ($d) => $d->whereNotNull('portal_document_id')->whereHas('portalDocument', fn ($x) => $x->where('is_published', true)));
            })
            ->forEmployee($employee)
            ->with(['article:id,title,title_ar', 'portalDocument:id,title,title_ar'])
            ->get();

        $floor = $this->relevanceFloor();

        $scored = $candidates
            ->map(function (AiKnowledgeChunk $chunk) use ($queryVector) {
                $vector = $chunk->embeddingVector();
                if ($vector === []) {
                    return null;
                }

                return [
                    'title' => $chunk->article?->title ?? $chunk->portalDocument?->title ?? '',
                    'heading' => $chunk->heading,
                    'content' => $chunk->content,
                    'score' => self::cosineSimilarity($queryVector, $vector),
                ];
            })
            ->filter()
            ->filter(fn ($row) => $row['score'] >= $floor)
            ->sortByDesc('score')
            ->values();

        return $scored->take($k);
    }

    /**
     * Cosine similarity between two equal-length vectors. A pure function so
     * it can be verified against known vectors in isolation from embeddings,
     * chunking or the database.
     *
     * @param  array<int,float>  $a
     * @param  array<int,float>  $b
     */
    public static function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
