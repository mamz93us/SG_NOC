<?php

namespace App\Services\Archive\Ai;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiBatch;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reading history, and proposing values for fields nobody ever filled in.
 *
 * Both are the same act — spend money across many documents — so both are a
 * batch: estimated before it starts, costed while it runs, and stopped by the
 * budget rather than by a bill arriving. A batch is worked in slices by the
 * scheduler, so it survives being interrupted and never holds a request.
 *
 * The difference between the two:
 *
 *   read — the pages become text, and the archive becomes word-searchable.
 *          Nothing is written to any document.
 *   fill — the same reading, plus one AI call per document proposing values
 *          for its empty fields. Those are PROPOSALS. Nothing reaches a
 *          document until a person approves it in the review queue.
 *
 * That second rule is the important one. These are invoices and contracts, and
 * a confident machine is still a machine: the value it proposes is recorded
 * with the page it was read off, so a reviewer can look rather than trust.
 */
class BatchRunner
{
    /** Documents to touch in one slice before handing the budget back. */
    private const DOCUMENTS_PER_SLICE = 25;

    /** Pages of one document to read before moving on; the rest waits its turn. */
    private const PAGES_PER_DOCUMENT = 10;

    public function __construct(
        private ?PageReader $reader = null,
        private ?FieldExtractor $extractor = null,
    ) {
        $this->reader ??= new PageReader;
        $this->extractor ??= new FieldExtractor;
    }

    /**
     * Estimate a batch before anybody commits to it.
     *
     * Counted rather than guessed where it can be: the documents are a real
     * count, and pages come from what the files record. ArcMate stored 0 for
     * every page count, so for a mirrored archive this leans on an average of
     * what has already been read — and the page says it is an estimate.
     *
     * @param  array<string,mixed>  $filters
     * @return array{documents:int, pages:int, cost:float, known_pages:bool}
     */
    public function estimate(Archive $archive, string $type, array $filters = [], array $fieldIds = [], int $maxDocuments = 0): array
    {
        $documents = $this->documentQuery($archive, $type, $filters, $fieldIds)->count();

        // The figure on the page has to be what the batch will actually do.
        if ($maxDocuments > 0) {
            $documents = min($documents, $maxDocuments);
        }

        $recordedPages = (int) ArchiveFile::query()
            ->where('archive_id', $archive->getKey())
            ->whereNotNull('page_count')
            ->avg('page_count');

        $averagePages = $recordedPages > 0 ? $recordedPages : 2;
        $pages = $documents * $averagePages;

        return [
            'documents' => $documents,
            'pages' => $pages,
            'cost' => ArchiveAiSettings::get()->estimateFor($pages),
            'known_pages' => $recordedPages > 0,
        ];
    }

    /**
     * Work one slice of a batch.
     *
     * @param  callable|null  $shouldStop  true when the time budget is spent
     * @return array{documents:int, pages:int, cost:float, finished:bool, reason:?string}
     */
    public function run(ArchiveAiBatch $batch, ?callable $shouldStop = null): array
    {
        $shouldStop ??= static fn (): bool => false;

        $stats = ['documents' => 0, 'pages' => 0, 'cost' => 0.0, 'finished' => false, 'reason' => null];
        $settings = ArchiveAiSettings::get();
        $archive = $batch->archive;

        if (! $archive || ! $archive->readable) {
            $batch->finish(ArchiveAiBatch::STATUS_FAILED, 'The archive is not readable.');
            $stats['reason'] = 'The archive is not readable.';

            return $stats;
        }

        if (! $archive->ai_reading) {
            $batch->finish(ArchiveAiBatch::STATUS_FAILED, 'AI is switched off for this archive.');
            $stats['reason'] = 'AI is switched off for this archive.';

            return $stats;
        }

        $fields = $this->fields($archive, $batch);

        for ($n = 0; $n < self::DOCUMENTS_PER_SLICE; $n++) {
            if ($shouldStop()) {
                $stats['reason'] = 'Time budget spent; continues next run.';
                break;
            }

            // Checked per document, not once per slice: a batch runs for hours
            // and the month's budget can be spent by something else meanwhile.
            // Re-read rather than $settings->fresh(), which returns null if the
            // row has gone and would take the worker down with it.
            if (! ArchiveAiSettings::get()->withinBudget()) {
                $batch->finish(ArchiveAiBatch::STATUS_OVER_BUDGET, 'The month\'s AI budget has been spent.');
                $stats['reason'] = 'The month\'s AI budget has been spent.';

                return $stats;
            }

            $document = $this->nextDocument($batch, $archive, $fields);

            if (! $document) {
                $batch->finish(ArchiveAiBatch::STATUS_DONE);
                $stats['finished'] = true;
                $stats['reason'] = 'Finished.';

                return $stats;
            }

            // Whether this is a document the batch has not worked before. A read
            // batch takes ten pages of a document at a time and then picks the
            // SAME document again, so counting every turn would make a 30-page
            // document three of them — and a limit of a hundred stop at thirty.
            $isNewDocument = (int) $batch->current_document_id !== (int) $document->getKey();

            // The batch's own limit — "only the newest hundred".
            //
            // Checked against the batch row rather than this slice, because a
            // batch is worked a slice a minute: a limit counted per slice would
            // mean a hundred every minute until the archive ran out.
            //
            // And checked HERE, once a document has been chosen and only when it
            // is a new one, so the hundredth document is finished rather than
            // abandoned part-read. Stopping before the choice cut the last
            // document off after ten pages and marked the batch done, which
            // leaves a document half-searchable with nothing saying so.
            $limit = (int) $batch->max_documents;

            if ($isNewDocument && $limit > 0 && (int) $batch->documents_done >= $limit) {
                $batch->finish(ArchiveAiBatch::STATUS_DONE);
                $stats['finished'] = true;
                $stats['reason'] = 'Reached its limit of '.$limit.' document(s).';

                return $stats;
            }

            $done = $this->document($batch, $document, $fields);

            $stats['documents'] += $isNewDocument ? 1 : 0;
            $stats['pages'] += $done['pages'];
            $stats['cost'] += $done['cost'];

            $batch->addProgress(
                $done['pages'],
                $done['cost'],
                $isNewDocument ? 1 : 0,
                $document->getKey(),
            );
        }

        return $stats;
    }

    // ─── One document ────────────────────────────────────────────

    /**
     * @param  array<int,ArchiveField>  $fields
     * @return array{pages:int, cost:float}
     */
    private function document(ArchiveAiBatch $batch, ArchiveDocument $document, array $fields): array
    {
        $pages = 0;
        $cost = 0.0;
        $text = [];
        $outOfBudget = false;

        foreach ($document->files as $file) {
            $result = $this->reader->textFor($file, self::PAGES_PER_DOCUMENT);

            $pages += $result['read']['pages_read'];
            $cost += $result['read']['cost'];

            if (trim($result['text']) !== '') {
                $text[] = $result['text'];
            }

            // The budget is the ONLY reason to abandon a document part-read.
            // Every other reason PageReader stops is about one file — it reached
            // the page limit for this go, or the file has no pages in it at all
            // — and the document's other files must still be read. Reading its
            // `stopped` as "stop here" skipped the PDF behind an attached .msg.
            if (! ArchiveAiSettings::get()->withinBudget()) {
                $outOfBudget = true;
                break;
            }
        }

        // Reading alone is the whole job for a `read` batch: the words are now
        // searchable, and nothing about the document has been changed.
        if ($batch->type !== ArchiveAiBatch::TYPE_FILL || $fields === []) {
            $this->settle($document, $outOfBudget);

            return ['pages' => $pages, 'cost' => $cost];
        }

        // Out of money before the extraction call, which is the expensive half.
        // The reading is kept and the proposing waits for the next budget,
        // rather than the document being written off as done.
        if ($outOfBudget) {
            $this->settle($document, true);

            return ['pages' => $pages, 'cost' => $cost];
        }

        if ($text === []) {
            // Nothing readable came back, so there is nothing to propose from.
            // Still recorded as looked-at: otherwise this document is chosen
            // again on every slice for as long as the batch lives.
            $this->store($batch, $document, [], $fields);
            $this->settle($document, false);

            return ['pages' => $pages, 'cost' => $cost];
        }

        try {
            $proposals = $this->extractor->propose(
                implode(PHP_EOL.PHP_EOL, $text),
                $fields,
                $document->archive?->displayName() ?? '',
            );

            $this->store($batch, $document, $proposals, $fields);
            $cost += $this->charge($document);
        } catch (\Throwable $e) {
            Log::warning('[archive] fill failed for document '.$document->getKey().': '.$e->getMessage());
        }

        $this->settle($document, false);

        return ['pages' => $pages, 'cost' => $cost];
    }

    /**
     * Record what AI proposed — as proposals, never as values.
     *
     * One row per field that was asked about, including the ones the document
     * does not show: those are `not_found`, which keeps the batch from choosing
     * the same document again on every slice (see that constant), and tells a
     * reviewer the field was looked for rather than missed.
     *
     * @param  array<string,array{value:string, confidence:int, page:?int}>  $proposals
     * @param  array<int,ArchiveField>  $fields
     */
    private function store(ArchiveAiBatch $batch, ArchiveDocument $document, array $proposals, array $fields): void
    {
        Auditor::withoutAuditing(function () use ($batch, $document, $proposals, $fields) {
            foreach ($fields as $field) {
                $row = ArchiveAiProposal::firstOrNew([
                    'archive_document_id' => $document->getKey(),
                    'archive_field_id' => $field->getKey(),
                ]);

                // Never touch one a person has already decided. Two batches can
                // cover the same field, and overwriting here would reset an
                // approval to pending and throw the reviewer's work away.
                if ($row->exists && $row->isDecided()) {
                    continue;
                }

                $proposed = $proposals[$field->key] ?? null;
                $value = $proposed ? trim((string) $proposed['value']) : '';

                $row->fill($value !== '' ? [
                    'archive_ai_batch_id' => $batch->getKey(),
                    'value' => mb_substr($value, 0, 250),
                    'confidence' => max(0, min(100, (int) $proposed['confidence'])),
                    'evidence_page' => $proposed['page'],
                    'status' => ArchiveAiProposal::STATUS_PENDING,
                ] : [
                    'archive_ai_batch_id' => $batch->getKey(),
                    'value' => null,
                    'confidence' => null,
                    'evidence_page' => null,
                    'status' => ArchiveAiProposal::STATUS_NOT_FOUND,
                ])->save();
            }
        });
    }

    /**
     * What one extraction call cost, recorded where the budget can see it.
     *
     * Priced as one page: it is a single request over text that has already been
     * paid for, and a page is the unit the whole budget is keyed in. It has to
     * reach archive_ai_usage and not only the batch row — withinBudget() reads
     * usage, so a cost kept on the batch alone would mean the cap counted
     * reading and never counted filling.
     */
    private function charge(ArchiveDocument $document): float
    {
        $cost = ArchiveAiSettings::get()->pageCost();

        ArchiveAiUsage::record(
            ArchiveAiUsage::FEATURE_FILL,
            $cost,
            archiveId: $document->archive_id,
        );

        return $cost;
    }

    // ─── Choosing what to work on ────────────────────────────────

    /**
     * The next document this batch has not dealt with.
     *
     * "Dealt with" differs by type, and getting it wrong means either doing the
     * same document forever or skipping most of them: a read batch is done with
     * a document when its files have text, a fill batch when it has proposals.
     *
     * @param  array<int,ArchiveField>  $fields
     */
    private function nextDocument(ArchiveAiBatch $batch, Archive $archive, array $fields): ?ArchiveDocument
    {
        return $this->documentQuery($archive, $batch->type, (array) $batch->filters, array_map(
            fn (ArchiveField $field) => $field->getKey(),
            $fields
        ))->with('files')->first();
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<int,int>  $fieldIds
     */
    private function documentQuery(Archive $archive, string $type, array $filters, array $fieldIds)
    {
        $query = ArchiveDocument::query()
            ->where('archive_id', $archive->getKey())
            ->where('status', ArchiveDocument::STATUS_ACTIVE)
            ->orderByDesc('captured_at')
            ->orderByDesc('id');

        if ($from = ($filters['from'] ?? null)) {
            $query->where('captured_at', '>=', $from.' 00:00:00');
        }

        if ($to = ($filters['to'] ?? null)) {
            $query->where('captured_at', '<=', $to.' 23:59:59');
        }

        if ($type === ArchiveAiBatch::TYPE_FILL && $fieldIds !== []) {
            // Documents with at least one chosen field that is both empty and
            // has never been asked about. Two conditions, and they belong in one
            // correlated exists per FIELD rather than two document-wide ones:
            // asked per document, a document holding a proposal for field X is
            // skipped entirely and field Y is never filled in at all.
            //
            // Reading a document to propose a value it already has would be
            // money spent to confirm something already known.
            $query->whereExists(function ($sub) use ($fieldIds) {
                $sub->select(DB::raw(1))
                    ->from('archive_fields')
                    ->whereIn('archive_fields.id', $fieldIds)
                    ->whereNotExists(function ($inner) {
                        $inner->select(DB::raw(1))
                            ->from('archive_document_values')
                            ->whereColumn('archive_document_values.archive_document_id', 'archive_documents.id')
                            ->whereColumn('archive_document_values.archive_field_id', 'archive_fields.id')
                            ->whereNotNull('archive_document_values.value_text')
                            ->where('archive_document_values.value_text', '!=', '');
                    })
                    ->whereNotExists(function ($inner) {
                        $inner->select(DB::raw(1))
                            ->from('archive_ai_proposals')
                            ->whereColumn('archive_ai_proposals.archive_document_id', 'archive_documents.id')
                            ->whereColumn('archive_ai_proposals.archive_field_id', 'archive_fields.id');
                    });
            });

            return $query;
        }

        // A read batch is done with a document once every one of its readable
        // files has text.
        return $query->whereExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('archive_files')
                ->whereColumn('archive_files.archive_document_id', 'archive_documents.id')
                ->whereIn('archive_files.text_status', [ArchiveFile::TEXT_NONE, ArchiveFile::TEXT_PENDING]);
        });
    }

    /**
     * @return array<int,ArchiveField>
     */
    private function fields(Archive $archive, ArchiveAiBatch $batch): array
    {
        $ids = (array) $batch->field_ids;

        if ($ids === []) {
            return [];
        }

        return $archive->fields()->whereIn('id', $ids)->get()->all();
    }

    /**
     * Leave every file in the state that says what should happen to it next.
     *
     * PageReader has already set `done`, `pending` or `unreadable` per file and
     * those are right — `pending` means pages remain, and the next slice carries
     * on with them, which is how a 300-page contract is read ten pages at a
     * time. The one thing it cannot know is that a file yielded NOTHING while
     * there was budget to read it: that file cannot be read at all, and leaving
     * it pending would have the batch choose this document again forever.
     *
     * Marking a part-read file failed is what would have left a 30-page contract
     * with its first ten pages read and the other twenty never looked at, while
     * the batch reported success.
     */
    private function settle(ArchiveDocument $document, bool $outOfBudget): void
    {
        if ($outOfBudget) {
            // The budget stopped us, not the files. Failing them here would
            // permanently write off documents that are perfectly readable.
            return;
        }

        foreach ($document->files as $file) {
            if (! in_array($file->text_status, [ArchiveFile::TEXT_NONE, ArchiveFile::TEXT_PENDING], true)) {
                continue;
            }

            if ((int) $file->pages_read > 0) {
                continue;
            }

            $file->forceFill(['text_status' => ArchiveFile::TEXT_FAILED])->save();
        }
    }
}
