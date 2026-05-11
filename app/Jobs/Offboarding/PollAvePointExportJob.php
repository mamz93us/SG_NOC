<?php

namespace App\Jobs\Offboarding;

use App\Models\OffboardingBackup;
use App\Services\AvePoint\AvePointApiService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Polls an AvePoint export job until it reaches a terminal state, then either
 * dispatches the streaming-upload job or marks the row failed. Re-dispatches
 * itself with a 2-minute delay while running (caps at 720 retries ≈ 24h).
 */
class PollAvePointExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;       // we handle retries via re-dispatch ourselves
    public int $timeout = 60;

    public function __construct(
        private int $backupId,
        private int $attempt = 1,
    ) {
        $this->onQueue('avepoint');
    }

    public function handle(WorkflowEngine $engine, AvePointApiService $avepoint): void
    {
        $backup = OffboardingBackup::with('offboardingWorkflow.workflow')->find($this->backupId);
        if (! $backup || ! $backup->avepoint_job_id) return;

        try {
            $status = $avepoint->getExportStatus($backup->avepoint_job_id);
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $engine->logEvent($backup->offboardingWorkflow?->workflow,
                'error', "AvePoint status poll failed: {$e->getMessage()}");
            return;
        }

        switch ($status['status']) {
            case 'completed':
                $backup->update(['status' => 'uploading']);
                StreamAvePointExportToAzureBlobJob::dispatch($backup->id)->onQueue('avepoint');
                $engine->logEvent($backup->offboardingWorkflow?->workflow,
                    'success', "AvePoint export complete — streaming to Azure Blob ({$backup->type}).");
                return;

            case 'failed':
                $backup->update([
                    'status'        => 'failed',
                    'error_message' => 'AvePoint reported job failed.',
                ]);
                $engine->logEvent($backup->offboardingWorkflow?->workflow,
                    'error', "AvePoint export job failed ({$backup->type}).");
                return;

            case 'manual_upload_required':
                // endpoints disappeared mid-flight — fall back
                $backup->update(['status' => 'manual_upload_required', 'source' => 'manual_upload']);
                return;

            default:
                // pending / running / unknown — keep polling
                if ($this->attempt >= 720) {     // ~24h at 2 min intervals
                    $backup->update([
                        'status'        => 'failed',
                        'error_message' => 'AvePoint poll timed out after 24h.',
                    ]);
                    $engine->logEvent($backup->offboardingWorkflow?->workflow,
                        'warning', "AvePoint export polling timed out for {$backup->type}.");
                    return;
                }
                self::dispatch($backup->id, $this->attempt + 1)
                    ->delay(now()->addMinutes(2))
                    ->onQueue('avepoint');
        }
    }
}
