<?php

namespace App\Jobs;

use App\Mail\EmployeeWelcomeMail;
use App\Models\EmailLog;
use App\Models\Setting;
use App\Models\WorkflowLog;
use App\Models\WorkflowRequest;
use App\Services\SmtpConfigService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendEmployeeWelcomeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public int $workflowId) {}

    public function handle(SmtpConfigService $smtp): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        if (! $workflow) {
            Log::warning("SendEmployeeWelcomeJob: workflow #{$this->workflowId} not found.");
            return;
        }

        $payload = $workflow->payload ?? [];
        $toEmail = $payload['upn'] ?? null;
        $toName  = $payload['display_name'] ?? null;

        if (! $toEmail) {
            Log::info("SendEmployeeWelcomeJob: no UPN on workflow #{$workflow->id} — skipping.");
            $this->logToWorkflow($workflow->id, 'warning',
                'Welcome email skipped — UPN not available on workflow payload.');
            return;
        }

        $smtp->loadFromSettings();

        $setting  = Setting::first();
        $fromAddr = $setting?->smtp_from_address ?: config('mail.from.address');
        $fromName = $setting?->smtp_from_name    ?: 'SG NOC';

        $subject      = "Welcome to Samir Group, " . ($toName ?? 'New Employee');
        $status       = 'sent';
        $errorMessage = null;

        try {
            Mail::to($toEmail, $toName)
                ->send(
                    (new EmployeeWelcomeMail($workflow))
                        ->from($fromAddr, $fromName)
                );
            Log::info("SendEmployeeWelcomeJob: welcome sent to {$toEmail} for workflow #{$workflow->id}");
            $this->logToWorkflow($workflow->id, 'success',
                "Welcome email sent to {$toEmail}.");
        } catch (\Throwable $e) {
            $status       = 'failed';
            $errorMessage = $e->getMessage();
            Log::error("SendEmployeeWelcomeJob: send failed to {$toEmail} for workflow #{$workflow->id}: {$errorMessage}");
            $this->logToWorkflow($workflow->id, 'warning',
                "Welcome email send failed for {$toEmail}: {$errorMessage}");
        }

        try {
            EmailLog::create([
                'to_email'          => $toEmail,
                'to_name'           => $toName,
                'subject'           => $subject,
                'notification_type' => 'employee_welcome',
                'notification_id'   => null,
                'status'            => $status,
                'error_message'     => $errorMessage,
                'sent_at'           => now(),
            ]);
        } catch (\Throwable) {
            // audit row failure must not break the job
        }

        if ($status === 'failed') {
            throw new \RuntimeException("Welcome email failed: {$errorMessage}");
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("SendEmployeeWelcomeJob permanently failed for workflow #{$this->workflowId}: "
            . $e->getMessage());
        $this->logToWorkflow($this->workflowId, 'error',
            "Welcome email permanently failed: {$e->getMessage()}");
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
