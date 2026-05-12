<?php

namespace App\Jobs\Avepoint;

use App\Models\AvepointBackup;
use App\Services\AvePoint\AvePointApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asks AvePoint for an export. Persists the job id and either schedules the
 * status poller (when endpoints are configured) or flips the row to
 * `manual_upload_required` for IT to fulfil via the admin upload form.
 */
class RequestAvepointExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(private int $backupId)
    {
        $this->onQueue('avepoint');
    }

    public function handle(AvePointApiService $avepoint): void
    {
        $backup = AvepointBackup::find($this->backupId);
        if (! $backup || $backup->status !== 'pending') return;

        try {
            $result = $backup->type === 'mailbox'
                ? $avepoint->requestMailboxExport($backup->subject_upn)
                : $avepoint->requestOneDriveExport($backup->subject_upn);

            $backup->update([
                'avepoint_job_id' => $result['job_id'] ?? null,
                'source'          => $result['mode'] === 'live' ? 'avepoint' : 'manual_upload',
                'status'          => $result['mode'] === 'live' ? 'running' : 'manual_upload_required',
            ]);

            if ($result['mode'] === 'live') {
                PollAvepointExportJob::dispatch($backup->id)
                    ->delay(now()->addMinutes(2))
                    ->onQueue('avepoint');
            }
        } catch (\Throwable $e) {
            Log::error('RequestAvepointExportJob failed', [
                'backup_id' => $backup->id,
                'error'     => $e->getMessage(),
            ]);
            $backup->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
