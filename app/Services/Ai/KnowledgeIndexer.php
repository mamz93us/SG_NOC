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

    /** Chunks, embeds and stores one article's current content. Unpublishing removes its chunks. */
    public function indexArticle(AiKnowledgeArticle $article): void
    {
        if (! $article->is_published) {
            $article->chunks()->delete();

            return;
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

                return; // leave existing chunks in place rather than half-index
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
    }

    public function reindexAll(): void
    {
        AiKnowledgeArticle::query()->each(fn (AiKnowledgeArticle $a) => $this->indexArticle($a));
    }
}
