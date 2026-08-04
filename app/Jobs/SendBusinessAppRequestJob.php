<?php

namespace App\Jobs;

use App\Mail\BusinessAppRequestMail;
use App\Models\BusinessApp;
use App\Models\EmailLog;
use App\Models\MailSender;
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

/**
 * Asks the team that runs a business system (Salesforce, Oracle, …) to create
 * an account for a new starter.
 *
 * NOC cannot create these accounts, so this email IS the handoff — if it does
 * not arrive, nothing happens. That is why the app is hidden from the manager's
 * form unless recipients are configured.
 *
 * Carries the employee's work details only. No password: NOC has no credentials
 * for these systems and the recipient sets their own.
 */
class SendBusinessAppRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $workflowId,
        public int $businessAppId,
    ) {}

    public function handle(SmtpConfigService $smtp): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        $app = BusinessApp::find($this->businessAppId);

        if (! $workflow || ! $app) {
            Log::warning("SendBusinessAppRequestJob: workflow #{$this->workflowId} or app #{$this->businessAppId} not found.");

            return;
        }

        $recipients = $app->requestRecipients();

        if (empty($recipients)) {
            $this->logToWorkflow($workflow->id, 'warning',
                "{$app->name} account was requested but no recipient address is configured — nobody has been told. Set one in Admin → Business App Accounts.");

            return;
        }

        $smtp->loadFromSettings();

        $payload = $workflow->payload ?? [];
        $displayName = $payload['display_name'] ?? 'New employee';
        $subject = "Account request: {$app->name} for {$displayName}";

        foreach ($recipients as $email) {
            $status = 'sent';
            $error = null;

            try {
                Mail::to($email)->send(
                    MailSender::apply(
                        new BusinessAppRequestMail($workflow, $app),
                        MailSender::ONBOARDING
                    )
                );
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                Log::error("SendBusinessAppRequestJob: send failed to {$email} for workflow #{$workflow->id}: {$error}");
            }

            try {
                EmailLog::create([
                    'to_email' => $email,
                    'to_name' => null,
                    'subject' => $subject,
                    'notification_type' => 'business_app_request_'.$app->key,
                    'notification_id' => null,
                    'status' => $status,
                    'error_message' => $error,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable) {
                // audit row failure must not break the job
            }
        }

        $this->logToWorkflow($workflow->id, 'success',
            "{$app->name} account request emailed to ".implode(', ', $recipients).'.');
    }

    private function logToWorkflow(int $workflowId, string $level, string $message): void
    {
        try {
            WorkflowLog::create([
                'workflow_id' => $workflowId,
                'level' => $level,
                'message' => $message,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never let logging break the job
        }
    }
}
