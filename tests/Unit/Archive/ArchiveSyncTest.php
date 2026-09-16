<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Services\Archive\ArchiveSyncService;
use App\Services\Archive\ArcMate\ReadsArcMate;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * The ArcMate mirror, end to end, against a fake ArcMate.
 *
 * A fake rather than an in-memory SQLite "ArcMate" — the trick the BioTime
 * tests use — because ArcMateReader speaks SQL Server only (`SELECT TOP (n)`,
 * `DATALENGTH`, bracket-quoted columns). The interface exists for exactly this:
 * the logic worth testing is in ArchiveSyncService, and here it meets a fake
 * shaped like the real database, including the parts of that shape that are
 * surprising:
 *
 *  - document ids and file ids are independent sequences, so files routinely
 *    run ahead of the documents they belong to;
 *  - tblDocuments has no date column, so a document's date comes from the name
 *    its file was saved under;
 *  - an edit changes a row in place without moving its arcId, so it is only
 *    visible through tblDocumentsTrack;
 *  - a deletion leaves the row and records a line in tblDeletedObjects.
 */
uses(Tests\TestCase::class);

/** A fake ArcMate project database: rows in, objects out. */
class FakeArcMate implements ReadsArcMate
{
    public function __construct(
        public array $documents = [],
        public array $files = [],
        public array $track = [],
        public array $deleted = [],
    ) {}

    public function documentsAfter(int $afterArcId, array $columns, int $limit): array
    {
        return $this->page($this->documents, 'arcId', $afterArcId, $limit);
    }

    public function documentsByIds(array $arcIds, array $columns): array
    {
        $ids = array_map('intval', $arcIds);

        return array_values(array_map(
            fn (array $row) => (object) $row,
            array_filter($this->documents, fn (array $row) => in_array((int) $row['arcId'], $ids, true))
        ));
    }

    public function filesAfter(int $afterArcId, int $limit): array
    {
        return $this->page($this->files, 'arcId', $afterArcId, $limit);
    }

    public function filesForDocuments(array $documentArcIds): array
    {
        $ids = array_map('intval', $documentArcIds);

        return array_values(array_map(
            fn (array $row) => (object) $row,
            array_filter($this->files, fn (array $row) => in_array((int) $row['arcDocumentId'], $ids, true))
        ));
    }

    public function documentTrackAfter(int $afterArcId, int $limit): array
    {
        return $this->page($this->track, 'arcId', $afterArcId, $limit);
    }

    public function deletedDocumentsAfter(int $afterArcId, int $limit): array
    {
        return $this->page($this->deleted, 'arcId', $afterArcId, $limit);
    }

    public function earliestTrackDates(array $documentArcIds): array
    {
        $dates = [];

        foreach ($this->track as $row) {
            $id = (int) $row['arcDocumentId'];
            if (! isset($dates[$id]) || $row['arcDate'] < $dates[$id]) {
                $dates[$id] = (string) $row['arcDate'];
            }
        }

        return $dates;
    }

    public function documentCount(): int
    {
        return count($this->documents);
    }

    public function fileCount(): int
    {
        return count($this->files);
    }

    private function page(array $rows, string $key, int $after, int $limit): array
    {
        $rows = array_values(array_filter($rows, fn (array $row) => (int) $row[$key] > $after));
        usort($rows, fn (array $a, array $b) => $a[$key] <=> $b[$key]);

        return array_map(fn (array $row) => (object) $row, array_slice($rows, 0, $limit));
    }
}

beforeEach(function () {
    ArchiveTestSchema::create();

    // An archive shaped like SPS Invoices: an invoice number on S1 and a PO
    // number on S2, which is the real design of the live project.
    $this->archive = Archive::create([
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'arcmate_folder' => 'SPS_Invoices',
        'arcmate_database' => 'AM7220130318_Invoices',
        'mode' => Archive::MODE_MIRROR,
    ]);

    ArchiveField::create([
        'archive_id' => $this->archive->id,
        'key' => 'invoiceno',
        'label' => 'InvoiceNo',
        'type' => ArchiveField::TYPE_TEXT,
        'arcmate_column' => 'S1',
        'sort_order' => 1,
    ]);
    ArchiveField::create([
        'archive_id' => $this->archive->id,
        'key' => 'pono',
        'label' => 'PONo',
        'type' => ArchiveField::TYPE_TEXT,
        'arcmate_column' => 'S2',
        'sort_order' => 2,
    ]);

    $this->archive->load('fields');
    $this->sync = new ArchiveSyncService;
});

afterEach(fn () => ArchiveTestSchema::drop());

function syncFake(FakeArcMate $arcmate, Archive $archive, ArchiveSyncService $sync, int $batch = 1000): array
{
    $archive->load('fields');

    return $sync->sync($archive, $arcmate, $batch);
}

// ─── Documents and their values ──────────────────────────────────

test('documents arrive with their index values', function () {
    $arcmate = new FakeArcMate(
        documents: [
            ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => 'PO-77'],
            ['arcId' => 11, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-002', 'S2' => null],
        ],
    );

    $stats = syncFake($arcmate, $this->archive, $this->sync);

    expect($stats['documents'])->toBe(2);
    expect(ArchiveDocument::count())->toBe(2);

    $first = ArchiveDocument::where('arcmate_id', 10)->first();
    expect($first->valueMap())->toBe(['invoiceno' => 'INV-001', 'pono' => 'PO-77']);

    // An empty ArcMate column is a value that is not there, not the string "".
    $second = ArchiveDocument::where('arcmate_id', 11)->first();
    expect($second->values()->where('value_text', null)->exists())->toBeTrue();
});

test('a second run copies nothing and leaves the watermark alone', function () {
    $arcmate = new FakeArcMate(documents: [
        ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => null],
    ]);

    syncFake($arcmate, $this->archive, $this->sync);
    $watermark = $this->archive->fresh()->last_doc_arc_id;

    $second = syncFake($arcmate, $this->archive->fresh(), $this->sync);

    expect($second['documents'])->toBe(0);
    expect($this->archive->fresh()->last_doc_arc_id)->toBe($watermark);
    expect(ArchiveDocument::count())->toBe(1);
});

test('the backfill resumes exactly where a stopped run left off', function () {
    $documents = [];
    for ($i = 1; $i <= 5; $i++) {
        $documents[] = ['arcId' => $i, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => "INV-{$i}", 'S2' => null];
    }

    $arcmate = new FakeArcMate(documents: $documents);

    // Batch of 2, which is what an interrupted slice looks like from the inside.
    syncFake($arcmate, $this->archive, $this->sync, batch: 2);

    expect(ArchiveDocument::count())->toBe(5);
    expect($this->archive->fresh()->last_doc_arc_id)->toBe(5);
});

// ─── Files ───────────────────────────────────────────────────────

test('files land on their documents with a resolved path', function () {
    $arcmate = new FakeArcMate(
        documents: [['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => null]],
        files: [[
            'arcId' => 500,
            'arcDocumentId' => 10,
            'arcFileName' => 'D2026\\0915\\1440\\20260915144709220434.pdf',
            'arcOrgName' => 'scan.pdf',
            'arcFileOrder' => 10,
            'arcPageCount' => 0,
            'arcFileSize' => 0,
            'arcStatus' => 0,
            'arcFileCRC' => null,
        ]],
    );

    $stats = syncFake($arcmate, $this->archive, $this->sync);

    expect($stats['files'])->toBe(1);

    $file = ArchiveFile::first();
    expect($file->disk)->toBe(ArchiveFile::DISK_ARCMATE);
    // A .pdf is a scan, so Images — reconstructed, because nothing in the row says so.
    expect($file->path)->toContain('SPS_Invoices/Documents/Images/D2026/0915/1440/');
    expect($file->original_name)->toBe('scan.pdf');
    // arcFileSize and arcPageCount are 0 in the live rows; a zero is not a size.
    expect($file->size)->toBeNull();
    expect($file->page_count)->toBeNull();
});

test('an attached email is looked for under EDocs', function () {
    $arcmate = new FakeArcMate(
        documents: [['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => null]],
        files: [[
            'arcId' => 500, 'arcDocumentId' => 10,
            'arcFileName' => 'D2026\\0915\\1440\\20260915144723220434.msg',
            'arcOrgName' => 'P0000000001.msg', 'arcFileOrder' => 10,
            'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null,
        ]],
    );

    syncFake($arcmate, $this->archive, $this->sync);

    expect(ArchiveFile::first()->path)->toContain('/Documents/EDocs/');
});

test('the file pass stops at a document it has not copied yet', function () {
    // The heart of it: ArcMate's document and file ids are independent
    // sequences, so a file can arrive before its document. Skipping it would
    // lose the file for good, so the pass stops and the watermark stays put.
    $arcmate = new FakeArcMate(
        documents: [['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => null]],
        files: [
            ['arcId' => 500, 'arcDocumentId' => 10, 'arcFileName' => 'D2026\\0915\\1440\\a.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
            // Belongs to a document ArcMate has but this run has not reached.
            ['arcId' => 501, 'arcDocumentId' => 99, 'arcFileName' => 'D2026\\0915\\1450\\b.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
        ],
    );

    $stats = syncFake($arcmate, $this->archive, $this->sync);

    expect($stats['files'])->toBe(1);
    expect($this->archive->fresh()->last_file_arc_id)->toBe(500);

    // The document turns up, and the orphan is picked up without anything else.
    $arcmate->documents[] = ['arcId' => 99, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-099', 'S2' => null];

    $second = syncFake($arcmate, $this->archive->fresh(), $this->sync);

    expect($second['files'])->toBe(1);
    expect(ArchiveFile::count())->toBe(2);
    expect($this->archive->fresh()->last_file_arc_id)->toBe(501);
});

test('the capture time comes from the earliest file name', function () {
    $arcmate = new FakeArcMate(
        documents: [['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 2, 'S1' => 'INV-001', 'S2' => null]],
        files: [
            ['arcId' => 500, 'arcDocumentId' => 10, 'arcFileName' => 'D2026\\0915\\1440\\20260915144709220434.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 20, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
            ['arcId' => 501, 'arcDocumentId' => 10, 'arcFileName' => 'D2026\\0915\\1440\\20260915144655220434.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
        ],
    );

    syncFake($arcmate, $this->archive, $this->sync);

    // 14:46:55, the earlier of the two — and wall clock, not shifted by a zone.
    expect(ArchiveDocument::first()->captured_at->format('Y-m-d H:i:s'))->toBe('2026-09-15 14:46:55');
});

// ─── Edits ───────────────────────────────────────────────────────

test('an edit in ArcMate arrives through the track table', function () {
    $arcmate = new FakeArcMate(documents: [
        ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => null],
    ]);

    syncFake($arcmate, $this->archive, $this->sync);

    // Somebody fixes the invoice number in ArcMate. The row changes in place —
    // its arcId does not move — so only the track row reveals it.
    $arcmate->documents[0]['S1'] = 'INV-001-CORRECTED';
    $arcmate->track[] = ['arcId' => 1, 'arcDocumentId' => 10, 'arcStatus' => 2, 'arcUser' => 'tareq', 'arcDate' => '20260915150000'];

    $stats = syncFake($arcmate, $this->archive->fresh(), $this->sync);

    expect($stats['updated'])->toBe(1);
    expect(ArchiveDocument::first()->valueMap()['invoiceno'])->toBe('INV-001-CORRECTED');
});

test('a value edited here is never overwritten by ArcMate', function () {
    $arcmate = new FakeArcMate(documents: [
        ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => null],
    ]);

    syncFake($arcmate, $this->archive, $this->sync);

    // A person corrects it on this side.
    $document = ArchiveDocument::first();
    $document->values()->where('archive_field_id', ArchiveField::where('key', 'invoiceno')->value('id'))
        ->update(['value_text' => 'INV-001-FIXED-HERE', 'source' => ArchiveDocumentValue::SOURCE_PERSON]);

    // ArcMate disagrees, and says so through a track row.
    $arcmate->documents[0]['S1'] = 'INV-999-FROM-ARCMATE';
    $arcmate->track[] = ['arcId' => 1, 'arcDocumentId' => 10, 'arcStatus' => 2, 'arcUser' => 'tareq', 'arcDate' => '20260915150000'];

    syncFake($arcmate, $this->archive->fresh(), $this->sync);

    $document = ArchiveDocument::first();
    expect($document->valueMap()['invoiceno'])->toBe('INV-001-FIXED-HERE');
    expect($document->needs_review)->toBeTrue();
    expect($document->review_note)->toContain('Nothing was overwritten');
});

test('ArcMate may refresh a value it owns', function () {
    $arcmate = new FakeArcMate(documents: [
        ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => 'PO-1'],
    ]);

    syncFake($arcmate, $this->archive, $this->sync);

    $arcmate->documents[0]['S2'] = 'PO-2';
    $arcmate->track[] = ['arcId' => 1, 'arcDocumentId' => 10, 'arcStatus' => 2, 'arcUser' => 'zak', 'arcDate' => '20260915150000'];

    syncFake($arcmate, $this->archive->fresh(), $this->sync);

    $document = ArchiveDocument::first();
    expect($document->valueMap()['pono'])->toBe('PO-2');
    expect($document->needs_review)->toBeFalse();
});

// ─── Deletions ───────────────────────────────────────────────────

test('a deletion in ArcMate hides the document without destroying it', function () {
    $arcmate = new FakeArcMate(documents: [
        ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => null],
        ['arcId' => 11, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-002', 'S2' => null],
    ]);

    syncFake($arcmate, $this->archive, $this->sync);

    $arcmate->deleted[] = ['arcId' => 1, 'arcObjectId' => 10, 'arcObjectType' => 1, 'arcUserName' => 'admin', 'arcDate' => '20260915160000'];

    $stats = syncFake($arcmate, $this->archive->fresh(), $this->sync);

    expect($stats['deleted'])->toBe(1);
    // The row is still there — a deletion in a twenty-year-old system is worth
    // keeping recoverable — but it is no longer active.
    expect(ArchiveDocument::count())->toBe(2);
    expect(ArchiveDocument::where('arcmate_id', 10)->first()->status)
        ->toBe(ArchiveDocument::STATUS_DELETED_IN_ARCMATE);
    expect(ArchiveDocument::active()->count())->toBe(1);
});

// ─── Typed columns and safety ────────────────────────────────────

test('date and number columns fill their typed values', function () {
    ArchiveField::create([
        'archive_id' => $this->archive->id, 'key' => 'servicedate', 'label' => 'ServiceReqDate',
        'type' => ArchiveField::TYPE_DATE, 'arcmate_column' => 'D1', 'sort_order' => 3,
    ]);
    ArchiveField::create([
        'archive_id' => $this->archive->id, 'key' => 'amount', 'label' => 'Invoice Amount',
        'type' => ArchiveField::TYPE_NUMBER, 'arcmate_column' => 'S5', 'sort_order' => 4,
    ]);

    $arcmate = new FakeArcMate(documents: [[
        'arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0,
        'S1' => 'INV-001', 'S2' => null, 'D1' => '20240301', 'S5' => '12,500.50',
    ]]);

    syncFake($arcmate, $this->archive->fresh(), $this->sync);

    $document = ArchiveDocument::with('values')->first();
    $byField = $document->values->keyBy('archive_field_id');

    $dateField = ArchiveField::where('key', 'servicedate')->first();
    $amountField = ArchiveField::where('key', 'amount')->first();

    expect($byField[$dateField->id]->value_date->format('Y-m-d'))->toBe('2024-03-01');
    // ArcMate keeps amounts as text, commas and all; the number is ours.
    expect((float) $byField[$amountField->id]->value_number)->toBe(12500.5);
    expect($byField[$amountField->id]->value_text)->toBe('12,500.50');
});

test('a field mapped to an unsafe column is ignored entirely', function () {
    ArchiveField::create([
        'archive_id' => $this->archive->id, 'key' => 'injected', 'label' => 'Injected',
        'type' => ArchiveField::TYPE_TEXT, 'arcmate_column' => 'S1; DROP TABLE tblDocuments', 'sort_order' => 9,
    ]);

    $arcmate = new FakeArcMate(documents: [
        ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => null],
    ]);

    syncFake($arcmate, $this->archive->fresh(), $this->sync);

    // The safe fields still sync; the unsafe one simply never reaches a query.
    $document = ArchiveDocument::first();
    expect($document->valueMap())->toHaveKey('invoiceno');
    expect($document->valueMap())->not->toHaveKey('injected');
});

test('counts are refreshed for the archive card', function () {
    $arcmate = new FakeArcMate(
        documents: [
            ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => null],
            ['arcId' => 11, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-002', 'S2' => null],
        ],
        files: [['arcId' => 500, 'arcDocumentId' => 10, 'arcFileName' => 'D2026\\0915\\1440\\a.pdf',
            'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null]],
    );

    syncFake($arcmate, $this->archive, $this->sync);

    $archive = $this->archive->fresh();
    expect($archive->document_count)->toBe(2);
    expect($archive->file_count)->toBe(1);
    expect($archive->backfill_done_at)->not->toBeNull();
});

test('a file whose document can never arrive does not block every file behind it', function () {
    // Found in production. ArcMate holds files with arcDocumentId 0 — 38 of them
    // in SPS Invoices — and the pass waited for a document that cannot exist. The
    // mirror froze at 19,000 of 511,248 files with the watermark stuck on the
    // first such row, and every later file queued behind it for ever.
    //
    // The two cases are told apart by the DOCUMENT watermark: ahead of it means
    // still coming, at or behind it means it is not coming.
    $arcmate = new FakeArcMate(
        documents: [
            ['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => null],
            ['arcId' => 11, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-002', 'S2' => null],
        ],
        files: [
            ['arcId' => 500, 'arcDocumentId' => 10, 'arcFileName' => 'D2026\0915\1440\a.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
            // No document at all, and there never will be one.
            ['arcId' => 501, 'arcDocumentId' => 0, 'arcFileName' => 'D2026\0915\1445\orphan.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
            ['arcId' => 502, 'arcDocumentId' => 11, 'arcFileName' => 'D2026\0915\1450\b.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
        ],
    );

    $stats = syncFake($arcmate, $this->archive, $this->sync);

    // The file behind the orphan is copied, not stranded.
    expect($stats['files'])->toBe(2);
    expect($stats['skipped'])->toBe(1);
    expect(ArchiveFile::count())->toBe(2);

    // And the watermark moves PAST the orphan, or the next run reads it again.
    expect($this->archive->fresh()->last_file_arc_id)->toBe(502);
});

test('a batch that is entirely orphans still moves the watermark', function () {
    // The infinite-loop guard: advancing only to the last WRITTEN file leaves a
    // batch with nothing writable in it re-reading the same rows for ever.
    $arcmate = new FakeArcMate(
        documents: [['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 0, 'S1' => 'INV-001', 'S2' => null]],
        files: [
            ['arcId' => 500, 'arcDocumentId' => 0, 'arcFileName' => 'D2026\0915\1440\x.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
            ['arcId' => 501, 'arcDocumentId' => 0, 'arcFileName' => 'D2026\0915\1445\y.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
        ],
    );

    $stats = syncFake($arcmate, $this->archive, $this->sync);

    expect($stats['files'])->toBe(0);
    expect($stats['skipped'])->toBe(2);
    expect($this->archive->fresh()->last_file_arc_id)->toBe(501);
});

test('the backfill is not called done while a file is still waiting for its document', function () {
    // caught_up used to mean "did not run out of time", so a run that stopped at
    // a file whose document had not arrived still stamped backfill_done_at. On
    // NOC2 that marked a mirror holding 3% of its files as complete.
    $arcmate = new FakeArcMate(
        documents: [['arcId' => 10, 'arcStatus' => 1, 'arcFileCount' => 1, 'S1' => 'INV-001', 'S2' => null]],
        files: [
            ['arcId' => 500, 'arcDocumentId' => 10, 'arcFileName' => 'D2026\0915\1440\a.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
            // Its document is ahead of the watermark: still coming.
            ['arcId' => 501, 'arcDocumentId' => 99, 'arcFileName' => 'D2026\0915\1450\b.pdf',
                'arcOrgName' => null, 'arcFileOrder' => 10, 'arcPageCount' => 0, 'arcFileSize' => 0, 'arcStatus' => 0, 'arcFileCRC' => null],
        ],
    );

    $stats = syncFake($arcmate, $this->archive, $this->sync);

    expect($stats['caught_up'])->toBeFalse();
    expect($this->archive->fresh()->backfill_done_at)->toBeNull();
});
