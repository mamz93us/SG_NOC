<?php

namespace App\Services\Archive;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveSource;
use App\Services\Archive\ArcMate\ArcMateDates;
use App\Services\Archive\ArcMate\ArcMatePaths;
use App\Services\Archive\ArcMate\ArcMateReader;
use App\Services\Archive\ArcMate\ReadsArcMate;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\DB;

/**
 * Copies one ArcMate project into the NOC's own tables, incrementally and
 * read-only.
 *
 * Four passes per run, in this order and for these reasons:
 *
 *  1. **Documents**, by arcId watermark. New rows only.
 *  2. **Files**, by their own arcId watermark — and this pass STOPS at the
 *     first file whose document has not been copied yet, leaving the watermark
 *     just before it. Document and file ids are independent sequences in
 *     ArcMate, so during a backfill files routinely run ahead of documents;
 *     skipping them would lose them, and querying ArcMate per orphan would be a
 *     round trip per row. Stopping is self-healing: the next run has the
 *     document and carries on.
 *  3. **Changed documents**, from tblDocumentsTrack. Without this an edit never
 *     arrives at all — correcting an invoice number in ArcMate changes a row in
 *     place and its arcId does not move.
 *  4. **Deletions**, from tblDeletedObjects, which is the only place a deletion
 *     is recorded.
 *
 * Bulk writes go through the query builder rather than Eloquent: SPS Invoices
 * alone is 513,381 documents and ~594,000 files, and saving those one model at
 * a time would be hours of object hydration. The whole thing runs inside
 * Auditor::withoutAuditing(), so a backfill writes no audit rows while a
 * person's later edit still does.
 *
 * The one thing this service will NOT do is overwrite human work. A value whose
 * source is `person` or `ai_approved` is never replaced by ArcMate's copy; the
 * document is flagged `needs_review` instead, and someone decides.
 */
class ArchiveSyncService
{
    /** Stop a pass at this many rows even if the budget allows more. */
    private const MAX_BATCHES_PER_PASS = 200;

    public function __construct(private ?ArchiveSource $source = null) {}

    /**
     * Run every pass for one archive until it is caught up or the budget runs out.
     *
     * @param  callable|null  $shouldStop  returns true when the time budget is spent
     * @return array{documents:int, files:int, updated:int, deleted:int, caught_up:bool}
     */
    public function sync(Archive $archive, ReadsArcMate $reader, int $batch = 1000, ?callable $shouldStop = null): array
    {
        $shouldStop ??= static fn (): bool => false;

        $stats = ['documents' => 0, 'files' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => 0, 'caught_up' => false];
        $documentsExhausted = false;
        $filesExhausted = false;
        $skipped = 0;

        Auditor::withoutAuditing(function () use ($archive, $reader, $batch, $shouldStop, &$stats) {
            $columns = $this->columns($archive);

            $stats['documents'] = $this->syncDocuments($archive, $reader, $columns, $batch, $shouldStop, $documentsExhausted);

            if (! $shouldStop()) {
                $stats['files'] = $this->syncFiles($archive, $reader, $batch, $shouldStop, $filesExhausted, $skipped);
            }

            if (! $shouldStop()) {
                $stats['updated'] = $this->syncChanges($archive, $reader, $columns, $batch, $shouldStop);
            }

            if (! $shouldStop()) {
                $stats['deleted'] = $this->syncDeletions($archive, $reader, $batch, $shouldStop);
            }

            $stats['skipped'] = $skipped;

            // "Caught up" has to mean there is nothing left to copy, not merely
            // that we did not run out of time. Read the loose way, one pass that
            // stopped early marked a 3%-complete mirror as finished.
            $stats['caught_up'] = ! $shouldStop() && $documentsExhausted && $filesExhausted;
        });

        if ($stats['caught_up'] && $archive->backfill_done_at === null) {
            $archive->forceFill(['backfill_done_at' => now()])->save();
        }

        $this->refreshCounts($archive);

        return $stats;
    }

    // ─── Pass 1: new documents ───────────────────────────────────

    /** @param array<int,ArchiveField> $columns arcmate column => field */
    private function syncDocuments(Archive $archive, ReadsArcMate $reader, array $columns, int $batch, callable $shouldStop, ?bool &$exhausted = null): int
    {
        $exhausted = false;
        $total = 0;

        for ($pass = 0; $pass < self::MAX_BATCHES_PER_PASS; $pass++) {
            if ($shouldStop()) {
                break;
            }

            $rows = $reader->documentsAfter((int) $archive->last_doc_arc_id, array_keys($columns), $batch);

            if ($rows === []) {
                $exhausted = true;
                break;
            }

            $this->writeDocuments($archive, $rows, $columns);

            $highest = (int) end($rows)->arcId;
            $archive->forceFill(['last_doc_arc_id' => $highest])->save();
            $total += count($rows);
        }

        return $total;
    }

    /**
     * @param  array<int,object>  $rows
     * @param  array<string,ArchiveField>  $columns
     */
    private function writeDocuments(Archive $archive, array $rows, array $columns): void
    {
        $now = now();
        $documents = [];

        foreach ($rows as $row) {
            $documents[] = [
                'archive_id' => $archive->getKey(),
                'arcmate_id' => (int) $row->arcId,
                'status' => ArchiveDocument::STATUS_ACTIVE,
                'file_count' => (int) ($row->arcFileCount ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('archive_documents')->upsert(
            $documents,
            ['archive_id', 'arcmate_id'],
            ['status', 'file_count', 'updated_at']
        );

        $ids = $this->documentIds($archive, array_column($documents, 'arcmate_id'));

        $this->writeValues($ids, $rows, $columns);
    }

    // ─── Pass 2: new files ───────────────────────────────────────

    /**
     * Copy new files.
     *
     * The ordering rule that makes this safe: file and document arcIds are
     * independent sequences in ArcMate, so a file can appear before its document
     * has been copied. When that happens the pass stops and leaves the watermark
     * before the file, and the next run picks it up once the document pass has
     * caught up.
     *
     * But that only works for a document that is still COMING. ArcMate also holds
     * files whose document can never arrive -- 38 of them in SPS Invoices, all with
     * arcDocumentId 0 -- and waiting for those blocked every later file behind them:
     * the mirror sat at 19,000 of 511,248 files with the watermark frozen, and
     * reported itself caught up. So the two cases are told apart by the document
     * watermark: ahead of it means not copied yet, at or behind it means it is not
     * coming, and the file is skipped rather than waited on.
     *
     * @param  int|null  $skipped  files whose document will never exist
     */
    private function syncFiles(
        Archive $archive,
        ReadsArcMate $reader,
        int $batch,
        callable $shouldStop,
        ?bool &$exhausted = null,
        ?int &$skipped = null,
    ): int {
        $exhausted = false;
        $skipped = 0;
        $total = 0;

        for ($pass = 0; $pass < self::MAX_BATCHES_PER_PASS; $pass++) {
            if ($shouldStop()) {
                break;
            }

            $rows = $reader->filesAfter((int) $archive->last_file_arc_id, $batch);

            if ($rows === []) {
                $exhausted = true;
                break;
            }

            $documentIds = $this->documentIds($archive, array_map(
                fn (object $row) => (int) $row->arcDocumentId,
                $rows
            ));

            $writable = [];
            $waitingFor = null;
            $examined = null;

            foreach ($rows as $row) {
                $documentArcId = (int) $row->arcDocumentId;

                if (! isset($documentIds[$documentArcId])) {
                    // Still coming: the document pass has not reached it. Stop, and
                    // do not move the watermark past a file we did not write.
                    if ($documentArcId > (int) $archive->last_doc_arc_id) {
                        $waitingFor = (int) $row->arcId;
                        break;
                    }

                    // Never coming (see the note above). Skipped, and counted so the
                    // run reports it rather than losing files silently.
                    $examined = (int) $row->arcId;
                    $skipped++;

                    continue;
                }

                $examined = (int) $row->arcId;
                $writable[] = $row;
            }

            if ($writable !== []) {
                $this->writeFiles($archive, $writable, $documentIds);
                $this->stampCaptureTimes($archive, $writable, $documentIds);
                $total += count($writable);
            }

            // Past everything decided about, written or deliberately skipped. Moving
            // only to the last WRITTEN file means a batch that is entirely orphans
            // never advances, and the pass re-reads the same rows for ever.
            if ($examined !== null) {
                $archive->forceFill(['last_file_arc_id' => $examined])->save();
            }

            if ($waitingFor !== null) {
                break;
            }

            if (count($rows) < $batch) {
                $exhausted = true;
                break;
            }
        }

        return $total;
    }

    /**
     * @param  array<int,object>  $rows
     * @param  array<int,int>  $documentIds  arcmate document id => local id
     */
    private function writeFiles(Archive $archive, array $rows, array $documentIds): void
    {
        $now = now();
        $mount = $this->source?->mountPath() ?? (string) config('archive_portal.mount_path');
        $files = [];

        foreach ($rows as $row) {
            $arcFileName = (string) ($row->arcFileName ?? '');

            // Where the file is, checked against the share when it is mounted.
            // A file that cannot be found still gets a row carrying the best
            // guess: the document is real, and a missing file is a fact to show
            // rather than a reason to drop the document.
            $resolved = ArcMatePaths::resolve($mount, $archive->arcmate_folder, $arcFileName)
                ?? ArcMatePaths::bestGuess($mount, $archive->arcmate_folder, $arcFileName);

            $files[] = [
                'archive_id' => $archive->getKey(),
                'archive_document_id' => $documentIds[(int) $row->arcDocumentId],
                'arcmate_id' => (int) $row->arcId,
                'position' => (int) ($row->arcFileOrder ?? 0),
                'original_name' => $this->trim($row->arcOrgName ?? null, 255),
                'disk' => ArchiveFile::DISK_ARCMATE,
                'path' => (string) $resolved,
                'arcmate_path' => (string) $resolved,
                // arcFileSize and arcPageCount are 0 in every row checked on the
                // live database, so they are recorded only when they are real
                // and otherwise left null for the filesystem to answer.
                'size' => ((int) ($row->arcFileSize ?? 0)) ?: null,
                'page_count' => ((int) ($row->arcPageCount ?? 0)) ?: null,
                'arcmate_crc' => $this->trim($row->arcFileCRC ?? null, 40),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('archive_files')->upsert(
            $files,
            ['archive_id', 'arcmate_id'],
            ['archive_document_id', 'position', 'original_name', 'path', 'arcmate_path', 'size', 'page_count', 'arcmate_crc', 'updated_at']
        );
    }

    /**
     * Give each document the time its earliest file was named with.
     *
     * ArcMate's tblDocuments has no date column, so this is where a document's
     * date comes from at all. Only ever moved earlier, because files arrive
     * across runs and the first one is the capture.
     *
     * @param  array<int,object>  $rows
     * @param  array<int,int>  $documentIds
     */
    private function stampCaptureTimes(Archive $archive, array $rows, array $documentIds): void
    {
        $earliest = [];

        foreach ($rows as $row) {
            $moment = ArcMateDates::fromFileName((string) ($row->arcFileName ?? ''));

            if (! $moment) {
                continue;
            }

            $localId = $documentIds[(int) $row->arcDocumentId] ?? null;

            if ($localId === null) {
                continue;
            }

            $stamp = $moment->format('Y-m-d H:i:s');

            if (! isset($earliest[$localId]) || $stamp < $earliest[$localId]) {
                $earliest[$localId] = $stamp;
            }
        }

        foreach ($earliest as $localId => $stamp) {
            DB::table('archive_documents')
                ->where('id', $localId)
                ->where(function ($query) use ($stamp) {
                    $query->whereNull('captured_at')->orWhere('captured_at', '>', $stamp);
                })
                ->update(['captured_at' => $stamp]);
        }
    }

    // ─── Pass 3: changed documents ───────────────────────────────

    /** @param array<string,ArchiveField> $columns */
    private function syncChanges(Archive $archive, ReadsArcMate $reader, array $columns, int $batch, callable $shouldStop): int
    {
        $total = 0;

        for ($pass = 0; $pass < self::MAX_BATCHES_PER_PASS; $pass++) {
            if ($shouldStop()) {
                break;
            }

            $track = $reader->documentTrackAfter((int) $archive->last_doc_track_arc_id, $batch);

            if ($track === []) {
                break;
            }

            $arcIds = array_values(array_unique(array_map(
                fn (object $row) => (int) $row->arcDocumentId,
                $track
            )));

            // Only documents already copied here: a track row for one we have
            // not reached yet is not an edit, it is the document's own creation,
            // and pass 1 will bring it with its current values anyway.
            $known = $this->documentIds($archive, $arcIds);

            if ($known !== []) {
                $rows = $reader->documentsByIds(array_keys($known), array_keys($columns));

                if ($rows !== []) {
                    $this->writeValues($known, $rows, $columns, refreshing: true);
                    $total += count($rows);
                }
            }

            $archive->forceFill(['last_doc_track_arc_id' => (int) end($track)->arcId])->save();

            if (count($track) < $batch) {
                break;
            }
        }

        return $total;
    }

    // ─── Pass 4: deletions ───────────────────────────────────────

    private function syncDeletions(Archive $archive, ReadsArcMate $reader, int $batch, callable $shouldStop): int
    {
        $total = 0;

        for ($pass = 0; $pass < self::MAX_BATCHES_PER_PASS; $pass++) {
            if ($shouldStop()) {
                break;
            }

            $rows = $reader->deletedDocumentsAfter((int) $archive->last_deleted_arc_id, $batch);

            if ($rows === []) {
                break;
            }

            $arcIds = array_map(fn (object $row) => (int) $row->arcObjectId, $rows);

            // Marked, never removed. A deletion in a twenty-year-old system is
            // worth keeping recoverable, and the document stops appearing in
            // searches either way (ArchiveAccess only returns active ones).
            $total += DB::table('archive_documents')
                ->where('archive_id', $archive->getKey())
                ->whereIn('arcmate_id', $arcIds)
                ->where('status', ArchiveDocument::STATUS_ACTIVE)
                ->update([
                    'status' => ArchiveDocument::STATUS_DELETED_IN_ARCMATE,
                    'updated_at' => now(),
                ]);

            $archive->forceFill(['last_deleted_arc_id' => (int) end($rows)->arcId])->save();

            if (count($rows) < $batch) {
                break;
            }
        }

        return $total;
    }

    // ─── Values ──────────────────────────────────────────────────

    /**
     * Write the index values of a batch of documents.
     *
     * @param  array<int,int>  $documentIds  arcmate id => local id
     * @param  array<int,object>  $rows
     * @param  array<string,ArchiveField>  $columns
     * @param  bool  $refreshing  true when re-reading a document that changed,
     *                            which is when human edits have to be protected
     */
    private function writeValues(array $documentIds, array $rows, array $columns, bool $refreshing = false): void
    {
        if ($columns === []) {
            return;
        }

        $now = now();
        $values = [];
        $protected = $refreshing ? $this->protectedValues(array_values($documentIds)) : [];
        $conflicts = [];

        foreach ($rows as $row) {
            $localId = $documentIds[(int) $row->arcId] ?? null;

            if ($localId === null) {
                continue;
            }

            foreach ($columns as $column => $field) {
                $raw = $row->{$column} ?? null;
                $text = $this->trim($raw, 250);

                $key = $localId.':'.$field->getKey();

                // A person (or an approved AI proposal) owns this value. ArcMate
                // does not get to overwrite it — the disagreement is surfaced
                // instead, and someone decides which is right.
                if (isset($protected[$key])) {
                    if ($protected[$key] !== $text) {
                        $conflicts[$localId] = true;
                    }

                    continue;
                }

                $values[] = [
                    'archive_document_id' => $localId,
                    'archive_field_id' => $field->getKey(),
                    'value_text' => $text,
                    'value_date' => $field->isDate() ? ArcMateDates::fromStamp((string) $raw)?->format('Y-m-d') : null,
                    'value_number' => $field->isNumber() ? $this->number($raw) : null,
                    'source' => ArchiveDocumentValue::SOURCE_ARCMATE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($values !== []) {
            DB::table('archive_document_values')->upsert(
                $values,
                ['archive_document_id', 'archive_field_id'],
                ['value_text', 'value_date', 'value_number', 'updated_at']
            );
        }

        if ($conflicts !== []) {
            DB::table('archive_documents')
                ->whereIn('id', array_keys($conflicts))
                ->update([
                    'needs_review' => true,
                    'review_note' => 'ArcMate holds a different value for a field edited here. Nothing was overwritten.',
                    'updated_at' => $now,
                ]);
        }
    }

    /**
     * Values on these documents that ArcMate must not touch.
     *
     * @param  array<int,int>  $documentIds
     * @return array<string,?string> "documentId:fieldId" => current text
     */
    private function protectedValues(array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $rows = DB::table('archive_document_values')
            ->whereIn('archive_document_id', $documentIds)
            ->whereIn('source', [ArchiveDocumentValue::SOURCE_PERSON, ArchiveDocumentValue::SOURCE_AI_APPROVED])
            ->get(['archive_document_id', 'archive_field_id', 'value_text']);

        $protected = [];

        foreach ($rows as $row) {
            $protected[$row->archive_document_id.':'.$row->archive_field_id] = $row->value_text;
        }

        return $protected;
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * The archive's fields that map to an ArcMate column, keyed by that column.
     *
     * Only columns ArcMateReader considers safe to name in SQL survive, so a
     * mis-typed arcmate_column in the database cannot reach a query.
     *
     * @return array<string,ArchiveField>
     */
    private function columns(Archive $archive): array
    {
        $columns = [];

        foreach ($archive->fields as $field) {
            $column = strtoupper(trim((string) $field->arcmate_column));

            if ($column !== '' && ArcMateReader::safeColumn($column)) {
                $columns[$column] = $field;
            }
        }

        return $columns;
    }

    /**
     * @param  array<int,int>  $arcIds
     * @return array<int,int> arcmate id => local id
     */
    private function documentIds(Archive $archive, array $arcIds): array
    {
        $arcIds = array_values(array_unique(array_filter($arcIds)));

        if ($arcIds === []) {
            return [];
        }

        return DB::table('archive_documents')
            ->where('archive_id', $archive->getKey())
            ->whereIn('arcmate_id', $arcIds)
            ->pluck('id', 'arcmate_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function refreshCounts(Archive $archive): void
    {
        // One implementation on the model, shared with the recount task and with
        // filing — this one used to omit byte_total, so the Transfer page and the
        // archive card disagreed about the same archive.
        $archive->refreshCounts();
    }

    private function trim(mixed $value, int $length): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $length);
    }

    /** ArcMate keeps amounts as text, so anything unparseable is simply not a number. */
    private function number(mixed $value): ?string
    {
        $text = str_replace([',', ' '], '', trim((string) ($value ?? '')));

        return is_numeric($text) ? $text : null;
    }
}
