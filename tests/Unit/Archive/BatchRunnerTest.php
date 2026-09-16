<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiBatch;
use App\Models\Archive\ArchiveAiProposal;
use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveFileText;
use App\Services\Archive\Ai\BatchRunner;
use App\Services\Archive\Ai\FieldExtractor;
use App\Services\Archive\Ai\PageReader;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * Working a batch across 500,000 documents, and the four ways that quietly goes
 * wrong — every one of which was in the first draft of BatchRunner.
 *
 * The theme: a batch that reports success while doing the wrong thing is worse
 * than one that fails, because nobody looks again. So each case here pins a
 * behaviour whose failure mode is silence — pages never read, a document paid
 * for on every slice, an approval thrown away.
 *
 * Azure is never called. PageReader and FieldExtractor are subclassed with the
 * one method each that BatchRunner uses, because what is under test is the
 * decisions BatchRunner makes about them, not the calls themselves.
 */
uses(Tests\TestCase::class);

/** A PageReader that reads a fixed number of pages per call, without Azure. */
class FakePageReader extends PageReader
{
    public int $calls = 0;

    /** @var array<int,int> file id => pages already read */
    public array $readSoFar = [];

    public function __construct(
        private int $pagesPerCall = 10,
        private int $totalPages = 10,
        private float $costPerPage = 0.01,
    ) {
        parent::__construct();
    }

    public function textFor(ArchiveFile $file, int $maxPages = 30, ?int $userId = null): array
    {
        $this->calls++;

        $already = $this->readSoFar[$file->getKey()] ?? 0;
        $read = max(0, min($this->pagesPerCall, $maxPages, $this->totalPages - $already));
        $this->readSoFar[$file->getKey()] = $already + $read;

        for ($page = $already + 1; $page <= $already + $read; $page++) {
            ArchiveFileText::create([
                'archive_file_id' => $file->getKey(),
                'page' => $page,
                'text' => 'INVOICE 4471 page '.$page,
                'source' => ArchiveFileText::SOURCE_AI,
                'read_at' => now(),
            ]);
        }

        // Exactly what PageReader::stamp() does, which is the behaviour the
        // settle() bug turned on its head: pending means "pages remain".
        $done = $this->readSoFar[$file->getKey()];

        $file->forceFill([
            'pages_read' => $done,
            'page_count' => $this->totalPages,
            'text_status' => $done >= $this->totalPages && $this->totalPages > 0
                ? ArchiveFile::TEXT_DONE
                : ArchiveFile::TEXT_PENDING,
            'text_read_at' => now(),
        ])->save();

        if ($read > 0) {
            ArchiveAiUsage::record(
                ArchiveAiUsage::FEATURE_READ,
                $read * $this->costPerPage,
                pages: $read,
                archiveId: $file->archive_id,
            );
        }

        return [
            'text' => $done > 0 ? '[page 1]'.PHP_EOL.'INVOICE 4471' : '',
            'pages' => range(1, max(0, $done)),
            'read' => [
                'pages_read' => $read,
                'cost' => $read * $this->costPerPage,
                'from_text_layer' => 0,
                'stopped' => $done < $this->totalPages ? 'Reached the page limit for one go.' : null,
            ],
        ];
    }
}

/** A PageReader for a file nothing can be read from — a .msg, a broken scan. */
class UnreadablePageReader extends PageReader
{
    public function textFor(ArchiveFile $file, int $maxPages = 30, ?int $userId = null): array
    {
        return [
            'text' => '',
            'pages' => [],
            'read' => ['pages_read' => 0, 'cost' => 0.0, 'from_text_layer' => 0, 'stopped' => 'This kind of file has no pages to read.'],
        ];
    }
}

/** A FieldExtractor that proposes whatever the test tells it to. */
class FakeFieldExtractor extends FieldExtractor
{
    public int $calls = 0;

    public function __construct(private array $proposals = []) {}

    public function propose(string $text, array $fields, string $archiveName = ''): array
    {
        $this->calls++;

        return $this->proposals;
    }
}

beforeEach(function () {
    ArchiveTestSchema::create();

    ArchiveAiSettings::get()->forceFill([
        'monthly_budget_usd' => 50.00,
        'page_read_cost_usd' => 0.01,
    ])->save();

    $this->archive = Archive::create([
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'ai_reading' => true,
    ]);

    $this->field = ArchiveField::create([
        'archive_id' => $this->archive->id,
        'key' => 'invoice_no',
        'label' => 'Invoice Number',
        'type' => ArchiveField::TYPE_TEXT,
    ]);

    $this->makeDocument = function (int $files = 1): ArchiveDocument {
        $document = ArchiveDocument::create([
            'archive_id' => $this->archive->id,
            'status' => ArchiveDocument::STATUS_ACTIVE,
            'captured_at' => '2026-09-15 14:47:09',
        ]);

        for ($n = 0; $n < $files; $n++) {
            ArchiveFile::create([
                'archive_id' => $this->archive->id,
                'archive_document_id' => $document->id,
                'position' => $n,
                'original_name' => 'scan'.$n.'.pdf',
                'path' => '/mnt/arcmate/SPS_Invoices/scan'.$n.'.pdf',
            ]);
        }

        return $document;
    };

    $this->batchFor = function (string $type): ArchiveAiBatch {
        return ArchiveAiBatch::create([
            'archive_id' => $this->archive->id,
            'type' => $type,
            'field_ids' => $type === ArchiveAiBatch::TYPE_FILL ? [$this->field->id] : [],
        ]);
    };
});

afterEach(function () {
    ArchiveTestSchema::drop();
});

// ─── Reading history ─────────────────────────────────────────────

test('a document longer than one slice keeps its remaining pages', function () {
    // The regression: PageReader leaves a part-read file `pending`, and the
    // runner used to flip every pending file to `failed` once it had touched the
    // document. A 30-page contract had its first 10 pages read and the other 20
    // never looked at — and the batch reported success.
    $document = ($this->makeDocument)();
    $reader = new FakePageReader(pagesPerCall: 10, totalPages: 30);
    $runner = new BatchRunner($reader, new FakeFieldExtractor);
    $batch = ($this->batchFor)(ArchiveAiBatch::TYPE_READ);

    $runner->run($batch, fn () => $reader->calls >= 1);

    $file = $document->files()->first();
    expect($file->pages_read)->toBe(10);
    expect($file->text_status)->toBe(ArchiveFile::TEXT_PENDING);

    // Still the batch's work, so the next slice carries on with it.
    $runner->run($batch->fresh(), fn () => $reader->calls >= 2);
    expect($document->files()->first()->pages_read)->toBe(20);

    $runner->run($batch->fresh(), fn () => $reader->calls >= 3);
    $file = $document->files()->first();
    expect($file->pages_read)->toBe(30);
    expect($file->text_status)->toBe(ArchiveFile::TEXT_DONE);
});

test('a file nothing can be read from is not tried forever', function () {
    // The other half of the same rule: a file that yields nothing while there was
    // budget to read it cannot be read at all, and leaving it pending would have
    // the batch choose this document again on every slice.
    $document = ($this->makeDocument)();
    $runner = new BatchRunner(new UnreadablePageReader, new FakeFieldExtractor);

    $runner->run(($this->batchFor)(ArchiveAiBatch::TYPE_READ));

    expect($document->files()->first()->text_status)->toBe(ArchiveFile::TEXT_FAILED);
});

test('every file of a document is read, not only the ones before the first stop', function () {
    // PageReader sets `stopped` for a page limit and for a file with no pages in
    // it, as well as for the budget. Reading that as "stop here" skipped the PDF
    // sitting behind an attached .msg.
    $document = ($this->makeDocument)(files: 3);
    $reader = new FakePageReader(pagesPerCall: 10, totalPages: 30);
    $runner = new BatchRunner($reader, new FakeFieldExtractor);

    $runner->run(($this->batchFor)(ArchiveAiBatch::TYPE_READ), fn () => $reader->calls >= 3);

    foreach ($document->files as $file) {
        expect($file->pages_read)->toBe(10);
    }
});

test('a finished batch says so and stops picking work up', function () {
    ($this->makeDocument)();
    $reader = new FakePageReader(pagesPerCall: 10, totalPages: 10);
    $batch = ($this->batchFor)(ArchiveAiBatch::TYPE_READ);

    $stats = (new BatchRunner($reader, new FakeFieldExtractor))->run($batch);

    expect($stats['finished'])->toBeTrue();
    expect($batch->fresh()->status)->toBe(ArchiveAiBatch::STATUS_DONE);
    expect($batch->fresh()->pages_done)->toBe(10);
});

// ─── The budget ──────────────────────────────────────────────────

test('a batch stops at the month\'s budget instead of on a bill', function () {
    ($this->makeDocument)();
    ArchiveAiSettings::get()->forceFill(['monthly_budget_usd' => 0.05])->save();

    $batch = ($this->batchFor)(ArchiveAiBatch::TYPE_READ);
    $stats = (new BatchRunner(new FakePageReader(10, 200), new FakeFieldExtractor))->run($batch);

    expect($batch->fresh()->status)->toBe(ArchiveAiBatch::STATUS_OVER_BUDGET);
    expect($stats['reason'])->toContain('budget');
});

test('running out of budget does not write off the documents it had reached', function () {
    // A budget stop must never look like an unreadable file: those documents are
    // perfectly good and have to be picked up again next month.
    $document = ($this->makeDocument)();
    ArchiveAiSettings::get()->forceFill(['monthly_budget_usd' => 0.05])->save();

    (new BatchRunner(new FakePageReader(10, 200), new FakeFieldExtractor))
        ->run(($this->batchFor)(ArchiveAiBatch::TYPE_READ));

    expect($document->files()->first()->text_status)->not->toBe(ArchiveFile::TEXT_FAILED);
});

test('an archive with AI switched off is refused rather than read', function () {
    ($this->makeDocument)();
    $this->archive->forceFill(['ai_reading' => false])->save();

    $batch = ($this->batchFor)(ArchiveAiBatch::TYPE_READ);
    (new BatchRunner(new FakePageReader, new FakeFieldExtractor))->run($batch);

    expect($batch->fresh()->status)->toBe(ArchiveAiBatch::STATUS_FAILED);
});

// ─── Filling gaps ────────────────────────────────────────────────

test('a proposed value is recorded as a proposal, with the page it was read off', function () {
    $document = ($this->makeDocument)();
    $extractor = new FakeFieldExtractor([
        'invoice_no' => ['value' => 'SPS-4471', 'confidence' => 96, 'page' => 2],
    ]);

    (new BatchRunner(new FakePageReader, $extractor))->run(($this->batchFor)(ArchiveAiBatch::TYPE_FILL));

    $proposal = ArchiveAiProposal::where('archive_document_id', $document->id)->first();
    expect($proposal->value)->toBe('SPS-4471');
    expect($proposal->confidence)->toBe(96);
    expect($proposal->evidence_page)->toBe(2);
    expect($proposal->status)->toBe(ArchiveAiProposal::STATUS_PENDING);

    // Nothing reached the document itself. That is the whole point of the table.
    expect($document->values()->count())->toBe(0);
});

test('a document the fields are not on is not paid for twice', function () {
    // The expensive regression: with no proposal row written, the same document
    // matched the query again on the next slice and bought another extraction
    // call — for as long as the batch lived.
    ($this->makeDocument)();
    $extractor = new FakeFieldExtractor([]); // AI found none of the fields
    $runner = new BatchRunner(new FakePageReader, $extractor);
    $batch = ($this->batchFor)(ArchiveAiBatch::TYPE_FILL);

    $runner->run($batch);
    expect($extractor->calls)->toBe(1);

    // Looked for, and honestly recorded as absent — never offered for review.
    $proposal = ArchiveAiProposal::first();
    expect($proposal->status)->toBe(ArchiveAiProposal::STATUS_NOT_FOUND);
    expect($proposal->value)->toBeNull();
    expect(ArchiveAiProposal::pending()->count())->toBe(0);

    $runner->run($batch->fresh());
    expect($extractor->calls)->toBe(1);
});

test('a second batch never overwrites a decision somebody already made', function () {
    $document = ($this->makeDocument)();

    $approved = ArchiveAiProposal::create([
        'archive_document_id' => $document->id,
        'archive_field_id' => $this->field->id,
        'value' => 'SPS-4471',
        'confidence' => 96,
        'status' => ArchiveAiProposal::STATUS_APPROVED,
        'reviewed_by' => 1,
        'reviewed_at' => now(),
    ]);

    // A field can be covered by two batches. Resetting this to pending would
    // throw away the reviewer's work and offer it to them a second time.
    (new BatchRunner(new FakePageReader, new FakeFieldExtractor([
        'invoice_no' => ['value' => 'WRONG-9999', 'confidence' => 99, 'page' => 1],
    ])))->run(($this->batchFor)(ArchiveAiBatch::TYPE_FILL));

    $approved->refresh();
    expect($approved->status)->toBe(ArchiveAiProposal::STATUS_APPROVED);
    expect($approved->value)->toBe('SPS-4471');
});

test('a document whose field is already filled in is left alone', function () {
    $document = ($this->makeDocument)();
    $document->values()->create([
        'archive_field_id' => $this->field->id,
        'value_text' => 'SPS-1234',
        'source' => ArchiveDocumentValue::SOURCE_ARCMATE,
    ]);

    $extractor = new FakeFieldExtractor(['invoice_no' => ['value' => 'x', 'confidence' => 90, 'page' => 1]]);
    (new BatchRunner(new FakePageReader, $extractor))->run(($this->batchFor)(ArchiveAiBatch::TYPE_FILL));

    // Paying to be told something already known is money for nothing.
    expect($extractor->calls)->toBe(0);
    expect(ArchiveAiProposal::count())->toBe(0);
});

test('the extraction call is charged where the budget can see it', function () {
    // Kept only on the batch row, the fill calls were invisible to
    // withinBudget() — so the cap counted reading and never counted filling.
    ($this->makeDocument)();
    $extractor = new FakeFieldExtractor(['invoice_no' => ['value' => 'SPS-4471', 'confidence' => 90, 'page' => 1]]);

    (new BatchRunner(new FakePageReader(10, 10), $extractor))->run(($this->batchFor)(ArchiveAiBatch::TYPE_FILL));

    expect(ArchiveAiUsage::where('feature', ArchiveAiUsage::FEATURE_FILL)->count())->toBe(1);
    expect(ArchiveAiUsage::spentThisMonth())->toBe(0.11); // 10 pages read + one fill call
});

test('a fill batch that cannot read a document proposes nothing from nothing', function () {
    ($this->makeDocument)();
    $extractor = new FakeFieldExtractor(['invoice_no' => ['value' => 'guessed', 'confidence' => 99, 'page' => 1]]);

    (new BatchRunner(new UnreadablePageReader, $extractor))->run(($this->batchFor)(ArchiveAiBatch::TYPE_FILL));

    // No text means no evidence, and a value with no evidence is a guess.
    expect($extractor->calls)->toBe(0);
    expect(ArchiveAiProposal::first()->status)->toBe(ArchiveAiProposal::STATUS_NOT_FOUND);
});

// ─── Estimating before committing ────────────────────────────────

test('an estimate counts the documents and says when pages are guessed', function () {
    ($this->makeDocument)();
    ($this->makeDocument)();

    $estimate = (new BatchRunner(new FakePageReader, new FakeFieldExtractor))
        ->estimate($this->archive, ArchiveAiBatch::TYPE_READ);

    expect($estimate['documents'])->toBe(2);
    // ArcMate recorded 0 for every page count, so the page figure is an average
    // of what has been read — and the page has to say so rather than imply a
    // count it does not have.
    expect($estimate['known_pages'])->toBeFalse();
    expect($estimate['cost'])->toBeGreaterThan(0);
});

// ─── "Only the newest hundred" ────────────────────────────────────

test('a batch stops at its limit, counted across runs', function () {
    // The limit exists because "try it on the last hundred invoices" and "read
    // all 513,000" were the same button. It has to hold ACROSS runs: a batch is
    // worked a slice a minute, and a limit checked per slice would mean a hundred
    // every minute until the archive ran out.
    foreach (range(1, 5) as $ignored) {
        ($this->makeDocument)();
    }

    $reader = new FakePageReader(pagesPerCall: 10, totalPages: 1);
    $runner = new BatchRunner($reader, new FakeFieldExtractor);

    $batch = ArchiveAiBatch::create([
        'archive_id' => $this->archive->id,
        'type' => ArchiveAiBatch::TYPE_READ,
        'max_documents' => 2,
    ]);

    // One document per run, the way the scheduler ends a slice on time.
    $oneDocument = function () use ($runner, $batch): array {
        $turns = 0;

        return $runner->run($batch->fresh(), function () use (&$turns): bool {
            return $turns++ >= 1;
        });
    };

    $oneDocument();
    expect($batch->fresh()->documents_done)->toBe(1);
    expect($batch->fresh()->isFinished())->toBeFalse();

    $oneDocument();
    expect($batch->fresh()->documents_done)->toBe(2);

    // The third run has nothing left to do, and says so rather than carrying on.
    $stats = $oneDocument();

    expect($stats['finished'])->toBeTrue();
    expect($stats['reason'])->toContain('limit of 2');
    expect($batch->fresh()->status)->toBe(ArchiveAiBatch::STATUS_DONE);

    // Two documents read, three left alone — not "two ordered, five read".
    expect(ArchiveFileText::count())->toBe(2);
});

test('a long document counts once towards the limit, not once per slice', function () {
    // A read batch takes ten pages of a document and then picks the SAME
    // document again, so counting turns made a 30-page document three of them:
    // "the newest hundred" would have stopped at thirty-odd, with no hint that
    // it had. The batch must read exactly two documents to the end.
    $first = ($this->makeDocument)();
    $second = ($this->makeDocument)();
    $third = ($this->makeDocument)();

    $reader = new FakePageReader(pagesPerCall: 10, totalPages: 30);
    $runner = new BatchRunner($reader, new FakeFieldExtractor);

    $batch = ArchiveAiBatch::create([
        'archive_id' => $this->archive->id,
        'type' => ArchiveAiBatch::TYPE_READ,
        'max_documents' => 2,
    ]);

    $stats = $runner->run($batch);

    expect($stats['finished'])->toBeTrue();
    expect($batch->fresh()->documents_done)->toBe(2);

    // Newest first, and these share a captured_at, so it is the highest ids that
    // were read — both of them to the end, and the oldest not at all.
    expect($third->files()->first()->pages_read)->toBe(30);
    expect($second->files()->first()->pages_read)->toBe(30);
    expect((int) $first->files()->first()->pages_read)->toBe(0);

    // 60 pages, and the progress bar reads 100% rather than 300%.
    expect($batch->fresh()->pages_done)->toBe(60);
});

test('no limit means the date range alone decides, as before', function () {
    // The control is optional, and leaving it empty must not quietly become a
    // limit of nothing.
    foreach (range(1, 3) as $ignored) {
        ($this->makeDocument)();
    }

    $runner = new BatchRunner(new FakePageReader(10, 1), new FakeFieldExtractor);
    $batch = ($this->batchFor)(ArchiveAiBatch::TYPE_READ);

    expect($batch->max_documents)->toBeNull();

    $stats = $runner->run($batch);

    expect($stats['finished'])->toBeTrue();
    expect($batch->fresh()->documents_done)->toBe(3);
});

test('the estimate shows what the limit will actually do', function () {
    // The cost shown before starting is the whole reason to set a limit, so it
    // has to be the limited figure. Showing 513,000 documents next to a control
    // that will read a hundred is worse than showing nothing.
    foreach (range(1, 4) as $ignored) {
        ($this->makeDocument)();
    }

    $runner = new BatchRunner(new FakePageReader, new FakeFieldExtractor);

    $whole = $runner->estimate($this->archive, ArchiveAiBatch::TYPE_READ);
    $limited = $runner->estimate($this->archive, ArchiveAiBatch::TYPE_READ, [], [], 2);

    expect($whole['documents'])->toBe(4);
    expect($limited['documents'])->toBe(2);
    expect($limited['cost'])->toBeLessThan($whole['cost']);

    // A limit above what is there is not a promise of more documents.
    expect($runner->estimate($this->archive, ArchiveAiBatch::TYPE_READ, [], [], 500)['documents'])->toBe(4);
});
