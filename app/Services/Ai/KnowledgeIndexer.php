<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use Illuminate\Support\Facades\Log;

/**
 * Chunk -> embed -> upsert. Called after every article save from the admin
 * CRUD, and available for a full `reindexAll()` sweep.
 *
 * Re-embeds only chunks whose text actually changed (content_hash), so
 * editing one paragraph of a long policy does not re-spend the whole
 * article's embedding budget.
 */
class KnowledgeIndexer
{
    public function __construct(private AzureOpenAiClient $client) {}

    /**
     * Chunks, embeds and stores one article's current content. Unpublishing
     * removes its chunks.
     *
     * @return bool false only when embedding actually failed (e.g. Azure
     *              unreachable or unconfigured) — reindexAll() uses this to
     *              report which articles still need attention.
     */
    public function indexArticle(AiKnowledgeArticle $article): bool
    {
        if (! $article->is_published) {
            $article->chunks()->delete();

            return true;
        }

        $locale = $article->body_ar ? 'ar' : 'en';
        $pieces = AiChunker::chunk($article->bodyFor($locale));

        $existing = $article->chunks()->get()->keyBy('content_hash');
        $keepHashes = [];

        $toEmbed = [];
        foreach ($pieces as $piece) {
            $hash = hash('sha256', $piece['heading'].'|'.$piece['content']);
            $keepHashes[] = $hash;

            if ($existing->has($hash)) {
                continue; // unchanged — its embedding is still valid
            }

            $toEmbed[$hash] = $piece;
        }

        if ($toEmbed !== []) {
            try {
                $vectors = $this->client->embed(array_map(fn ($p) => $p['content'], array_values($toEmbed)));
            } catch (\Throwable $e) {
                Log::warning('KnowledgeIndexer: embedding failed', [
                    'article_id' => $article->id,
                    'error' => $e->getMessage(),
                ]);

                return false; // leave existing chunks in place rather than half-index
            }

            $hashes = array_keys($toEmbed);
            foreach (array_values($toEmbed) as $i => $piece) {
                $chunk = new AiKnowledgeChunk([
                    'article_id' => $article->id,
                    'heading' => $piece['heading'],
                    'content' => $piece['content'],
                    'content_hash' => $hashes[$i],
                    'token_count' => (int) ceil(mb_strlen($piece['content']) / 4),
                    'audience' => $article->audience,
                    'audience_branch_id' => $article->audience_branch_id,
                    'audience_department_id' => $article->audience_department_id,
                ]);
                $chunk->setEmbeddingVector($vectors[$i] ?? []);
                $chunk->save();
            }
        }

        // Anything no longer produced by the current body is stale.
        $article->chunks()->whereNotIn('content_hash', $keepHashes)->delete();

        // Audience may have changed without the body changing — keep the
        // denormalised copy in step so retrieval filters stay correct.
        $article->chunks()->update([
            'audience' => $article->audience,
            'audience_branch_id' => $article->audience_branch_id,
            'audience_department_id' => $article->audience_department_id,
        ]);

        return true;
    }

    /**
     * Full sweep over every article — the recovery path for articles whose
     * embedding failed silently (e.g. saved before Azure OpenAI was
     * configured, or during an outage). Cheap to re-run: indexArticle() only
     * re-embeds chunks whose content_hash actually changed.
     *
     * @return array{indexed:int, failed:int, failed_titles:array<int,string>}
     */
    public function reindexAll(): array
    {
        $indexed = 0;
        $failedTitles = [];

        AiKnowledgeArticle::query()->each(function (AiKnowledgeArticle $a) use (&$indexed, &$failedTitles) {
            if ($this->indexArticle($a)) {
                $indexed++;
            } else {
                $failedTitles[] = $a->title;
            }
        });

        return [
            'indexed' => $indexed,
            'failed' => count($failedTitles),
            'failed_titles' => $failedTitles,
        ];
    }
}
