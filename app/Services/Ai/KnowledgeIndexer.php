<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeArticle;
use App\Models\AiKnowledgeChunk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;

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
    /**
     * Characters per embeddings request. Azure counts every request against
     * the deployment's tokens-per-minute quota — 130,000 on NOC2 — and Arabic
     * runs about 0.75 tokens a character. The 68-page labor law imported from
     * PDF came to 428 chunks and ~100k tokens: nearly the whole quota in the one
     * request this used to send, so a longer Arabic document could never have
     * been indexed at all. 30,000 characters is under a quarter of the quota in
     * any language.
     */
    public const BATCH_CHARS = 30000;

    /** Inputs per request, well inside the API's own limit of 2,048. */
    public const BATCH_INPUTS = 100;

    /** A background caller waits this long after HTTP 429, up to this many times, before giving up. */
    private const THROTTLE_WAIT_SECONDS = 20;

    private const THROTTLE_ATTEMPTS = 15;

    public function __construct(private AzureOpenAiClient $client) {}

    /**
     * Chunks, embeds and stores one article's current content. Unpublishing
     * removes its chunks.
     *
     * @param  bool  $waitWhenThrottled  wait out Azure's HTTP 429 rather than fail.
     *                                   Background callers only — the PDF importer
     *                                   and Reindex All; an admin saving the form
     *                                   must not be kept waiting for minutes.
     * @return bool false only when embedding actually failed (e.g. Azure
     *              unreachable or unconfigured) — reindexAll() uses this to
     *              report which articles still need attention.
     */
    public function indexArticle(AiKnowledgeArticle $article, bool $waitWhenThrottled = false): bool
    {
        if (! $article->is_published) {
            $article->chunks()->delete();

            return true;
        }

        // Index every language the article actually has, not just one —
        // embedding similarity drops noticeably across languages even on the
        // same topic, so an English chunk is what an English question needs
        // to match well, and likewise for Arabic. Confirmed live: an
        // English-only question about a topic the English body covered in
        // detail scored well under the relevance floor against Arabic-only
        // chunks of the same article.
        $bodies = array_filter([
            'en' => (string) $article->body,
            'ar' => (string) $article->body_ar,
        ], fn ($body) => trim($body) !== '');

        $pieces = [];
        foreach ($bodies as $locale => $body) {
            foreach (AiChunker::chunk($body) as $piece) {
                $piece['locale'] = $locale;
                $pieces[] = $piece;
            }
        }

        $existing = $article->chunks()->get()->keyBy('content_hash');
        $keepHashes = [];

        // For an imported PDF, where each chunk is in the original — what the
        // assistant cites, since a translated heading may be nowhere in the PDF.
        $locator = PdfSourceLocator::forArticle($article);

        $toEmbed = [];
        foreach ($pieces as $piece) {
            $hash = hash('sha256', $piece['locale'].'|'.$piece['heading'].'|'.$piece['content']);
            $keepHashes[] = $hash;

            $source = $locator?->locate($piece['locale'], $piece['heading'], $piece['content']);
            $piece['source_page'] = $source['page'] ?? null;
            $piece['source_heading'] = $source['heading'] ?? null;

            if ($existing->has($hash)) {
                // Unchanged, so its embedding is still valid; a chunk indexed
                // before sources existed gets its source without re-embedding.
                $chunk = $existing->get($hash);
                if ($chunk->source_page !== $piece['source_page'] || $chunk->source_heading !== $piece['source_heading']) {
                    $chunk->update(['source_page' => $piece['source_page'], 'source_heading' => $piece['source_heading']]);
                }

                continue;
            }

            $toEmbed[$hash] = $piece;
        }

        if ($toEmbed !== []) {
            // Every batch is embedded before any chunk is written, so a failure
            // part-way leaves the existing chunks as they were rather than
            // half-indexed. Vectors are packed as they arrive: ~6 KB a chunk,
            // where a PHP array of 1,536 floats is several times that.
            $embeddings = [];

            foreach (self::batches($toEmbed) as $batch) {
                try {
                    $vectors = $this->embed(array_column($batch, 'content'), $waitWhenThrottled);
                } catch (\Throwable $e) {
                    Log::warning('KnowledgeIndexer: embedding failed', [
                        'article_id' => $article->id,
                        'error' => $e->getMessage(),
                    ]);

                    return false; // leave existing chunks in place rather than half-index
                }

                foreach (array_keys($batch) as $n => $hash) {
                    $embeddings[$hash] = pack('f*', ...($vectors[$n] ?? []));
                }
            }

            foreach ($toEmbed as $hash => $piece) {
                $chunk = new AiKnowledgeChunk([
                    'article_id' => $article->id,
                    'heading' => $piece['heading'],
                    'content' => $piece['content'],
                    'content_hash' => $hash,
                    'locale' => $piece['locale'],
                    'source_page' => $piece['source_page'],
                    'source_heading' => $piece['source_heading'],
                    'token_count' => (int) ceil(mb_strlen($piece['content']) / 4),
                    'audience' => $article->audience,
                    'audience_branch_id' => $article->audience_branch_id,
                    'audience_department_id' => $article->audience_department_id,
                ]);
                $chunk->embedding = $embeddings[$hash]; // already packed, as setEmbeddingVector() would
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
     * Pieces keyed by content hash, cut in order into embeddings requests of
     * at most $maxChars characters and $maxInputs inputs. A piece longer than
     * $maxChars on its own still goes, alone.
     *
     * @param  array<string, array{content:string}>  $pieces
     * @return array<int, array<string, array{content:string}>>
     */
    public static function batches(array $pieces, int $maxChars = self::BATCH_CHARS, int $maxInputs = self::BATCH_INPUTS): array
    {
        $batches = [];
        $batch = [];
        $chars = 0;

        foreach ($pieces as $hash => $piece) {
            $length = mb_strlen($piece['content']);

            if ($batch !== [] && ($chars + $length > $maxChars || count($batch) >= $maxInputs)) {
                $batches[] = $batch;
                $batch = [];
                $chars = 0;
            }

            $batch[$hash] = $piece;
            $chars += $length;
        }

        if ($batch !== []) {
            $batches[] = $batch;
        }

        return $batches;
    }

    /**
     * @param  array<int,string>  $texts
     * @return array<int,array<int,float>>
     */
    private function embed(array $texts, bool $waitWhenThrottled): array
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->client->embed($texts);
            } catch (RuntimeException $e) {
                if (! $waitWhenThrottled || ! str_contains($e->getMessage(), 'HTTP 429') || $attempt >= self::THROTTLE_ATTEMPTS) {
                    throw $e;
                }

                Sleep::for(self::THROTTLE_WAIT_SECONDS)->seconds();
            }
        }
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
            if ($this->indexArticle($a, waitWhenThrottled: true)) {
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
