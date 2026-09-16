<?php

namespace App\Services\Archive;

use App\Models\Archive\Archive;
use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveSource;
use App\Models\Archive\ArchiveTransferRun;
use App\Support\Audit\Auditor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Moves ArcMate's files into the NOC's own Azure storage, one verified file at
 * a time.
 *
 * 372 GB in 622,000 files. The whole design follows from two facts about that:
 * it takes nights rather than minutes, and it must be safe to stop dead at any
 * moment.
 *
 * So a file is only ever marked as living in Azure AFTER its copy has been
 * written, read back and checked. Until that moment the row still says
 * `arcmate`, the viewer still serves it from the share, and the worker will
 * simply try it again. There is no window in which a file is recorded in one
 * place and actually in the other.
 *
 * The copy itself reads the source ONCE. The obvious implementation — hash the
 * file, then upload it — reads every byte twice, which across 372 GB of cifs
 * traffic is an extra 372 GB pulled over the network for nothing. Instead the
 * bytes are streamed to a local temporary file while the hash is computed
 * incrementally, and the upload reads from local disk.
 */
class ArchiveTransferService
{
    /** Read size. Large enough to keep cifs happy, small enough to stay cheap. */
    private const CHUNK = 1048576;

    public function __construct(private ?FileViewer $viewer = null) {}

    /**
     * Transfer until the budget, the file cap or the window runs out.
     *
     * @param  callable|null  $shouldStop  true when the time budget is spent
     * @return array{files:int, bytes:int, failed:int, stopped:bool, reason:?string}
     */
    public function run(ArchiveSource $source, int $maxFiles = 500, ?callable $shouldStop = null): array
    {
        $shouldStop ??= static fn (): bool => false;

        $stats = ['files' => 0, 'bytes' => 0, 'failed' => 0, 'stopped' => false, 'reason' => null];

        if (! $source->transferAllowedNow()) {
            $stats['reason'] = 'Outside the transfer window.';

            return $stats;
        }

        if (! $this->diskReady()) {
            $stats['reason'] = 'Azure storage is not configured yet.';

            return $stats;
        }

        $run = ArchiveTransferRun::create(['started_at' => now()]);
        $startedAt = microtime(true);
        $cap = $source->transferBytesPerSecond();

        // The bulk writes here are the transfer's own bookkeeping, not events a
        // person did: 622,000 audit rows would be larger than the thing they
        // describe. A person pausing or retrying IS logged, on the page.
        Auditor::withoutAuditing(function () use ($source, $maxFiles, $shouldStop, $cap, $startedAt, &$stats) {
            while ($maxFiles > $stats['files'] + $stats['failed']) {
                if ($shouldStop()) {
                    $stats['stopped'] = true;
                    $stats['reason'] = 'Time budget spent.';
                    break;
                }

                // Re-checked every file, not once per run: a run can outlive
                // 07:00, and somebody pausing an archive should be obeyed
                // within seconds rather than at the end of a batch.
                if (! $source->fresh()->transferAllowedNow()) {
                    $stats['stopped'] = true;
                    $stats['reason'] = 'Left the transfer window.';
                    break;
                }

                $file = $this->next($source);

                if (! $file) {
                    $stats['reason'] = 'Nothing left to transfer.';
                    break;
                }

                if ($this->transfer($file)) {
                    $stats['files']++;
                    $stats['bytes'] += (int) $file->size;
                } else {
                    $stats['failed']++;
                }

                $this->throttle($cap, $stats['bytes'], microtime(true) - $startedAt);
            }
        });

        $elapsed = max(0.001, microtime(true) - $startedAt);

        $run->forceFill([
            'finished_at' => now(),
            'files_done' => $stats['files'],
            'bytes_done' => $stats['bytes'],
            'files_failed' => $stats['failed'],
            'avg_mbps' => round($stats['bytes'] / $elapsed / 1048576, 2),
            'note' => $stats['reason'],
        ])->save();

        return $stats;
    }

    /**
     * One file: copy, verify, then switch it over.
     *
     * Returns false and records why on any failure, leaving the file exactly
     * where it was.
     */
    public function transfer(ArchiveFile $file): bool
    {
        if (! $file->isOnArcMate()) {
            return false;
        }

        $source = $file->path;

        if ($source === '' || ! @is_file($source)) {
            $this->fail($file, 'Not on the share at '.$source);

            return false;
        }

        $temporary = null;

        try {
            [$temporary, $sha256, $bytes] = $this->copyToTemp($source);

            if ($bytes === 0) {
                $this->fail($file, 'The file on the share is empty.');

                return false;
            }

            $target = $this->targetPath($file);
            $disk = Storage::disk(ArchiveFile::DISK_AZURE);

            $stream = fopen($temporary, 'rb');
            $disk->writeStream($target, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            // Read the size back from Azure rather than trusting the write.
            // This is the whole point of the exercise: a copy nobody checked is
            // not a copy, it is an assumption.
            $written = (int) $disk->size($target);

            if ($written !== $bytes) {
                $disk->delete($target);
                $this->fail($file, "Copy is {$written} bytes, the original is {$bytes}.");

                return false;
            }

            $file->forceFill([
                'disk' => ArchiveFile::DISK_AZURE,
                'path' => $target,
                'size' => $bytes,
                'sha256' => $sha256,
                'transferred_at' => now(),
                'transfer_error' => null,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $this->fail($file, $e->getMessage());

            return false;
        } finally {
            if ($temporary && @is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Re-download random transferred files and check them against what was
     * recorded.
     *
     * The transfer verifies each file as it goes, but that proves the write
     * landed — not that it is still there and still right weeks later. This is
     * the periodic answer to "is the copy we are about to rely on real?", and
     * it is what has to be green before the ArcMate server is switched off.
     *
     * @return array{checked:int, ok:int, mismatched:array<int,array<string,mixed>>}
     */
    public function verifySample(int $count = 20): array
    {
        $files = ArchiveFile::query()
            ->where('disk', ArchiveFile::DISK_AZURE)
            ->whereNotNull('sha256')
            ->inRandomOrder()
            ->limit(max(1, min(200, $count)))
            ->get();

        $result = ['checked' => 0, 'ok' => 0, 'mismatched' => []];
        $disk = Storage::disk(ArchiveFile::DISK_AZURE);

        foreach ($files as $file) {
            $result['checked']++;

            try {
                $stream = $disk->readStream($file->path);

                if (! $stream) {
                    $result['mismatched'][] = ['file' => $file->getKey(), 'why' => 'Not in Azure'];

                    continue;
                }

                $context = hash_init('sha256');
                hash_update_stream($context, $stream);
                fclose($stream);
                $sha = hash_final($context);

                if ($sha === $file->sha256) {
                    $result['ok']++;
                } else {
                    $result['mismatched'][] = [
                        'file' => $file->getKey(),
                        'path' => $file->path,
                        'why' => 'Checksum differs from the one recorded when it was copied',
                    ];
                }
            } catch (\Throwable $e) {
                $result['mismatched'][] = ['file' => $file->getKey(), 'why' => $e->getMessage()];
            }
        }

        return $result;
    }

    /** Clear the errors so failed files are picked up again. */
    public function retryFailed(?int $archiveId = null): int
    {
        return ArchiveFile::query()
            ->transferFailed()
            ->when($archiveId, fn ($q) => $q->where('archive_id', $archiveId))
            ->update(['transfer_attempts' => 0, 'transfer_error' => null]);
    }

    // ─── Internals ───────────────────────────────────────────────

    /**
     * The next file to move.
     *
     * Ordered by the archive's priority, then newest first — if the move is
     * ever abandoned half way, the files people actually open should be the
     * ones that made it across.
     */
    private function next(?ArchiveSource $source = null): ?ArchiveFile
    {
        $from = $source?->transfer_from;
        $to = $source?->transfer_to;

        return ArchiveFile::query()
            ->transferQueue()
            ->join('archives', 'archives.id', '=', 'archive_files.archive_id')
            // A chosen date range moves one stretch of history at a time, so
            // 372 GB is several small decisions instead of one big one. The date
            // is the DOCUMENT's capture date, not the file's, because that is what
            // people mean by "last year's invoices" — and it is the same value the
            // blob path is built from.
            ->when($from || $to, function ($query) use ($from, $to) {
                $query->join('archive_documents', 'archive_documents.id', '=', 'archive_files.archive_document_id');

                if ($from) {
                    $query->where('archive_documents.captured_at', '>=', $from.' 00:00:00');
                }

                if ($to) {
                    $query->where('archive_documents.captured_at', '<=', $to.' 23:59:59');
                }
            })
            ->orderBy('archives.transfer_priority')
            ->orderByDesc('archive_files.id')
            ->select('archive_files.*')
            ->first();
    }

    /**
     * Stream the source to a local temporary file, hashing as it goes.
     *
     * One pass over the network. Hashing afterwards would read all 372 GB a
     * second time over cifs.
     *
     * @return array{0:string, 1:string, 2:int} temp path, sha256, bytes
     */
    private function copyToTemp(string $source): array
    {
        $directory = (new TiffConverter)->cacheDirectory();
        $temporary = $directory.'/transfer-'.bin2hex(random_bytes(8)).'.tmp';

        $in = @fopen($source, 'rb');

        if (! $in) {
            throw new \RuntimeException('Could not read the file on the share.');
        }

        $out = @fopen($temporary, 'wb');

        if (! $out) {
            fclose($in);
            throw new \RuntimeException('Could not write a temporary copy — check disk space.');
        }

        $context = hash_init('sha256');
        $bytes = 0;

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);

                if ($chunk === false) {
                    throw new \RuntimeException('Read failed part way through — the share may have dropped.');
                }

                if ($chunk === '') {
                    continue;
                }

                hash_update($context, $chunk);
                $bytes += strlen($chunk);

                if (fwrite($out, $chunk) === false) {
                    throw new \RuntimeException('Write failed — check disk space.');
                }
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        @chmod($temporary, 0644);

        return [$temporary, hash_final($context), $bytes];
    }

    /**
     * Where a file lives in Azure.
     *
     * Deterministic, so a retry overwrites its own half-finished attempt rather
     * than leaving a second copy behind. Keyed by the file's own id because
     * ArcMate's names are only unique within a timestamp folder.
     *
     * Public because filing a newly captured document has to put its files in
     * exactly this layout (InboxService moves them here out of `inbox/`). Two
     * implementations of this path is how a filed document becomes unfindable
     * later, so there is one and both callers use it.
     */
    public function targetPath(ArchiveFile $file): string
    {
        $slug = $file->archive?->slug ?: 'archive-'.$file->archive_id;
        $captured = $file->document?->captured_at;
        $year = $captured?->format('Y') ?: 'undated';
        $month = $captured?->format('m') ?: '00';
        $extension = $file->extension();

        return sprintf(
            'documents/%s/%s/%s/%d/%d%s',
            $slug,
            $year,
            $month,
            $file->archive_document_id,
            $file->getKey(),
            $extension !== '' ? '.'.$extension : '',
        );
    }

    private function fail(ArchiveFile $file, string $reason): void
    {
        $file->forceFill([
            'transfer_attempts' => (int) $file->transfer_attempts + 1,
            'transfer_error' => mb_substr($reason, 0, 1000),
        ])->save();

        Log::warning('[archive] transfer failed for file '.$file->getKey().': '.$reason);
    }

    /** Whether Azure is actually configured, without letting a throw escape. */
    /** Why the storage could not be reached, or null when it can. */
    public function storageProblem(): ?string
    {
        try {
            Storage::disk(ArchiveFile::DISK_AZURE)->exists('.archive-healthcheck');

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private function diskReady(): bool
    {
        try {
            Storage::disk(ArchiveFile::DISK_AZURE)->exists('.archive-healthcheck');

            return true;
        } catch (\Throwable $e) {
            // error, not warning: production runs LOG_LEVEL=error, and this stops
            // the whole transfer. Logged as a warning it was invisible — the
            // transfer simply did nothing, every minute, with no record anywhere
            // and no run row to look at either.
            Log::error('[archive] Azure disk not ready: '.$e->getMessage());

            return false;
        }
    }

    /** Hold the average under the cap, if one is set. */
    private function throttle(?int $bytesPerSecond, int $bytesSoFar, float $elapsed): void
    {
        if (! $bytesPerSecond || $bytesSoFar <= 0) {
            return;
        }

        $target = $bytesSoFar / $bytesPerSecond;

        if ($target > $elapsed) {
            usleep((int) min(5_000_000, ($target - $elapsed) * 1_000_000));
        }
    }
}
