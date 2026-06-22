<?php

namespace App\Console\Commands;

use App\Models\DownloadFile;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Imports a file already sitting on the NOC's local disk (e.g. one you SCP'd up)
 * into the Download Center: streams it to the azure_downloads disk and registers
 * the catalogue row as `stored`. This is the path for files too big for the
 * browser upload cap (2 GB) — SCP the artifact onto the box, then run this.
 *
 * The bytes always live on Azure Blob, not the local disk, so the SCP'd copy is
 * just a staging file; delete it afterwards (or pass --delete-source).
 */
class ImportLocalDownloadFile extends Command
{
    protected $signature = 'downloads:import-local
                            {path : Absolute path to the local file to import}
                            {--title= : Display title (defaults to the filename)}
                            {--user= : Email of the user to attribute the upload to}
                            {--delete-source : Delete the local file after a successful import}';

    protected $description = 'Stream a local file into the Download Center (Azure Blob) and catalogue it.';

    public function handle(): int
    {
        @set_time_limit(0);

        $path = $this->argument('path');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error("File not found or not readable: {$path}");

            return self::FAILURE;
        }

        $size = filesize($path) ?: 0;
        if ($size <= 0) {
            $this->error('File is empty.');

            return self::FAILURE;
        }

        // Bail cleanly if the disk isn't configured (azure driver throws on
        // missing account/key) rather than half-creating a row.
        try {
            Storage::disk(DownloadFile::DISK)->exists('.downloads-healthcheck');
        } catch (\Throwable $e) {
            $this->error('Download disk not ready: '.$e->getMessage());

            return self::FAILURE;
        }

        $original = basename($path);
        $blobPath = Str::uuid().'/'.$original;

        $uploaderId = null;
        if ($email = $this->option('user')) {
            $uploaderId = User::where('email', $email)->value('id');
            if (! $uploaderId) {
                $this->warn("No user found for {$email}; importing with no uploader attribution.");
            }
        }

        $this->info('Streaming '.DownloadFile::formatBytes($size).' → ['.DownloadFile::DISK."] {$blobPath} …");

        $stream = fopen($path, 'rb');
        if ($stream === false) {
            $this->error('Could not open the local file for reading.');

            return self::FAILURE;
        }

        try {
            $ok = Storage::disk(DownloadFile::DISK)->writeStream($blobPath, $stream);
        } catch (\Throwable $e) {
            $this->error('Upload to Azure failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($ok === false) {
            $this->error('Upload to Azure failed (writeStream returned false).');

            return self::FAILURE;
        }

        $download = DownloadFile::create([
            'title' => $this->option('title') ?: pathinfo($original, PATHINFO_FILENAME),
            'original_filename' => $original,
            'disk' => DownloadFile::DISK,
            'azure_path' => $blobPath,
            'size' => $size,
            'mime' => mime_content_type($path) ?: null,
            'sha256' => @hash_file('sha256', $path) ?: null,
            'source' => DownloadFile::SOURCE_UPLOAD,
            'status' => DownloadFile::STATUS_STORED,
            'uploaded_by' => $uploaderId,
        ]);

        $this->info("Imported #{$download->id} “{$download->title}” ({$download->humanSize()}).");

        if ($this->option('delete-source')) {
            @unlink($path);
            $this->line("Deleted local source: {$path}");
        }

        return self::SUCCESS;
    }
}
