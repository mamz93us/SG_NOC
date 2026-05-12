<?php

namespace App\Jobs\Avepoint;

use App\Models\AvepointBackup;
use App\Services\AvePoint\AvePointApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Polls AvePoint export job status every ~2 minutes (caps at 720 attempts ≈ 24h).
 * Completed → hand off to StreamAvepointToAzureBlobJob.
 * Failed → mark backup failed and stop.
 * Pending/running → re-dispatch with a 2-minute delay.
 */
class PollAvepointExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(
        private int $backupId,
        private int $attempt = 1,
    ) {
        $this->onQueue('avepoint');
    }

    public function handle(AvePointApiService $avepoint): void
    {
        $backup = AvepointBackup::find($this->backupId);
        if (! $backup || ! $backup->avepoint_job_id) return;
        if (in_array($backup->status, ['completed', 'failed', 'pruned'], true)) return;

        try {
            $status = $avepoint->getExportStatus($backup->avepoint_job_id);
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            return;
        }

        switch ($status['status']) {
            case 'completed':
                $backup->update(['status' => 'uploading']);
                StreamAvepointToAzureBlobJob::dispatch($backup->id)->onQueue('avepoint');
                return;

            case 'failed':
                $backup->update([
                    'status'        => 'failed',
                    'error_message' => 'AvePoint reported job failed.',
                ]);
                return;

            case 'manual_upload_required':
                $backup->update(['status' => 'manual_upload_required', 'source' => 'manual_upload']);
                return;

            default:
                if ($this->attempt >= 720) {
                    $backup->update([
                        'status'        => 'failed',
                        'error_message' => 'AvePoint poll timed out after 24h.',
                    ]);
                    return;
                }
                self::dispatch($backup->id, $this->attempt + 1)
                    ->delay(now()->addMinutes(2))
                    ->onQueue('avepoint');
        }
    }
}
