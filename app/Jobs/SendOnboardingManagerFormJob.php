<?php

namespace App\Jobs;

use App\Mail\OnboardingManagerFormMail;
use App\Models\OnboardingManagerToken;
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
            return;
        }

        // Derive manager name from email (before the @)
        $managerName = ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');

        // Create (or reuse existing valid) token
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

        Mail::to($managerEmail)->send(new OnboardingManagerFormMail($workflow, $token));

        Log::info("SendOnboardingManagerFormJob: form email sent to {$managerEmail} for workflow #{$workflow->id}");
    }
}
