<?php

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveDocument;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveSource;
use App\Models\Archive\ArchiveTransferRun;
use App\Services\Archive\ArchiveTransferService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Archive\ArchiveTestSchema;

/**
 * Moving 372 GB into Azure, and the promise that makes it safe to interrupt:
 * a file is only ever recorded as living in Azure after its copy has been
 * written, read back and checked.
 *
 * Storage::fake gives a real disk to write to and read back from, so the
 * verify-then-switch rule is exercised properly rather than mocked away.
 */
uses(Tests\TestCase::class);

beforeEach(function () {
    ArchiveTestSchema::create();
    Storage::fake(ArchiveFile::DISK_AZURE);

    $this->source = ArchiveSource::create([
        'name' => 'ArcMate',
        'host' => '172.16.8.10',
        'username' => 'noc_archive_reader',
        'password' => 'irrelevant-here',
        'transfer_anytime' => true,
    ]);

    $this->archive = Archive::create([
        'archive_source_id' => $this->source->id,
        'slug' => 'sps-invoices',
        'name' => 'SPS INVOICES',
        'arcmate_folder' => 'SPS_Invoices',
        'mode' => Archive::MODE_MIRROR,
    ]);

    $this->document = ArchiveDocument::create([
        'archive_id' => $this->archive->id,
        'arcmate_id' => 10,
        'status' => ArchiveDocument::STATUS_ACTIVE,
        'captured_at' => '2026-09-15 14:47:09',
    ]);

    // A real file on local disk standing in for the cifs mount: the transfer
    // reads an absolute path, and where that path is mounted is not its concern.
    $this->scratch = sys_get_temp_dir().'/arc-transfer-test';
    @mkdir($this->scratch, 0777, true);

    $this->makeFile = function (string $name, string $contents): ArchiveFile {
        $path = $this->scratch.'/'.$name;
        file_put_contents($path, $contents);

        return ArchiveFile::create([
            'archive_id' => $this->archive->id,
            'archive_document_id' => $this->document->id,
            'arcmate_id' => random_int(1000, 999999),
            'position' => 10,
            'original_name' => $name,
            'disk' => ArchiveFile::DISK_ARCMATE,
            'path' => $path,
            'arcmate_path' => $path,
        ]);
    };

    $this->transfers = new ArchiveTransferService;
});

afterEach(function () {
    foreach (glob($this->scratch.'/*') ?: [] as $file) {
        @unlink($file);
    }

    ArchiveTestSchema::drop();
});

// ─── The core promise ────────────────────────────────────────────

test('a transferred file is switched over only after its copy is verified', function () {
    $file = ($this->makeFile)('invoice.pdf', 'scanned invoice bytes');

    expect($this->transfers->transfer($file))->toBeTrue();

    $file->refresh();
    expect($file->disk)->toBe(ArchiveFile::DISK_AZURE);
    expect($file->path)->toStartWith('documents/sps-invoices/2026/09/');
    expect($file->sha256)->toBe(hash('sha256', 'scanned invoice bytes'));
    expect($file->size)->toBe(21);
    expect($file->transferred_at)->not->toBeNull();
    expect($file->transfer_error)->toBeNull();

    Storage::disk(ArchiveFile::DISK_AZURE)->assertExists($file->path);
    expect(Storage::disk(ArchiveFile::DISK_AZURE)->get($file->path))->toBe('scanned invoice bytes');
});

test('the original is never deleted from the ArcMate share', function () {
    $file = ($this->makeFile)('keep-me.pdf', 'still needed');

    $this->transfers->transfer($file);

    // The move is additive until somebody decides the old server can go.
    expect(file_exists($this->scratch.'/keep-me.pdf'))->toBeTrue();
    expect($file->fresh()->arcmate_path)->toBe($this->scratch.'/keep-me.pdf');
});

test('a file missing from the share is recorded, not switched over', function () {
    $file = ($this->makeFile)('gone.pdf', 'x');
    unlink($this->scratch.'/gone.pdf');

    expect($this->transfers->transfer($file))->toBeFalse();

    $file->refresh();
    expect($file->disk)->toBe(ArchiveFile::DISK_ARCMATE);
    expect($file->transfer_attempts)->toBe(1);
    expect($file->transfer_error)->toContain('Not on the share');
});

test('an empty file is refused rather than copied as nothing', function () {
    $file = ($this->makeFile)('empty.pdf', '');

    expect($this->transfers->transfer($file))->toBeFalse();
    expect($file->fresh()->disk)->toBe(ArchiveFile::DISK_ARCMATE);
});

// ─── The queue ───────────────────────────────────────────────────

test('the run moves queued files and records what it did', function () {
    ($this->makeFile)('a.pdf', 'aaa');
    ($this->makeFile)('b.pdf', 'bbbb');

    $stats = $this->transfers->run($this->source, maxFiles: 10);

    expect($stats['files'])->toBe(2);
    expect($stats['bytes'])->toBe(7);
    expect($stats['failed'])->toBe(0);
    expect(ArchiveFile::where('disk', ArchiveFile::DISK_AZURE)->count())->toBe(2);

    $run = ArchiveTransferRun::latest('id')->first();
    expect($run->files_done)->toBe(2);
    expect($run->finished_at)->not->toBeNull();
});

test('a paused archive is left alone', function () {
    ($this->makeFile)('paused.pdf', 'nope');
    $this->archive->forceFill(['transfer_paused' => true])->save();

    $stats = $this->transfers->run($this->source, maxFiles: 10);

    expect($stats['files'])->toBe(0);
    expect(ArchiveFile::where('disk', ArchiveFile::DISK_ARCMATE)->count())->toBe(1);
});

test('an encrypted archive is never transferred', function () {
    // The ArcMate "Test" project: its bytes are ciphertext only ArcMate can
    // undo, so copying them to Azure would move something unopenable.
    ($this->makeFile)('encrypted.pdf', 'cipher');
    $this->archive->forceFill(['readable' => false])->save();

    expect($this->transfers->run($this->source, maxFiles: 10)['files'])->toBe(0);
});

test('a file that has failed too often drops out of the queue', function () {
    $file = ($this->makeFile)('bad.pdf', 'x');
    $file->forceFill(['transfer_attempts' => ArchiveFile::MAX_TRANSFER_ATTEMPTS])->save();

    expect($this->transfers->run($this->source, maxFiles: 10)['files'])->toBe(0);

    // Until a person retries it from the Transfer page.
    expect($this->transfers->retryFailed())->toBe(1);
    expect($this->transfers->run($this->source, maxFiles: 10)['files'])->toBe(1);
});

// ─── The window ──────────────────────────────────────────────────

test('the transfer window is obeyed and wraps midnight', function () {
    $source = new ArchiveSource([
        'transfer_enabled' => true,
        'transfer_anytime' => false,
        'transfer_weekend_all_day' => false,
        'transfer_window_start' => '19:00',
        'transfer_window_end' => '07:00',
    ]);

    // A window of 19:00 to 07:00 is the normal case and the one a naive
    // start <= now <= end comparison gets wrong every single night.
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-16 22:00')))->toBeTrue();
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-16 03:00')))->toBeTrue();
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-16 11:00')))->toBeFalse();
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-16 18:59')))->toBeFalse();
});

test('the Saudi weekend is Friday and Saturday', function () {
    $source = new ArchiveSource([
        'transfer_enabled' => true,
        'transfer_anytime' => false,
        'transfer_weekend_all_day' => true,
        'transfer_window_start' => '19:00',
        'transfer_window_end' => '07:00',
    ]);

    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-18 11:00')))->toBeTrue();  // Friday
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-19 11:00')))->toBeTrue();  // Saturday
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-17 11:00')))->toBeFalse(); // Thursday
});

test('a run outside the window does nothing and says why', function () {
    ($this->makeFile)('waiting.pdf', 'later');

    $this->source->forceFill([
        'transfer_anytime' => false,
        'transfer_weekend_all_day' => false,
        'transfer_window_start' => '19:00',
        'transfer_window_end' => '07:00',
    ])->save();

    CarbonImmutable::setTestNow('2026-09-17 11:00');   // a Thursday morning

    $stats = $this->transfers->run($this->source->fresh(), maxFiles: 10);

    expect($stats['files'])->toBe(0);
    expect($stats['reason'])->toContain('Outside the transfer window');
    // A run that did nothing must not be logged as a run that moved nothing,
    // or the measured speed on the Transfer page collapses to zero.
    expect(ArchiveTransferRun::count())->toBe(0);

    CarbonImmutable::setTestNow();
});

test('a freshly created source is usable without being reloaded first', function () {
    // The regression this pins: a database default is applied by the INSERT,
    // so the model handed back by create() still had null for transfer_enabled.
    // `! null` is true, and the worker decided transfers were switched off —
    // while the identical row, reloaded, said they were on. Intermittent by
    // nature, and it would have looked like "the transfer just never runs".
    $source = ArchiveSource::create([
        'name' => 'Fresh',
        'host' => '172.16.8.10',
        'username' => 'noc_archive_reader',
        'password' => 'x',
    ]);

    expect($source->transfer_enabled)->toBeTrue();
    expect($source->transferAllowedNow(CarbonImmutable::parse('2026-09-16 22:00')))->toBeTrue();

    // And a partially-loaded model must not read as "disabled" either.
    $partial = ArchiveSource::query()->select('id')->find($source->id);
    expect($partial->transferAllowedNow(CarbonImmutable::parse('2026-09-16 22:00')))->toBeTrue();
});

test('a new archive defaults to readable and unpaused in memory', function () {
    $archive = Archive::create(['slug' => 'fresh', 'name' => 'Fresh']);

    // Both are read by the transfer worker and by AI; a null would silently
    // exclude the archive from both.
    expect($archive->readable)->toBeTrue();
    expect($archive->transfer_paused)->toBeFalse();
    expect($archive->mode)->toBe(Archive::MODE_MIRROR);
});

test('turning the transfer off stops it entirely', function () {
    ($this->makeFile)('off.pdf', 'nope');
    $this->source->forceFill(['transfer_enabled' => false])->save();

    expect($this->transfers->run($this->source->fresh(), maxFiles: 10)['files'])->toBe(0);
});

// ─── Verification afterwards ─────────────────────────────────────

test('verifying a sample catches a copy that no longer matches', function () {
    $good = ($this->makeFile)('good.pdf', 'intact');
    $bad = ($this->makeFile)('bad.pdf', 'original');

    $this->transfers->transfer($good);
    $this->transfers->transfer($bad);

    // Something rewrites the blob after the fact.
    Storage::disk(ArchiveFile::DISK_AZURE)->put($bad->fresh()->path, 'tampered');

    $result = $this->transfers->verifySample(10);

    expect($result['checked'])->toBe(2);
    expect($result['ok'])->toBe(1);
    expect($result['mismatched'])->toHaveCount(1);
    expect($result['mismatched'][0]['file'])->toBe($bad->id);
});

test('the same file transferred twice lands in one place', function () {
    $file = ($this->makeFile)('retry.pdf', 'contents');

    $this->transfers->transfer($file);
    $first = $file->fresh()->path;

    // Force it back into the queue as though the switch-over had been lost.
    $file->forceFill(['disk' => ArchiveFile::DISK_ARCMATE, 'path' => $this->scratch.'/retry.pdf'])->save();

    $this->transfers->transfer($file->fresh());

    // A deterministic target, so a retry overwrites its own attempt instead of
    // leaving a second copy behind to pay for.
    expect($file->fresh()->path)->toBe($first);
    expect(Storage::disk(ArchiveFile::DISK_AZURE)->allFiles())->toHaveCount(1);
});

test('a date range moves only the documents scanned inside it', function () {
    // 372 GB in one decision is a lot; a range turns it into several. The date is
    // the DOCUMENT's capture date, because that is what people mean by "last
    // year's invoices" — and it is the same value the blob path is built from.
    $old = ArchiveDocument::create([
        'archive_id' => $this->archive->id,
        'arcmate_id' => 99,
        'status' => ArchiveDocument::STATUS_ACTIVE,
        'captured_at' => '2013-04-02 09:00:00',
    ]);

    $recent = ($this->makeFile)('recent.pdf', 'this year');

    $path = $this->scratch.'/old.pdf';
    file_put_contents($path, 'old history');
    $older = ArchiveFile::create([
        'archive_id' => $this->archive->id,
        'archive_document_id' => $old->id,
        'arcmate_id' => 4242,
        'position' => 10,
        'original_name' => 'old.pdf',
        'disk' => ArchiveFile::DISK_ARCMATE,
        'path' => $path,
        'arcmate_path' => $path,
    ]);

    $this->source->forceFill(['transfer_from' => '2026-01-01', 'transfer_to' => '2026-12-31'])->save();

    $stats = $this->transfers->run($this->source->fresh(), maxFiles: 10);

    expect($stats['files'])->toBe(1);
    expect($recent->fresh()->disk)->toBe(ArchiveFile::DISK_AZURE);
    // Outside the range: left exactly where it was, not failed, not skipped-with-error.
    expect($older->fresh()->disk)->toBe(ArchiveFile::DISK_ARCMATE);
    expect($older->fresh()->transfer_attempts)->toBe(0);
});

test('one open end of a range still limits the transfer', function () {
    // "Everything from 2024 onwards" is a from with no to, and must not be read
    // as "no range at all".
    $old = ArchiveDocument::create([
        'archive_id' => $this->archive->id,
        'arcmate_id' => 98,
        'status' => ArchiveDocument::STATUS_ACTIVE,
        'captured_at' => '2013-04-02 09:00:00',
    ]);

    $path = $this->scratch.'/ancient.pdf';
    file_put_contents($path, 'ancient');
    ArchiveFile::create([
        'archive_id' => $this->archive->id,
        'archive_document_id' => $old->id,
        'arcmate_id' => 4243,
        'position' => 10,
        'original_name' => 'ancient.pdf',
        'disk' => ArchiveFile::DISK_ARCMATE,
        'path' => $path,
        'arcmate_path' => $path,
    ]);

    ($this->makeFile)('current.pdf', 'current');

    $this->source->forceFill(['transfer_from' => '2026-01-01', 'transfer_to' => null])->save();

    expect($this->transfers->run($this->source->fresh(), maxFiles: 10)['files'])->toBe(1);
});

// ─── Where the temporary copy goes ───────────────────────────────

test('the temporary copy is not written into the viewer cache', function () {
    // The bug that made EVERY transfer on NOC2 fail. copyToTemp() borrowed
    // TiffConverter::cacheDirectory(), which is created by whichever user gets
    // there first — the viewer, as www-data — and its mode gives a non-owner
    // traverse but not write. The scheduler runs as a different user, so it
    // could not create the file, and the error blamed disk space on a host with
    // 87 GB free.
    //
    // Asserted by making the cache directory unwritable: a transfer that still
    // succeeds is one that is not writing there. On Windows chmod does not
    // remove write permission, so the check is skipped rather than passing
    // vacuously.
    $cache = (new \App\Services\Archive\TiffConverter)->cacheDirectory();
    @mkdir($cache, 0770, true);
    @chmod($cache, 0500);

    if (is_writable($cache)) {
        @chmod($cache, 0770);
        $this->markTestSkipped('this filesystem ignores chmod, so an unwritable directory cannot be simulated');
    }

    try {
        $file = ($this->makeFile)('cache-locked.pdf', 'invoice bytes');

        expect($this->transfers->transfer($file))->toBeTrue();
        expect($file->fresh()->disk)->toBe(ArchiveFile::DISK_AZURE);
        expect($file->fresh()->transfer_error)->toBeNull();
    } finally {
        @chmod($cache, 0770);
    }
});

test('the temporary copy is cleaned up, wherever it lives', function () {
    // 372 GB moves through this directory one file at a time. A temp file left
    // behind per transfer would fill the disk long before the archive finished.
    $before = glob(sys_get_temp_dir().'/archive-transfer-*') ?: [];

    $file = ($this->makeFile)('leaves-nothing.pdf', 'invoice bytes');
    expect($this->transfers->transfer($file))->toBeTrue();

    $after = glob(sys_get_temp_dir().'/archive-transfer-*') ?: [];

    expect($after)->toBe($before);
});
