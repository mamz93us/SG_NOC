<?php

namespace App\Console\Commands;

use App\Models\DownloadFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Streams Download Center URL entries into Azure Blob. Direct uploads land
 * synchronously in the controller; remote URLs are queued as `pending` rows and
 * fetched here so a large artifact can't time out the web request — the same
 * scheduler-as-worker pattern as sftp-backups:sweep (prod has no queue worker).
 *
 * Each row goes pending → fetching → stored|failed, and the index page polls the
 * status endpoint so the badge updates live.
 */
class FetchDownloadCenterUrls extends Command
{
    protected $signature = 'downloads:fetch-remote
                            {--id= : Only fetch this download_files row}
                            {--max=5 : Max rows to fetch this run}';

    protected $description = 'Fetch pending Download Center URLs and stream them to Azure Blob.';

    /** Hard cap on a fetched file (4 GB) to protect the VM temp disk. */
    private const MAX_BYTES = 4 * 1024 * 1024 * 1024;

    public function handle(): int
    {
        @set_time_limit(0);

        $query = DownloadFile::where('source', DownloadFile::SOURCE_URL)
            ->where('status', DownloadFile::STATUS_PENDING)
            ->orderBy('id');

        if ($id = $this->option('id')) {
            $query->where('id', (int) $id);
        }

        $rows = $query->limit(max(1, (int) $this->option('max')))->get();

        if ($rows->isEmpty()) {
            $this->info('No pending URL fetches.');

            return self::SUCCESS;
        }

        // Bail cleanly if the disk isn't configured yet (azure driver throws on
        // missing account/key) — leaves rows pending for the next tick.
        try {
            Storage::disk(DownloadFile::DISK)->exists('.downloads-healthcheck');
        } catch (\Throwable $e) {
            $this->warn('Download disk not ready: '.$e->getMessage());

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->fetchOne($row);
        }

        return self::SUCCESS;
    }

    private function fetchOne(DownloadFile $row): void
    {
        $row->update(['status' => DownloadFile::STATUS_FETCHING, 'error' => null]);

        $tmp = tempnam(sys_get_temp_dir(), 'dlc_');
        if ($tmp === false) {
            $this->markFailed($row, 'Could not allocate a temp file.');

            return;
        }

        try {
            $response = Http::timeout(900)
                ->withOptions(['stream' => false, 'sink' => $tmp])
                ->get($row->source_url);

            if (! $response->successful()) {
                throw new \RuntimeException("Remote returned HTTP {$response->status()}.");
            }

            $size = filesize($tmp) ?: 0;
            if ($size <= 0) {
                throw new \RuntimeException('Fetched file is empty.');
            }
            if ($size > self::MAX_BYTES) {
                throw new \RuntimeException('Fetched file exceeds the size limit.');
            }

            $blobPath = $row->id.'/'.$row->original_filename;

            $stream = fopen($tmp, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('Could not read the fetched file.');
            }
            try {
                $ok = Storage::disk(DownloadFile::DISK)->writeStream($blobPath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            if ($ok === false) {
                throw new \RuntimeException('writeStream returned false.');
            }

            $row->update([
                'azure_path' => $blobPath,
                'size' => $size,
                'mime' => $response->header('Content-Type') ?: null,
                'sha256' => @hash_file('sha256', $tmp) ?: null,
                'status' => DownloadFile::STATUS_STORED,
                'error' => null,
            ]);

            $this->info("Fetched #{$row->id} ({$size} bytes) → [".DownloadFile::DISK."] {$blobPath}");
        } catch (\Throwable $e) {
            $this->markFailed($row, $e->getMessage());
        } finally {
            @unlink($tmp);
        }
    }

    private function markFailed(DownloadFile $row, string $message): void
    {
        $row->update(['status' => DownloadFile::STATUS_FAILED, 'error' => $message]);
        Log::warning("downloads:fetch-remote failed for #{$row->id}: {$message}");
        $this->error("FAILED #{$row->id}: {$message}");
    }
}
