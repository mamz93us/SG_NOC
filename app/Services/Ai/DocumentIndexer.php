<?php

namespace App\Services\Ai;

use App\Models\AiKnowledgeChunk;
use App\Models\PortalDocument;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extracts text from published PDF documents in the employee library
 * (`portal_documents`) and indexes it the same way KnowledgeIndexer indexes
 * hand-written articles — chunk, embed, upsert, skip unchanged content.
 *
 * This is the ONE place PDF text nobody has reviewed line by line enters the
 * knowledge base, so its output is a chunk row like any other: wrapped in
 * `<reference>` delimiters by KnowledgeRetriever/AssistantAgent's prompt, not
 * trusted as instructions, the same guard already in place for articles.
 */
class DocumentIndexer
{
    public function __construct(private AzureOpenAiClient $client) {}

    /** Chunks, embeds and stores one document's current PDF text. Unpublishing or non-PDFs remove their chunks. */
    public function indexDocument(PortalDocument $document): void
    {
        if (! $document->is_published || ! $document->isPdf()) {
            $document->chunks()->delete();

            return;
        }

        $text = $this->extractText($document);

        if ($text === null) {
            return; // extraction failure already logged; leave existing chunks in place
        }

        $pieces = AiChunker::chunk($text);

        $existing = $document->chunks()->get()->keyBy('content_hash');
        $keepHashes = [];

        $toEmbed = [];
        foreach ($pieces as $piece) {
            $hash = hash('sha256', $piece['heading'].'|'.$piece['content']);
            $keepHashes[] = $hash;

            if ($existing->has($hash)) {
                continue;
            }

            $toEmbed[$hash] = $piece;
        }

        if ($toEmbed !== []) {
            try {
                $vectors = $this->client->embed(array_map(fn ($p) => $p['content'], array_values($toEmbed)));
            } catch (Throwable $e) {
                Log::warning('DocumentIndexer: embedding failed', [
                    'portal_document_id' => $document->id,
                    'error' => $e->getMessage(),
                ]);

                return;
            }

            $hashes = array_keys($toEmbed);
            foreach (array_values($toEmbed) as $i => $piece) {
                $chunk = new AiKnowledgeChunk([
                    'portal_document_id' => $document->id,
                    'heading' => $piece['heading'],
                    'content' => $piece['content'],
                    'content_hash' => $hashes[$i],
                    'token_count' => (int) ceil(mb_strlen($piece['content']) / 4),
                    'audience' => $document->audience,
                    'audience_branch_id' => $document->audience_branch_id,
                    'audience_department_id' => $document->audience_department_id,
                ]);
                $chunk->setEmbeddingVector($vectors[$i] ?? []);
                $chunk->save();
            }
        }

        $document->chunks()->whereNotIn('content_hash', $keepHashes)->delete();

        $document->chunks()->update([
            'audience' => $document->audience,
            'audience_branch_id' => $document->audience_branch_id,
            'audience_department_id' => $document->audience_department_id,
        ]);
    }

    /** Every published PDF, indexed; every chunk belonging to a document no longer eligible, removed. */
    public function reindexAll(): int
    {
        $documents = PortalDocument::query()
            ->where('is_published', true)
            ->whereNotNull('file_path')
            ->get()
            ->filter(fn (PortalDocument $d) => $d->isPdf());

        foreach ($documents as $document) {
            $this->indexDocument($document);
        }

        AiKnowledgeChunk::query()
            ->whereNotNull('portal_document_id')
            ->whereNotIn('portal_document_id', $documents->pluck('id'))
            ->delete();

        return $documents->count();
    }

    /** Null on any failure (missing file, corrupt/encrypted PDF, parser exception) — logged, never thrown. */
    private function extractText(PortalDocument $document): ?string
    {
        if (! $document->file_path || ! Storage::disk('private')->exists($document->file_path)) {
            Log::warning('DocumentIndexer: file missing', ['portal_document_id' => $document->id]);

            return null;
        }

        try {
            $pdf = (new Parser)->parseContent(Storage::disk('private')->get($document->file_path));
            $text = trim($pdf->getText());
        } catch (Throwable $e) {
            Log::warning('DocumentIndexer: PDF parse failed', [
                'portal_document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        return $text !== '' ? $text : null;
    }
}
