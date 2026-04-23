<?php

namespace App\Jobs;

use App\Mail\OnboardingManagerFormMail;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowLog;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendOnboardingManagerFormJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public int $workflowId) {}

    public function handle(): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        if (! $workflow) {
            Log::warning("SendOnboardingManagerFormJob: workflow #{$this->workflowId} not found.");
            return;
        }

        $payload      = $workflow->payload ?? [];
        $managerEmail = $payload['manager_email'] ?? null;

        if (! $managerEmail) {
            Log::info("SendOnboardingManagerFormJob: no manager_email in workflow #{$this->workflowId} — skipping.");
            $this->logToWorkflow($workflow->id, 'warning',
                'Manager form email skipped — no manager_email on workflow payload.');
            return;
        }

        // Derive manager name from email (before the @)
        $managerName = ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');

        // Reuse existing token (created synchronously in HrOnboardingController)
        // or create a new one if this job is called from another path (e.g. admin panel).
        $token = OnboardingManagerToken::where('workflow_id', $workflow->id)
            ->whereNull('responded_at')
            ->latest()
            ->first();

        if (! $token || ! $token->isValid()) {
            $token = OnboardingManagerToken::generate($workflow->id, [
                'manager_email' => $managerEmail,
                'manager_name'  => $managerName,
            ]);
        }

        try {
            Mail::to($managerEmail)->send(new OnboardingManagerFormMail($workflow, $token));

            Log::info("SendOnboardingManagerFormJob: form email sent to {$managerEmail} for workflow #{$workflow->id}");
            $this->logToWorkflow($workflow->id, 'success',
                "Manager setup form email sent to {$managerEmail}.");
        } catch (\Throwable $e) {
            Log::error(
                "SendOnboardingManagerFormJob: failed to send to {$managerEmail} for workflow #{$workflow->id}: "
                . $e->getMessage()
            );
            $this->logToWorkflow($workflow->id, 'error',
                "Manager form email send failed for {$managerEmail}: {$e->getMessage()}");
            throw $e; // let queue retry
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("SendOnboardingManagerFormJob permanently failed for workflow #{$this->workflowId}: "
            . $e->getMessage());
        $this->logToWorkflow($this->workflowId, 'error',
            "Manager form email permanently failed after retries: {$e->getMessage()}");
    }

    private function logToWorkflow(int $workflowId, string $level, string $message): void
    {
        try {
            WorkflowLog::create([
                'workflow_id' => $workflowId,
                'level'       => $level,
                'message'     => $message,
                'created_at'  => now(),
            ]);
        } catch (\Throwable) {
            // never let logging break the job
        }
    }
}
