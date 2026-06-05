<?php

namespace App\Console\Commands;

use App\Models\NocEvent;
use App\Models\SftpBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Sweeps the chrooted SFTP inbox on the NOC and ships each backup file to Azure
 * Blob. Network devices (firewalls, UCM, switches, …) push their backups into
 * the inbox over SFTP (see deployment/sftp/); this command — driven by the
 * scheduler, since production has no queue worker — uploads each *stable* file
 * to the `azure_backups` disk, records it in `sftp_backups`, and deletes the
 * local copy once the upload is verified, so the inbox can never fill the VM
 * disk.
 *
 * Idempotency: the blob key is derived deterministically from the file (its
 * inbox path + mtime), so a crash between "uploaded" and "deleted locally" just
 * re-finds the same blob next tick instead of duplicating it, and a failed
 * upload retries cleanly.
 */
class SweepSftpBackupsToAzure extends Command
{
    protected $signature = 'sftp-backups:sweep
                            {--dry-run : List what would be uploaded without touching Azure or local files}
                            {--keep : Do not delete local files after a successful upload (overrides config)}';

    protected $description = 'Sweep the chrooted SFTP inbox, stream each stable backup file to Azure Blob, then delete the local copy.';

    public function handle(): int
    {
        // A single big backup can take a while to stream; the scheduler runs us
        // in the background, so lift the CLI time limit defensively.
        @set_time_limit(0);

        $inbox = (string) config('sftp_backup.inbox_path');
        if ($inbox === '' || ! is_dir($inbox)) {
            // Not set up on this host (e.g. local dev / before the VPS service
            // exists). Nothing to do — don't error the scheduler.
            $this->info("SFTP inbox not present ({$inbox}); nothing to sweep.");

            return self::SUCCESS;
        }

        $disk = (string) config('sftp_backup.disk', 'azure_backups');
        $dryRun = (bool) $this->option('dry-run');
        $deleteAfter = ! $this->option('keep') && (bool) config('sftp_backup.delete_after_upload', true);
        $stability = (int) config('sftp_backup.stability_seconds', 120);
        $maxFiles = (int) config('sftp_backup.max_files_per_run', 25);
        $maxBytes = config('sftp_backup.max_file_bytes');
        $ignore = (array) config('sftp_backup.ignore_suffixes', []);

        // Make sure the target disk is actually configured before walking files
        // — the azure driver throws if account/key are missing. If Azure isn't
        // set up yet, leave the files in place and bail cleanly so the scheduler
        // doesn't spam failures (no data loss — they sweep once it's configured).
        if (! $dryRun) {
            try {
                Storage::disk($disk)->exists('.sftp-backups-healthcheck');
            } catch (\Throwable $e) {
                $this->warn("Backup disk [{$disk}] is not ready: {$e->getMessage()}");

                return self::SUCCESS;
            }
        }

        $finder = (new Finder)
            ->files()
            ->ignoreDotFiles(true)
            ->in($inbox)
            ->sortByModifiedTime();

        foreach ($ignore as $suffix) {
            $finder->notName('*'.$suffix);
        }

        $now = time();
        $uploaded = $failed = $skipped = $processed = 0;
        $failures = [];

        foreach ($finder as $file) {
            /** @var SplFileInfo $file */
            if ($maxFiles > 0 && $processed >= $maxFiles) {
                $this->line("Reached max-files-per-run ({$maxFiles}); the rest sweep next tick.");
                break;
            }

            // Stability guard: skip files whose mtime is still inside the window
            // — an in-progress push keeps bumping mtime, so this avoids grabbing
            // a half-written file.
            if (($now - $file->getMTime()) < $stability) {
                continue;
            }

            $processed++;
            $rel = str_replace('\\', '/', $file->getRelativePathname());
            $status = $this->handleFile($file, $rel, $disk, $deleteAfter, $dryRun, $maxBytes, $failures);

            match ($status) {
                'uploaded' => $uploaded++,
                'failed' => $failed++,
                'skipped' => $skipped++,
                default => null,
            };
        }

        if ($dryRun) {
            $this->info("Dry run — would upload {$uploaded}, skip {$skipped}.");

            return self::SUCCESS;
        }

        $this->info("Sweep complete — uploaded {$uploaded}, failed {$failed}, skipped {$skipped}.");
        $this->syncFailureEvent($failed, $failures);

        return self::SUCCESS;
    }

    /**
     * Upload one file and return 'uploaded' | 'failed' | 'skipped'.
     *
     * @param  array<int, string>  $failures  Collected "rel: error" lines (by ref).
     */
    private function handleFile(
        SplFileInfo $file,
        string $rel,
        string $disk,
        bool $deleteAfter,
        bool $dryRun,
        int|string|null $maxBytes,
        array &$failures,
    ): string {
        $full = $file->getRealPath();
        if ($full === false || ! is_file($full)) {
            // Vanished between listing and now (e.g. another process moved it).
            return 'skipped';
        }

        $size = (int) $file->getSize();
        $mtime = $file->getMTime();
        $dir = str_replace('\\', '/', dirname($rel));
        $hasDir = $dir !== '' && $dir !== '.';
        $source = $hasDir ? explode('/', $dir)[0] : null;

        // Deterministic blob key: source subfolder + file mtime stamp + name.
        $blobPath = ($hasDir ? $dir.'/' : '').date('Ymd-His', $mtime).'-'.$file->getFilename();

        // Oversized guard — flag and leave on disk for an operator to handle.
        if ($maxBytes !== null && $size > (int) $maxBytes) {
            $this->warn("  · SKIP {$rel} — {$size} bytes exceeds max_file_bytes.");
            if (! $dryRun) {
                $this->record($blobPath, $source, $rel, $file->getFilename(), $size, null, $disk, SftpBackup::STATUS_SKIPPED, 'Exceeds max_file_bytes', $mtime, false);
            }

            return 'skipped';
        }

        if ($dryRun) {
            $this->line("  · would upload {$rel} → [{$disk}] {$blobPath} ({$size} bytes)");

            return 'uploaded';
        }

        try {
            $diskApi = Storage::disk($disk);

            // Crash-recovery / idempotency: if the deterministic blob already
            // exists at the right size, trust it and skip the re-send.
            $alreadyThere = $diskApi->exists($blobPath) && (int) $diskApi->size($blobPath) === $size;

            $sha = hash_file('sha256', $full) ?: null;

            if (! $alreadyThere) {
                $stream = fopen($full, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Could not open local file for reading.');
                }
                try {
                    $ok = $diskApi->writeStream($blobPath, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                if ($ok === false) {
                    throw new \RuntimeException('writeStream returned false.');
                }

                // Verify the byte count landed before we trust (and delete) it.
                $remoteSize = (int) $diskApi->size($blobPath);
                if ($remoteSize !== $size) {
                    throw new \RuntimeException("Size mismatch after upload (local {$size}, remote {$remoteSize}).");
                }
            }

            $this->record($blobPath, $source, $rel, $file->getFilename(), $size, $sha, $disk, SftpBackup::STATUS_UPLOADED, null, $mtime, true);
            $this->line("  · uploaded {$rel} → [{$disk}] {$blobPath} ({$size} bytes)".($alreadyThere ? ' [already present]' : ''));

            if ($deleteAfter && ! @unlink($full)) {
                Log::warning("sftp-backups: uploaded but could not delete local file {$full}");
                $this->warn('    (uploaded, but local delete failed — check inbox permissions)');
            }

            return 'uploaded';
        } catch (\Throwable $e) {
            // Best-effort cleanup of a partial remote write so the retry is clean.
            try {
                Storage::disk($disk)->delete($blobPath);
            } catch (\Throwable) {
            }

            $this->record($blobPath, $source, $rel, $file->getFilename(), $size, null, $disk, SftpBackup::STATUS_FAILED, $e->getMessage(), $mtime, false);
            Log::error("sftp-backups: failed to upload {$rel}: ".$e->getMessage());
            $this->error("  · FAILED {$rel}: {$e->getMessage()}");
            $failures[] = "{$rel}: {$e->getMessage()}";

            return 'failed';
        }
    }

    private function record(
        string $blobPath,
        ?string $source,
        string $rel,
        string $filename,
        ?int $size,
        ?string $sha,
        string $disk,
        string $status,
        ?string $error,
        int $mtime,
        bool $uploaded,
    ): void {
        SftpBackup::updateOrCreate(
            ['azure_path' => $blobPath],
            [
                'source' => $source,
                'relative_path' => $rel,
                'filename' => $filename,
                'size' => $size,
                'sha256' => $sha,
                'disk' => $disk,
                'status' => $status,
                'error' => $error,
                'received_at' => Carbon::createFromTimestamp($mtime),
                'uploaded_at' => $uploaded ? now() : null,
            ]
        );
    }

    /**
     * Surface upload failures as a single open NocEvent so the existing
     * notification rules fire, and resolve it once a clean sweep succeeds.
     *
     * @param  array<int, string>  $failures
     */
    private function syncFailureEvent(int $failed, array $failures): void
    {
        try {
            if ($failed > 0) {
                $message = "{$failed} SFTP backup file(s) failed to upload to Azure in the last sweep:\n"
                    .implode("\n", array_slice($failures, 0, 20));

                // noc_events.module is an enum (network|identity|voip|assets);
                // 'network' is the right bucket for device-backup failures, and
                // the title carries the real context.
                $event = NocEvent::firstOrCreate(
                    ['source_type' => 'sftp_backup_failed', 'source_id' => 0, 'status' => 'open'],
                    [
                        'module' => 'network',
                        'severity' => 'warning',
                        'title' => 'SFTP backup upload failures',
                        'message' => $message,
                        'first_seen' => now(),
                        'last_seen' => now(),
                    ]
                );
                $event->update(['last_seen' => now(), 'message' => $message]);
            } else {
                NocEvent::where('source_type', 'sftp_backup_failed')
                    ->where('status', 'open')
                    ->update(['status' => 'resolved', 'resolved_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('sftp-backups: could not sync NocEvent: '.$e->getMessage());
        }
    }
}
