<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveDocumentValue;
use App\Models\Archive\ArchiveField;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\User;
use App\Services\Archive\InboxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * Capture: a scan arriving, and a scan becoming a document.
 *
 * The properties worth pinning are the ones whose failure is silent. A scan that
 * arrives and is never visible looks like an upload that worked. A document whose
 * files were never moved out of the inbox opens as "file missing" weeks later. A
 * duplicate that throws a database error blames the person who uploaded it for
 * something an MFP did.
 *
 * Storage::fake gives a real disk, so the move from inbox/ to documents/ is
 * actually performed and checked rather than mocked away.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    ArchiveTestSchema::create();
    Storage::fake(ArchiveFile::DISK_AZURE);

    $this->inbox = new InboxService;

    $this->owner = User::create([
        'name' => 'Filing Clerk',
        'email' => 'clerk@samirgroup.com',
        'password' => 'x',
        'role' => 'viewer',
    ]);

    // Native: the only mode that accepts new documents. A mirrored archive is
    // still ArcMate's to write to.
    $this->archive = Archive::create([
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'mode' => Archive::MODE_NATIVE,
    ]);

    $this->invoiceNo = ArchiveField::create([
        'archive_id' => $this->archive->id,
        'key' => 'invoice_no',
        'label' => 'Invoice Number',
        'type' => ArchiveField::TYPE_TEXT,
        'is_unique' => true,
    ]);

    $this->total = ArchiveField::create([
        'archive_id' => $this->archive->id,
        'key' => 'total',
        'label' => 'Total',
        'type' => ArchiveField::TYPE_NUMBER,
    ]);

    $this->upload = function (string $name = 'scan.pdf', string $contents = 'scanned bytes'): array {
        return $this->inbox->receiveUpload(
            UploadedFile::fake()->createWithContent($name, $contents),
            $this->owner,
        );
    };
});

afterEach(function () {
    ArchiveTestSchema::drop();
});

// ─── Arriving ────────────────────────────────────────────────────

test('an uploaded scan lands in the inbox and its bytes are in Azure', function () {
    $result = ($this->upload)();

    expect($result['error'])->toBeNull();
    expect($result['duplicate'])->toBeFalse();

    $item = $result['item'];
    expect($item->status)->toBe(ArchiveInboxItem::STATUS_WAITING);
    expect($item->ai_status)->toBe(ArchiveInboxItem::AI_QUEUED);
    expect($item->original_name)->toBe('scan.pdf');
    // Never the client's filename: two uploads of "scan.pdf" must not collide,
    // and a supplied name is attacker-controlled.
    expect($item->path)->toStartWith('inbox/');
    expect($item->path)->not->toContain('scan.pdf');

    Storage::disk(ArchiveFile::DISK_AZURE)->assertExists($item->path);
});

test('the same scan twice is recognised, not refused', function () {
    // An MFP retrying, or a folder swept twice, is ordinary. It must report
    // "already here" rather than raise a database error at whoever uploaded it.
    $first = ($this->upload)('invoice.pdf', 'identical bytes');
    $second = ($this->upload)('invoice-again.pdf', 'identical bytes');

    expect($second['duplicate'])->toBeTrue();
    expect($second['error'])->toBeNull();
    expect($second['item']->id)->toBe($first['item']->id);
    expect(ArchiveInboxItem::count())->toBe(1);
});

test('a discarded scan can be sent again', function () {
    $first = ($this->upload)('again.pdf', 'same bytes');
    $this->inbox->discard($first['item']);

    // Discarding then re-sending is how somebody retries, so a discarded item
    // must not block the new one.
    $second = ($this->upload)('again.pdf', 'same bytes');

    expect($second['duplicate'])->toBeFalse();
    expect($second['item']->id)->not->toBe($first['item']->id);
});

test('something that is not a scan is refused on arrival', function () {
    $result = $this->inbox->receiveUpload(
        UploadedFile::fake()->createWithContent('rates.xlsx', 'not a scan'),
        $this->owner,
    );

    expect($result['item'])->toBeNull();
    expect($result['error'])->toContain('not a scan');
});

test('discarding removes the bytes but keeps the record', function () {
    $item = ($this->upload)()['item'];
    $path = $item->path;

    $this->inbox->discard($item);

    expect($item->fresh()->status)->toBe(ArchiveInboxItem::STATUS_DISCARDED);
    Storage::disk(ArchiveFile::DISK_AZURE)->assertMissing($path);
});

// ─── Becoming a document ─────────────────────────────────────────

test('filing creates the document, its values and its files', function () {
    $item = ($this->upload)('invoice.pdf', 'invoice bytes')['item'];

    $result = $this->inbox->file(
        [$item],
        $this->archive,
        ['invoice_no' => 'SPS-4471', 'total' => '12500.50'],
        $this->owner,
    );

    expect($result['error'])->toBeNull();

    $document = $result['document'];
    expect($document->created_by_name)->toBe('Filing Clerk');
    expect($document->file_count)->toBe(1);

    $value = $document->values()->where('archive_field_id', $this->invoiceNo->id)->first();
    expect($value->value_text)->toBe('SPS-4471');
    // Typed by a person, so the ArcMate sync will never overwrite it.
    expect($value->source)->toBe(ArchiveDocumentValue::SOURCE_PERSON);

    // The typed column is filled alongside the text, so a range search is an
    // indexed comparison rather than string parsing.
    $number = $document->values()->where('archive_field_id', $this->total->id)->first();
    expect((float) $number->value_number)->toBe(12500.50);

    expect($item->fresh()->status)->toBe(ArchiveInboxItem::STATUS_FILED);
    expect($item->fresh()->archive_document_id)->toBe($document->id);
});

test('a filed scan moves out of the inbox into the layout the transfer worker uses', function () {
    // The silent failure this guards: a document committed whose files were never
    // moved opens as "file missing" weeks later, and two implementations of the
    // blob path is how that happens.
    $item = ($this->upload)('invoice.pdf', 'invoice bytes')['item'];
    $inboxPath = $item->path;

    $document = $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-1'], $this->owner)['document'];

    $file = $document->files()->first();
    expect($file->disk)->toBe(ArchiveFile::DISK_AZURE);
    expect($file->path)->toStartWith('documents/sps-invoices/');
    expect($file->path)->not->toStartWith('inbox/');

    $disk = Storage::disk(ArchiveFile::DISK_AZURE);
    $disk->assertExists($file->path);
    $disk->assertMissing($inboxPath);
    expect($disk->get($file->path))->toBe('invoice bytes');
});

test('several scans can be filed as one document', function () {
    // A scanner producing a file per sheet, or an invoice with its delivery note
    // behind it, is ONE document — filing them separately is what people spend
    // their time undoing.
    $first = ($this->upload)('page1.pdf', 'sheet one')['item'];
    $second = ($this->upload)('page2.pdf', 'sheet two')['item'];

    $document = $this->inbox->file([$first, $second], $this->archive, ['invoice_no' => 'SPS-2'], $this->owner)['document'];

    expect($document->file_count)->toBe(2);
    expect($document->files()->count())->toBe(2);
    // Ordered, so the sheets stay in the order they were scanned.
    expect($document->files()->pluck('position')->all())->toBe([0, 1]);
});

test('a required field with nothing in it stops the filing', function () {
    $this->invoiceNo->forceFill(['required' => true])->save();

    $item = ($this->upload)()['item'];

    $result = $this->inbox->file([$item], $this->archive->fresh(), ['total' => '10'], $this->owner);

    expect($result['document'])->toBeNull();
    expect($result['error'])->toContain('Invoice Number');
    // Nothing half-created, and the scan is still there to file properly.
    expect(ArchiveDocument::count())->toBe(0);
    expect($item->fresh()->status)->toBe(ArchiveInboxItem::STATUS_WAITING);
});

test('a mirrored archive refuses new documents', function () {
    // ArcMate still owns a mirrored archive. Filing into it would create a
    // document ArcMate never hears about, which the next sync cannot reconcile.
    $this->archive->forceFill(['mode' => Archive::MODE_MIRROR])->save();

    $item = ($this->upload)()['item'];
    $result = $this->inbox->file([$item], $this->archive->fresh(), ['invoice_no' => 'X'], $this->owner);

    expect($result['document'])->toBeNull();
    expect($result['error'])->toContain('does not accept new documents');
    expect($item->fresh()->status)->toBe(ArchiveInboxItem::STATUS_WAITING);
});

test('an item already filed is not filed twice', function () {
    $item = ($this->upload)()['item'];

    $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-3'], $this->owner);
    $again = $this->inbox->file([$item->fresh()], $this->archive, ['invoice_no' => 'SPS-3'], $this->owner);

    expect($again['document'])->toBeNull();
    expect($again['error'])->toContain('already been filed');
    expect(ArchiveDocument::count())->toBe(1);
});

test('filing warns about a duplicate value rather than blocking it', function () {
    // ArcMate's own data has repeated invoice numbers in it (credit notes,
    // re-issues), so this has to inform rather than refuse.
    $first = ($this->upload)('a.pdf', 'first')['item'];
    $this->inbox->file([$first], $this->archive, ['invoice_no' => 'SPS-9'], $this->owner);

    $duplicates = $this->inbox->duplicatesOf($this->archive, $this->invoiceNo, 'SPS-9');
    expect($duplicates)->toHaveCount(1);

    $second = ($this->upload)('b.pdf', 'second')['item'];
    $result = $this->inbox->file([$second], $this->archive, ['invoice_no' => 'SPS-9'], $this->owner);

    expect($result['error'])->toBeNull();
    expect($result['document'])->not->toBeNull();
});

test('filing keeps the archive card figures right', function () {
    $item = ($this->upload)()['item'];

    $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-5'], $this->owner);

    $archive = $this->archive->fresh();
    expect($archive->document_count)->toBe(1);
    expect($archive->file_count)->toBe(1);
    // byte_total used to be skipped on this path, so the card and a recount
    // disagreed about the same archive with nothing obviously wrong.
    expect($archive->byte_total)->toBeGreaterThan(0);
    expect($archive->counts_updated_at)->not->toBeNull();
});

test('the shared recount agrees with what filing recorded', function () {
    $item = ($this->upload)()['item'];
    $this->inbox->file([$item], $this->archive, ['invoice_no' => 'SPS-6'], $this->owner);

    $afterFiling = $this->archive->fresh()->only(['document_count', 'file_count', 'byte_total']);

    // The recount task, the sync and filing all call this one method. Three
    // implementations is how a dashboard starts disagreeing with itself.
    $archive = $this->archive->fresh();
    $archive->refreshCounts();

    expect($archive->fresh()->only(['document_count', 'file_count', 'byte_total']))->toBe($afterFiling);
});
