<?php

namespace App\Jobs\Avepoint;

use App\Models\AvepointBackup;
use App\Models\Setting;
use App\Services\AvePoint\AvePointApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Streams an AvePoint export directly into Azure Blob (azure_avepoint disk)
 * with inline SHA-256 — no local file. Generates the download token and
 * fires the requester-notification job.
 */
class StreamAvepointToAzureBlobJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 0;

    public function __construct(private int $backupId)
    {
        $this->onQueue('avepoint');
    }

    public function handle(AvePointApiService $avepoint): void
    {
        $backup = AvepointBackup::find($this->backupId);
        if (! $backup || ! $backup->avepoint_job_id) return;
        if ($backup->status === 'completed') return;

        $ext = $backup->type === 'mailbox' ? 'pst' : 'zip';
        $sub = preg_replace('/[^A-Za-z0-9._-]+/', '-', $backup->subject_upn);
        $blobPath = "{$sub}/{$backup->type}-" . date('Ymd-His') . ".{$ext}";

        $tmpStream = fopen('php://temp/maxmemory:' . (8 * 1024 * 1024), 'w+b');
        $hashCtx   = hash_init('sha256');
        $bytes     = 0;

        try {
            $avepoint->downloadExport($backup->avepoint_job_id, function (string $chunk) use ($tmpStream, &$hashCtx, &$bytes) {
                fwrite($tmpStream, $chunk);
                hash_update($hashCtx, $chunk);
                $bytes += strlen($chunk);
            });

            rewind($tmpStream);
            Storage::disk('azure_avepoint')->writeStream($blobPath, $tmpStream);

            $expiryDays = (int) (Setting::get()->offboarding_download_expiry_days ?? 5);

            $backup->update([
                'file_path'           => $blobPath,
                'file_size'           => $bytes,
                'file_sha256'         => hash_final($hashCtx),
                'status'              => 'completed',
                'download_token'      => Str::random(64),
                'download_expires_at' => now()->addDays($expiryDays),
            ]);

            SendAvepointBackupReadyJob::dispatch($backup->id)->onQueue('emails');
        } catch (\Throwable $e) {
            try {
                Storage::disk('azure_avepoint')->delete($blobPath);
            } catch (\Throwable) {
                // ignore
            }
            $backup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            throw $e;
        } finally {
            if (is_resource($tmpStream)) {
                fclose($tmpStream);
            }
        }
    }
}
