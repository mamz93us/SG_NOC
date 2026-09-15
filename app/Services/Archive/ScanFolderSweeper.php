<?php

namespace App\Services\Archive;

use App\Models\Archive\ArchiveFile;
use App\Models\Archive\ArchiveInboxItem;
use App\Models\Archive\ArchiveScanEndpoint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scans a copier wrote into its SFTP folder, taken into the inbox.
 *
 * The machines that can do SFTP write straight into a per-destination SFTPGo
 * account whose home is a folder under the scan root, named after the account. So
 * the first path segment of every file identifies the endpoint it arrived for,
 * exactly as it does for the device backups.
 *
 * Two rules carried over from the backup sweeper, both learned the hard way:
 *
 *   **A file is only taken once its mtime has stopped moving.** An in-progress
 *   transfer keeps bumping it, and half a scan filed as a document is worse than
 *   a scan that arrives a minute late.
 *
 *   **The local copy is deleted only after the write to Azure is read back.** A
 *   copy nobody checked is not a copy, it is an assumption — and this is the only
 *   copy, because the original is a sheet of paper already back in the tray.
 *
 * Not shared code with SweepSftpBackupsToAzure despite the shape: that one writes
 * to a different disk, records a different table, and reports overdue devices as
 * NOC events. What they have in common is two rules and a directory layout, and
 * those are cheaper to restate than to abstract.
 */
class ScanFolderSweeper
{
    /** Partial uploads well-behaved clients leave behind. */
    private const IGNORE = ['.part', '.filepart', '.tmp', '.partial', '.lock'];

    public function __construct(private ?InboxService $inbox = null)
    {
        $this->inbox ??= new InboxService;
    }

    /**
     * Sweep every scan folder.
     *
     * @param  callable|null  $shouldStop  true when the time budget is spent
     * @return array{files:int, skipped:int, failed:int, reason:?string}
     */
    public function run(int $maxFiles = 50, ?callable $shouldStop = null): array
    {
        $shouldStop ??= static fn (): bool => false;
        $stats = ['files' => 0, 'skipped' => 0, 'failed' => 0, 'reason' => null];

        $root = rtrim((string) config('archive_portal.scan_folder_root'), '/');

        if ($root === '' || ! @is_dir($root)) {
            // Not set up on this host — every dev box, and the NOC until the scan
            // folders exist. Not an error.
            $stats['reason'] = 'No scan folder root on this host.';

            return $stats;
        }

        $endpoints = ArchiveScanEndpoint::query()
            ->enabled()
            ->where('type', ArchiveScanEndpoint::TYPE_FOLDER)
            ->whereNotNull('sftpgo_username')
            ->get();

        if ($endpoints->isEmpty()) {
            return $stats;
        }

        foreach ($endpoints as $endpoint) {
            if ($shouldStop()) {
                $stats['reason'] = 'Time budget spent; the rest sweeps next run.';
                break;
            }

            $done = $this->endpoint($endpoint, $maxFiles - $stats['files'], $shouldStop);

            $stats['files'] += $done['files'];
            $stats['skipped'] += $done['skipped'];
            $stats['failed'] += $done['failed'];

            if ($stats['files'] >= $maxFiles) {
                $stats['reason'] = 'Reached the file limit for one run.';
                break;
            }
        }

        return $stats;
    }

    /**
     * One destination's folder.
     *
     * @return array{files:int, skipped:int, failed:int}
     */
    private function endpoint(ArchiveScanEndpoint $endpoint, int $remaining, callable $shouldStop): array
    {
        $stats = ['files' => 0, 'skipped' => 0, 'failed' => 0];
        $home = $endpoint->homeDir();

        if (! @is_dir($home)) {
            return $stats;
        }

        $stability = (int) config('archive_portal.scan_folder_stability_seconds');
        $limit = max(1, (int) config('archive_portal.max_scan_mb')) * 1024 * 1024;
        $now = time();
        $arrived = false;

        foreach ($this->files($home) as $path) {
            if ($stats['files'] >= $remaining || $shouldStop()) {
                break;
            }

            // Still being written: leave it for the next run.
            if (($now - (int) @filemtime($path)) < $stability) {
                $stats['skipped']++;

                continue;
            }

            $size = (int) @filesize($path);

            if ($size === 0) {
                // An empty file is a failed scan on the copier's side. Removed so
                // it is not counted for ever.
                @unlink($path);
                $stats['skipped']++;

                continue;
            }

            if ($size > $limit) {
                Log::warning('[archive] scan folder file too large, left in place: '.$path.' ('.$size.' bytes)');
                $endpoint->recordError(basename($path).' is larger than the '.config('archive_portal.max_scan_mb').' MB limit and was left in the folder.');
                $stats['skipped']++;

                continue;
            }

            if ($this->take($endpoint, $path, $size)) {
                $stats['files']++;
                $arrived = true;
            } else {
                $stats['failed']++;
            }
        }

        if ($arrived) {
            $endpoint->recordArrival();
        }

        return $stats;
    }

    /**
     * Move one file into the inbox.
     *
     * Written to Azure, read back, and only then deleted locally — the original
     * is paper that has already gone back in the tray, so this copy is the only
     * one there is.
     */
    private function take(ArchiveScanEndpoint $endpoint, string $path, int $size): bool
    {
        $name = basename($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (! in_array($extension, InboxService::ACCEPTED, true)) {
            // Not a scan. Left alone rather than deleted: it is somebody's file in
            // a folder they have write access to, and deleting it is not this
            // sweep's business.
            return false;
        }

        $sha256 = @hash_file('sha256', $path);

        if ($sha256 === false) {
            return false;
        }

        // The same scan twice — a copier retrying, or a sweep interrupted after
        // the upload but before the delete — is recognised, not duplicated.
        $existing = ArchiveInboxItem::query()
            ->where('sha256', $sha256)
            ->where('status', ArchiveInboxItem::STATUS_WAITING)
            ->where(function ($query) use ($endpoint) {
                $query->where('user_id', $endpoint->user_id)
                    ->orWhere('archive_id', $endpoint->archive_id);
            })
            ->exists();

        if ($existing) {
            @unlink($path);

            return true;
        }

        $target = 'inbox/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;
        $disk = Storage::disk(ArchiveFile::DISK_AZURE);

        try {
            $stream = @fopen($path, 'rb');

            if (! $stream) {
                return false;
            }

            try {
                $written = $disk->writeStream($target, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            if ($written === false) {
                throw new \RuntimeException('The storage account refused the write.');
            }

            // Read the size back. This is the whole reason the local file is still
            // here: until the copy is checked, it is the only one.
            $landed = (int) $disk->size($target);

            if ($landed !== $size) {
                $disk->delete($target);
                throw new \RuntimeException("Copy is {$landed} bytes, the scan is {$size}.");
            }
        } catch (\Throwable $e) {
            Log::error('[archive] scan folder upload failed for '.$path.': '.$e->getMessage());
            $endpoint->recordError('A scan could not be stored: '.$e->getMessage());

            return false;
        }

        ArchiveInboxItem::create([
            'user_id' => $endpoint->user_id,
            'archive_id' => $endpoint->archive_id,
            'source' => ArchiveInboxItem::SOURCE_FOLDER,
            'source_detail' => mb_substr($endpoint->label, 0, 255),
            'path' => $target,
            'original_name' => $name,
            'size' => $size,
            'sha256' => $sha256,
            'ai_status' => ArchiveInboxItem::AI_QUEUED,
            // The copier's own timestamp, not the sweep's: a scan made at 17:55
            // and swept at 18:00 belongs to when it was scanned.
            'received_at' => date('Y-m-d H:i:s', (int) @filemtime($path) ?: time()),
            'meta' => ['endpoint' => $endpoint->getKey(), 'from_folder' => $endpoint->sftpgoUsername()],
        ]);

        if (! @unlink($path)) {
            // Uploaded but not removed: the dedupe above stops a second item, so
            // this is untidy rather than harmful.
            Log::warning('[archive] swept scan could not be deleted locally: '.$path);
        }

        return true;
    }

    /**
     * Files in a destination's folder, oldest first, partials ignored.
     *
     * Only one level deep plus subfolders a copier makes by date — a scanner that
     * writes into a dated subdirectory is normal, so the walk is recursive but
     * shallow-ordered.
     *
     * @return array<int,string>
     */
    private function files(string $home): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            $name = $file->getFilename();

            if (str_starts_with($name, '.')) {
                continue;
            }

            foreach (self::IGNORE as $suffix) {
                if (str_ends_with(strtolower($name), $suffix)) {
                    continue 2;
                }
            }

            $found[] = $file->getPathname();
        }

        usort($found, fn (string $a, string $b) => (@filemtime($a) ?: 0) <=> (@filemtime($b) ?: 0));

        return $found;
    }
}
