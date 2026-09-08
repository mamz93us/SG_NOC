<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeChunk;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Embeds a question and ranks it against every audience-visible chunk by
 * cosine similarity, computed in PHP over unpacked float32 blobs — see the
 * ai_knowledge_chunks migration for why there is no vector column.
 *
 * An empty result (nothing clears RELEVANCE_FLOOR) is not a failure — it is
 * what authorises AssistantAgent to let draft_ticket run, and it is recorded
 * in ai_knowledge_gaps so IT knows what to write next.
 */
class KnowledgeRetriever
{
    /**
     * Cosine scores below this are treated as "did not find it", not a weak
     * match.
     *
     * 0.5, not something closer to 1.0: text-embedding-3-small's cosine
     * scores for a genuinely correct query-to-document match commonly land
     * around 0.5-0.7, not 0.8+ — a naive higher floor rejects real answers.
     * Measured directly against a real published article: a well-formed
     * query ("steps to apply for vacation in Oracle HRMS") scored 0.71
     * against its correct chunk, which the previous floor of 0.72 rejected
     * by 0.01, sending the employee to a needless ticket draft instead of
     * the article that already answered the question.
     */
    public const RELEVANCE_FLOOR = 0.5;

    public function __construct(private AzureOpenAiClient $client) {}

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
            ->filter(fn ($row) => $row['score'] >= self::RELEVANCE_FLOOR)
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
