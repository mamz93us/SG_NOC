<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveAiSettings;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\Archive\ArchiveMember;
use App\Models\User;
use App\Services\Ai\PdfPages;
use App\Services\Archive\Ai\FieldExtractor;
use App\Services\Archive\Ai\PageReader;
use App\Services\Archive\InboxProcessor;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * AI must not read a scan for an archive whose AI is switched off.
 *
 * The AI settings page tells whoever reads it to leave the switches off for
 * anything holding HR files. That promise did not cover capture: a scan arriving
 * in an inbox was read the moment there was budget for it, because this path
 * checked the month's money and nothing else. An archive with every switch off
 * still had its scans read, and paid for.
 *
 * `ai_extract` is the capture switch, deliberately separate from `ai_reading`:
 * reading history is a batch somebody starts and watches, while this runs by
 * itself on every scan that arrives.
 *
 * Azure is never called. The reader and the extractor are subclassed to record
 * whether they were reached at all, which is the whole question here.
 */
uses(Tests\TestCase::class);

/**
 * A reader that only records that something tried to spend money through it.
 *
 * readUnfiledPage(), not textFor(): a scan in the inbox has no archive_files
 * row yet, so capture reads through the unfiled path. Faking the wrong method
 * makes the test pass for the wrong reason — it did, at first.
 */
class RecordingPageReader extends PageReader
{
    public int $calls = 0;

    public function readUnfiledPage(string $pdfPath, int $page, ?int $archiveId = null, ?int $userId = null): string
    {
        $this->calls++;

        return 'INVOICE 4471';
    }
}

class RecordingExtractor extends FieldExtractor
{
    public int $calls = 0;

    public function __construct() {}

    public function propose(string $text, array $fields, string $archiveName = ''): array
    {
        $this->calls++;

        return [];
    }
}

/** pdfinfo is not on every machine this suite runs on, and is not what is under test. */
class FixedPdfPages extends PdfPages
{
    public function __construct(private int $pages = 1) {}

    public function count(string $path): int
    {
        return $this->pages;
    }

    public function text(string $path, int $page): string
    {
        return ''; // no text layer, so every page falls through to the reader
    }
}

beforeEach(function () {
    ArchiveTestSchema::create();
    Storage::fake(ArchiveFile::DISK_AZURE);

    // A budget, because 0 means "AI off" everywhere and would stop these scans
    // for a reason that is not the one under test.
    ArchiveAiSettings::get()->forceFill([
        'monthly_budget_usd' => 50.00,
        'page_read_cost_usd' => 0.01,
    ])->save();

    $this->owner = User::create([
        'name' => 'Filing Clerk',
        'email' => 'clerk@samirgroup.com',
        'password' => 'x',
        // A superuser on purpose. ArchiveAccess asks hasPermission(), which
        // resolves through the role and permission tables, and this schema does
        // not build them — isSuperAdmin() falls back to the role slug when the
        // roles table is absent, so this is the only way to answer that question
        // here. Without it the owner sees NO archive and every "AI stays off"
        // assertion below passes for that reason rather than the switch, which is
        // what happened on the first run of this file.
        'role' => 'super_admin',
    ]);

    $this->archive = Archive::create([
        'slug' => 'sps-hr',
        'name' => 'SPS HR',
        'mode' => Archive::MODE_NATIVE,
        'readable' => true,
        'ai_extract' => false,
    ]);

    ArchiveMember::create([
        'archive_id' => $this->archive->id,
        'user_id' => $this->owner->id,
        'can_view' => true,
        'can_add' => true,
    ]);

    $this->reader = new RecordingPageReader;
    $this->extractor = new RecordingExtractor;
    $this->processor = new InboxProcessor($this->reader, $this->extractor, new FixedPdfPages(1));

    // A scan sitting in the inbox, its bytes really on the (faked) disk, so the
    // processor gets as far as the decision under test rather than failing to
    // open anything.
    $this->scan = function (?int $archiveId = null): ArchiveInboxItem {
        $path = 'inbox/'.bin2hex(random_bytes(6)).'.pdf';
        Storage::disk(ArchiveFile::DISK_AZURE)->put($path, '%PDF-1.4 fake');

        return ArchiveInboxItem::create([
            'user_id' => $this->owner->id,
            'archive_id' => $archiveId,
            'source' => ArchiveInboxItem::SOURCE_UPLOAD,
            'path' => $path,
            'original_name' => 'scan.pdf',
            'mime' => 'application/pdf',
            'size' => 13,
            'status' => ArchiveInboxItem::STATUS_WAITING,
            'ai_status' => ArchiveInboxItem::AI_QUEUED,
        ]);
    };
});

afterEach(function () {
    ArchiveTestSchema::drop();
});

test('a scan is not read when its archive has AI switched off', function () {
    $item = ($this->scan)();

    $result = $this->processor->item($item);

    // Nothing was spent, and nothing was proposed.
    expect($this->reader->calls)->toBe(0);
    expect($this->extractor->calls)->toBe(0);

    // Done, not failed: the scan is filed by hand perfectly well. Marking it
    // failed would put it in front of somebody as a problem to fix.
    expect($item->fresh()->ai_status)->toBe(ArchiveInboxItem::AI_DONE);
});

test('the page count is still recorded, because the filing form wants it', function () {
    // Refusing to read is not refusing to look at the file at all. The page count
    // is free and the filing form shows it either way.
    $item = ($this->scan)();

    $result = $this->processor->item($item);

    expect($result['pages'])->toBe(1);
    expect($item->fresh()->pages)->toBe(1);
});

test('switching it on lets the same scan be read', function () {
    // The other half: the gate has to be the switch and not something else that
    // happens to be false in this test.
    $this->archive->forceFill(['ai_extract' => true])->save();

    $item = ($this->scan)();

    $this->processor->item($item);

    expect($this->reader->calls)->toBeGreaterThan(0);
});

test('a scan bound to one archive is judged by that archive alone', function () {
    // A scan that arrived through a destination bound to an archive already knows
    // where it is going, so another archive having AI on must not speak for it.
    $open = Archive::create([
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'mode' => Archive::MODE_NATIVE,
        'readable' => true,
        'ai_extract' => true,
    ]);

    ArchiveMember::create([
        'archive_id' => $open->id,
        'user_id' => $this->owner->id,
        'can_view' => true,
        'can_add' => true,
    ]);

    // Bound to the archive whose AI is OFF.
    $item = ($this->scan)($this->archive->id);

    $this->processor->item($item);

    expect($this->reader->calls)->toBe(0);
    expect($item->fresh()->ai_status)->toBe(ArchiveInboxItem::AI_DONE);
});

test('an unbound scan is read when any archive it could go to has AI on', function () {
    // The alternative — every candidate must allow it — would refuse to read a
    // scan because one of several possible destinations has AI off, which is the
    // common case the moment a second archive exists.
    Archive::create([
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'mode' => Archive::MODE_NATIVE,
        'readable' => true,
        'ai_extract' => true,
    ])->members()->create([
        'user_id' => $this->owner->id,
        'can_view' => true,
        'can_add' => true,
    ]);

    $item = ($this->scan)();

    $this->processor->item($item);

    expect($this->reader->calls)->toBeGreaterThan(0);
});
