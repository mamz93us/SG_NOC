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

class StartAvePointMailboxExportJob implements ShouldQueue
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

        // Idempotency: skip if a mailbox backup row already exists
        if ($ow->backups()->where('type', 'mailbox')->exists()) {
            return;
        }

        $backup = OffboardingBackup::create([
            'offboarding_workflow_id' => $ow->id,
            'type'                    => 'mailbox',
            'source'                  => $avepoint->hasExportEndpoints() ? 'avepoint' : 'manual_upload',
            'status'                  => 'pending',
        ]);

        // Sanity check: confirm AvePoint has a recent successful backup of this user.
        try {
            $recent = $avepoint->findRecentBackupJob($ow->employee->email, 1, 48);  // 1 = Exchange
            if ($recent) {
                $engine->logEvent($ow->workflow, 'info',
                    'AvePoint mailbox backup verified: ' . ($recent['id'] ?? 'unknown'));
            } else {
                $engine->logEvent($ow->workflow, 'warning',
                    "No recent AvePoint mailbox backup found for {$ow->employee->email} in the last 48h.");
            }
        } catch (\Throwable $e) {
            $engine->logEvent($ow->workflow, 'warning',
                "AvePoint monitoring check failed: {$e->getMessage()}");
        }

        // Request export (or fall back to manual upload).
        try {
            $result = $avepoint->requestMailboxExport($ow->employee->email);

            $backup->update([
                'avepoint_job_id' => $result['job_id'] ?? null,
                'status'          => $result['mode'] === 'live' ? 'running' : 'manual_upload_required',
            ]);

            if ($result['mode'] === 'live') {
                $engine->logEvent($ow->workflow, 'success',
                    "AvePoint mailbox export job requested: {$result['job_id']}");
                PollAvePointExportJob::dispatch($backup->id)
                    ->delay(now()->addMinutes(2))
                    ->onQueue('avepoint');
            } else {
                $engine->logEvent($ow->workflow, 'info',
                    'AvePoint export endpoint not configured — mailbox backup falls back to manual upload.');
                $this->createManualUploadTask($ow, $backup, 'mailbox');
            }
        } catch (\Throwable $e) {
            $backup->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            $engine->logEvent($ow->workflow, 'error',
                "AvePoint mailbox export request failed: {$e->getMessage()}");
            throw $e;
        }
    }

    private function createManualUploadTask(OffboardingWorkflow $ow, OffboardingBackup $backup, string $type): void
    {
        \App\Models\ItTask::create([
            'title'        => "Manual upload: export {$ow->employee->name}'s {$type} from AvePoint UI",
            'description'  => "AvePoint Graph API trigger/download endpoints are not configured.\n"
                            . "1. Log into AvePoint Cloud Backup for M365.\n"
                            . "2. Locate the latest {$type} backup for {$ow->employee->email}.\n"
                            . "3. Export and download the archive.\n"
                            . "4. Upload via NOC: " . route('admin.offboarding.show', $ow) . " (look for the {$type} backup row).",
            'type'         => 'offboarding_avepoint_manual',
            'priority'     => 'high',
            'status'       => 'open',
            'related_type' => \App\Models\Employee::class,
            'related_id'   => $ow->employee->id,
            'due_date'     => $ow->expected_last_day,
            'assigned_to'  => $this->resolveItAssignee(),
        ]);
    }

    private function resolveItAssignee(): ?int
    {
        $settings = \App\Models\Setting::get();
        if ($email = $settings->offboarding_it_escalation_email) {
            $user = \App\Models\User::where('email', $email)->first();
            if ($user) return $user->id;
        }
        return \App\Models\User::whereIn('role', ['super_admin', 'admin'])->orderBy('id')->value('id');
    }
}
