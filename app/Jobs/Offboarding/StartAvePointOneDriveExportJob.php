<?php

namespace App\Jobs\Offboarding;

use App\Models\OffboardingBackup;
use App\Models\OffboardingWorkflow;
use App\Services\AvePoint\AvePointApiService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class StartAvePointOneDriveExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('avepoint');
    }

    public function handle(WorkflowEngine $engine, AvePointApiService $avepoint): void
    {
        $ow = OffboardingWorkflow::with(['employee', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow || ! $ow->employee) return;

        if ($ow->backups()->where('type', 'onedrive')->exists()) {
            return;
        }

        $backup = OffboardingBackup::create([
            'offboarding_workflow_id' => $ow->id,
            'type'                    => 'onedrive',
            'source'                  => $avepoint->hasExportEndpoints() ? 'avepoint' : 'manual_upload',
            'status'                  => 'pending',
        ]);

        try {
            $recent = $avepoint->findRecentBackupJob($ow->employee->email, 3, 48);  // 3 = OneDrive
            if ($recent) {
                $engine->logEvent($ow->workflow, 'info',
                    'AvePoint OneDrive backup verified: ' . ($recent['id'] ?? 'unknown'));
            }
        } catch (\Throwable) {
            // monitoring is non-blocking
        }

        try {
            $result = $avepoint->requestOneDriveExport($ow->employee->email);

            $backup->update([
                'avepoint_job_id' => $result['job_id'] ?? null,
                'status'          => $result['mode'] === 'live' ? 'running' : 'manual_upload_required',
            ]);

            if ($result['mode'] === 'live') {
                $engine->logEvent($ow->workflow, 'success',
                    "AvePoint OneDrive export job requested: {$result['job_id']}");
                PollAvePointExportJob::dispatch($backup->id)
                    ->delay(now()->addMinutes(2))
                    ->onQueue('avepoint');
            } else {
                $engine->logEvent($ow->workflow, 'info',
                    'AvePoint export endpoint not configured — OneDrive backup falls back to manual upload.');
                \App\Models\ItTask::create([
                    'title'        => "Manual upload: export {$ow->employee->name}'s OneDrive from AvePoint UI",
                    'description'  => "AvePoint Graph API trigger/download endpoints are not configured.\n"
                                    . "Export OneDrive backup from AvePoint UI and upload via NOC: "
                                    . route('admin.offboarding.show', $ow),
                    'type'         => 'offboarding_avepoint_manual',
                    'priority'     => 'high',
                    'status'       => 'open',
                    'related_type' => \App\Models\Employee::class,
                    'related_id'   => $ow->employee->id,
                    'due_date'     => $ow->expected_last_day,
                ]);
            }
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $engine->logEvent($ow->workflow, 'error',
                "AvePoint OneDrive export request failed: {$e->getMessage()}");
            throw $e;
        }
    }
}
