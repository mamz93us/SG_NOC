<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveAiUsage;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveFileText;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\User;
use App\Services\Ai\PdfPages;
use App\Services\Archive\Ai\PageReader;
use App\Services\Archive\InboxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * A scan is read once, not once for the form and again for the archive.
 *
 * Capture reads a scan's pages to pre-fill the filing form, and that reading is
 * charged for. The text used to be thrown away afterwards: the filed document
 * had no text at all, so the first question asked about it — or the first read
 * batch covering it — read and paid for exactly the same pages a second time.
 *
 * The last test here is the one that matters. The others check the rows land;
 * that one checks the money, by reading the filed file for real and asserting
 * nothing was spent.
 */
uses(Tests\TestCase::class);

/** A reader whose vision call would cost money, so any call at all is a failure. */
class CountingClient extends App\Services\Ai\AzureOpenAiClient
{
    public int $calls = 0;

    public function __construct() {}

    public function chat(array $messages, array $tools = [], array $opts = []): array
    {
        $this->calls++;

        return [
            'message' => ['content' => json_encode(['language' => 'en', 'text' => 'READ AGAIN'])],
            'finish_reason' => 'stop',
        ];
    }
}

/** poppler is not on every machine this suite runs on, and is not under test. */
class CarryPdfPages extends PdfPages
{
    public function __construct(private int $pages = 2) {}

    public function count(string $path): int
    {
        return $this->pages;
    }

    public function text(string $path, int $page): string
    {
        return ''; // no text layer, so a page with no stored text would cost
    }

    public function image(string $path, int $page): string
    {
        return 'jpeg-bytes'; // pdftoppm is not installed here
    }
}

beforeEach(function () {
    ArchiveTestSchema::create();
    Storage::fake(ArchiveFile::DISK_AZURE);

    ArchiveAiSettings::get()->forceFill([
        'monthly_budget_usd' => 50.00,
        'page_read_cost_usd' => 0.01,
    ])->save();

    $this->owner = User::create([
        'name' => 'Filing Clerk',
        'email' => 'clerk@samirgroup.com',
        'password' => 'x',
        'role' => 'viewer',
    ]);

    $this->archive = Archive::create([
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'mode' => Archive::MODE_NATIVE,
    ]);

    ArchiveField::create([
        'archive_id' => $this->archive->id,
        'key' => 'invoice_no',
        'label' => 'Invoice Number',
        'type' => ArchiveField::TYPE_TEXT,
    ]);

    $this->inbox = new InboxService;

    // A scan that has been read: two pages, one lifted free off the PDF's own
    // text layer and one that AI was paid to look at.
    $this->readScan = function (?array $pages = null): ArchiveInboxItem {
        $item = $this->inbox->receiveUpload(
            UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.4 '.bin2hex(random_bytes(6))),
            $this->owner,
        )['item'];

        $item->forceFill([
            'pages' => 2,
            'ai_status' => ArchiveInboxItem::AI_DONE,
            'ai_text' => $pages ?? [
                1 => ['text' => 'INVOICE 4471', 'source' => ArchiveFileText::SOURCE_PDF_TEXT],
                2 => ['text' => 'TOTAL 12,500.50', 'source' => ArchiveFileText::SOURCE_AI],
            ],
        ])->save();

        return $item->fresh();
    };
});

afterEach(function () {
    ArchiveTestSchema::drop();
});

test('filing puts the text it already paid for onto the document', function () {
    $item = ($this->readScan)();

    $document = $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-4471'], $this->owner)['document'];

    $file = $document->files()->first();
    $texts = $file->texts()->orderBy('page')->get();

    expect($texts)->toHaveCount(2);
    expect($texts[0]->page)->toBe(1);
    expect($texts[0]->text)->toBe('INVOICE 4471');
    expect($texts[1]->text)->toBe('TOTAL 12,500.50');
});

test('each page keeps the source it actually came from', function () {
    // A page lifted free off the text layer must not be recorded as one AI was
    // paid to read: the AI page counts what reading has cost by source, and the
    // review queue treats an AI reading differently from the file's own words.
    $item = ($this->readScan)();

    $document = $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-1'], $this->owner)['document'];

    $texts = $document->files()->first()->texts()->orderBy('page')->get();

    expect($texts[0]->source)->toBe(ArchiveFileText::SOURCE_PDF_TEXT);
    expect($texts[1]->source)->toBe(ArchiveFileText::SOURCE_AI);
});

test('the copy on the inbox item is cleared once it has been carried across', function () {
    // Otherwise the same text sits in two places for ever, and the inbox table
    // quietly becomes a second copy of the archive.
    $item = ($this->readScan)();

    $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-2'], $this->owner);

    expect($item->fresh()->ai_text)->toBeNull();
});

test('a fully read scan is marked done, a partly read one is not', function () {
    // Capture reads at most ten pages, so a longer scan arrives with some of its
    // text. Marking that `done` would tell every later batch there was nothing
    // left to read, and those pages would never be read at all.
    $whole = ($this->readScan)();
    $document = $this->inbox->file([$whole], $this->archive, ['invoice_no' => 'SPS-3'], $this->owner)['document'];

    $file = $document->files()->first();
    expect($file->text_status)->toBe(ArchiveFile::TEXT_DONE);
    expect($file->pages_read)->toBe(2);

    // The same scan with only its first page read.
    $partial = ($this->readScan)([
        1 => ['text' => 'INVOICE 9999', 'source' => ArchiveFileText::SOURCE_AI],
    ]);
    $second = $this->inbox->file([$partial], $this->archive, ['invoice_no' => 'SPS-4'], $this->owner)['document'];

    $file = $second->files()->first();
    expect($file->text_status)->toBe(ArchiveFile::TEXT_PENDING);
    expect($file->pages_read)->toBe(1);
});

test('a scan that was never read files perfectly well', function () {
    // AI is off for most archives and refused for the rest whenever a cap says
    // no. Filing must not depend on there being any text to carry.
    $item = $this->inbox->receiveUpload(
        UploadedFile::fake()->createWithContent('plain.pdf', 'no ai here'),
        $this->owner,
    )['item'];

    $result = $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-5'], $this->owner);

    expect($result['error'])->toBeNull();
    expect($result['document']->files()->first()->texts()->count())->toBe(0);
});

test('the filed document is never read a second time', function () {
    // The whole point. Reading the filed file for real must spend nothing,
    // because every page already has text — and the client here fails the test
    // if it is called at all.
    $item = ($this->readScan)();
    $document = $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-6'], $this->owner)['document'];

    $client = new CountingClient;
    $reader = new PageReader($client, new CarryPdfPages(2));

    $spentBefore = ArchiveAiUsage::spentThisMonth();

    $read = $reader->read($document->files()->first()->fresh(), 30, $this->owner->id);

    expect($client->calls)->toBe(0);
    expect($read['pages_read'])->toBe(0);
    expect($read['cost'])->toBe(0.0);
    expect(ArchiveAiUsage::spentThisMonth())->toBe($spentBefore);
});

test('and the control: the same file with no carried text does cost', function () {
    // Without this, the test above passes just as well when the file cannot be
    // opened at all — nothing read, nothing spent, for entirely the wrong
    // reason. That is the shape of mistake this suite has made before.
    //
    // Same scan, same reader, same everything, except the text was never
    // carried across. It must reach the client and be charged.
    $item = ($this->readScan)();
    $document = $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-7'], $this->owner)['document'];

    $file = $document->files()->first();
    $file->texts()->delete();
    $file->forceFill(['text_status' => ArchiveFile::TEXT_NONE, 'pages_read' => 0])->save();

    $client = new CountingClient;
    $read = (new PageReader($client, new CarryPdfPages(2)))->read($file->fresh(), 30, $this->owner->id);

    expect($read['stopped'])->toBeNull();
    expect($client->calls)->toBe(2);
    expect($read['pages_read'])->toBe(2);
});
