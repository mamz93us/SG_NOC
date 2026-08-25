<?php

namespace App\Console\Commands;

use App\Models\PhoneFirmware;
use App\Services\Phone\FirmwarePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Pulls a Grandstream firmware package onto the NOC from a URL.
 *
 * Vendor packages run 60–150 MB, which is exactly the size that dies against
 * nginx's client_max_body_size or PHP's post_max_size on a browser upload — and
 * pushing one up from a branch laptop over the VPN is slow twice over. So the UI
 * queues a `pending` row and this command fetches it server-side, unpacks the
 * .bin images and files them into the library.
 *
 * Same scheduler-as-worker shape as downloads:fetch-remote — production runs no
 * queue worker.
 */
class FetchRemoteFirmware extends Command
{
    protected $signature = 'firmware:fetch-remote
                            {--id= : Only fetch this phone_firmwares row}
                            {--max=3 : Max rows to fetch this run}';

    protected $description = 'Fetch queued phone firmware URLs and unpack them into the firmware library.';

    public function handle(FirmwarePublisher $publisher): int
    {
        @set_time_limit(0);

        $query = PhoneFirmware::where('source', PhoneFirmware::SOURCE_URL)->orderBy('id');

        if ($id = $this->option('id')) {
            // A targeted run (the UI's Retry) re-fetches whatever state it is in.
            $query->where('id', (int) $id);
        } else {
            $query->where('status', PhoneFirmware::STATUS_PENDING);
        }

        $rows = $query->limit(max(1, (int) $this->option('max')))->get();

        if ($rows->isEmpty()) {
            $this->info('No pending firmware fetches.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->fetchOne($row, $publisher);
        }

        return self::SUCCESS;
    }

    private function fetchOne(PhoneFirmware $row, FirmwarePublisher $publisher): void
    {
        $row->update([
            'status' => PhoneFirmware::STATUS_FETCHING,
            'error' => null,
            'download_received_bytes' => 0,
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'fwdl_');
        if ($tmp === false) {
            $this->markFailed($row, 'Could not allocate a temp file.');

            return;
        }

        // Guzzle fires the progress callback constantly; write at most every 2s
        // with a raw update (no model events) so the index page's bar has
        // something to read without hammering MySQL.
        $lastWrite = 0.0;
        $progress = function ($downloadTotal, $downloadedBytes) use ($row, &$lastWrite) {
            if ($downloadedBytes <= 0) {
                return;
            }
            $now = microtime(true);
            if (($now - $lastWrite) < 2.0) {
                return;
            }
            $lastWrite = $now;
            DB::table('phone_firmwares')->where('id', $row->id)->update([
                'download_total_bytes' => $downloadTotal > 0 ? $downloadTotal : null,
                'download_received_bytes' => $downloadedBytes,
                'updated_at' => now(),
            ]);
        };

        $extracted = [];

        try {
            $response = Http::timeout((int) config('phone_firmware.fetch_timeout', 1800))
                ->withOptions(['sink' => $tmp, 'progress' => $progress])
                ->get($row->source_url);

            if (! $response->successful()) {
                throw new \RuntimeException("Remote returned HTTP {$response->status()}.");
            }

            $size = filesize($tmp) ?: 0;
            if ($size <= 0) {
                throw new \RuntimeException('Fetched file is empty.');
            }
            $maxBytes = (int) config('phone_firmware.max_fetch_bytes', 1024 * 1024 * 1024);
            if ($size > $maxBytes) {
                throw new \RuntimeException('Fetched file ('.round($size / 1048576).' MB) exceeds the limit.');
            }

            $extracted = $publisher->extractImages($tmp, $row->filename);

            // The first image takes over the placeholder row (keeping its notes,
            // covers list and uploader); any further images in a family package
            // become rows of their own.
            $rest = $extracted;
            $first = array_shift($rest);
            $this->applyImage($row, $first, $publisher);

            foreach ($rest as $image) {
                $this->spawnRow($row, $image, $publisher);
            }

            $this->info("Fetched #{$row->id} → {$row->model} {$row->version} ({$row->filename})");
        } catch (\Throwable $e) {
            $this->markFailed($row, $e->getMessage());
        } finally {
            // Clean up every extracted image, including the one that took over the
            // placeholder row — extractImages() hands back temp files we own. The
            // bare-.bin case returns $tmp itself, which the unlink below covers.
            foreach ($extracted as $image) {
                if ($image['path'] !== $tmp) {
                    @unlink($image['path']);
                    @rmdir(dirname($image['path']));
                }
            }
            @unlink($tmp);
        }
    }

    /**
     * @param  array{filename: string, path: string, size: int}  $image
     */
    private function applyImage(PhoneFirmware $row, array $image, FirmwarePublisher $publisher): void
    {
        $model = $publisher->guessModel($image['filename']) ?? 'UNKNOWN';

        // The placeholder held a synthetic PENDING-… model. If the real model at
        // this version is already in the library, the fetch was redundant.
        $clash = PhoneFirmware::where('model', $model)
            ->where('version', $row->version)
            ->where('id', '!=', $row->id)
            ->exists();

        if ($clash) {
            throw new \RuntimeException("{$model} {$row->version} is already in the library.");
        }

        $row->update([
            'model' => $model,
            'filename' => $image['filename'],
            'size' => $image['size'],
            'sha256' => @hash_file('sha256', $image['path']) ?: null,
            'status' => PhoneFirmware::STATUS_STORED,
            'download_received_bytes' => $image['size'],
            'error' => null,
        ]);

        $this->store($row, $image['path']);
    }

    /**
     * @param  array{filename: string, path: string, size: int}  $image
     */
    private function spawnRow(PhoneFirmware $parent, array $image, FirmwarePublisher $publisher): void
    {
        $model = $publisher->guessModel($image['filename']) ?? 'UNKNOWN';

        if (PhoneFirmware::where('model', $model)->where('version', $parent->version)->exists()) {
            $this->warn("Skipping {$model} {$parent->version} — already in the library.");

            return;
        }

        $row = PhoneFirmware::create([
            'model' => $model,
            'version' => $parent->version,
            'filename' => $image['filename'],
            'size' => $image['size'],
            'sha256' => @hash_file('sha256', $image['path']) ?: null,
            'source' => PhoneFirmware::SOURCE_URL,
            'source_url' => $parent->source_url,
            'status' => PhoneFirmware::STATUS_STORED,
            'notes' => $parent->notes,
            'uploaded_by' => $parent->uploaded_by,
        ]);

        $this->store($row, $image['path']);
        $this->info("  + {$model} {$row->version} ({$row->filename})");
    }

    private function store(PhoneFirmware $row, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not read the extracted image.');
        }
        try {
            Storage::disk(PhoneFirmware::DISK)->writeStream($row->libraryPath(), $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $row->update(['path' => $row->libraryPath()]);
    }

    private function markFailed(PhoneFirmware $row, string $message): void
    {
        $row->update(['status' => PhoneFirmware::STATUS_FAILED, 'error' => $message]);
        Log::warning("firmware:fetch-remote failed for #{$row->id}: {$message}");
        $this->error("FAILED #{$row->id}: {$message}");
    }
}
