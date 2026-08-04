<?php

namespace App\Jobs;

use App\Mail\BusinessAppAccountMail;
use App\Models\BusinessApp;
use App\Models\EmailLog;
use App\Models\Employee;
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
 * Sends the create-account or disable-account request for a business system.
 *
 * Used by onboarding (activate) and by the add/remove buttons on an employee's
 * profile (either). Workflow context is optional — a mid-career access change
 * has no onboarding request behind it.
 */
class SendBusinessAppAccountMailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public int $employeeId,
        public int $businessAppId,
        public string $action = BusinessAppAccountMail::ACTIVATE,
        public ?int $workflowId = null,
        public ?string $reason = null,
    ) {}

    public function handle(SmtpConfigService $smtp): void
    {
        $employee = Employee::with(['branch', 'department', 'manager'])->find($this->employeeId);
        $app = BusinessApp::find($this->businessAppId);

        if (! $employee || ! $app) {
            Log::warning("SendBusinessAppAccountMailJob: employee #{$this->employeeId} or app #{$this->businessAppId} not found.");

            return;
        }

        $recipients = $app->requestRecipients();

        if (empty($recipients)) {
            $this->log("{$app->name} {$this->action} request could not be sent — no recipient address is configured. Set one in Admin → Business App Accounts.", 'warning');

            return;
        }

        $smtp->loadFromSettings();

        $workflow = $this->workflowId ? WorkflowRequest::find($this->workflowId) : null;
        $verb = $this->action === BusinessAppAccountMail::DEACTIVATE ? 'Disable' : 'Create';
        $subject = "{$verb} {$app->name} account: {$employee->name}";

        foreach ($recipients as $email) {
            $status = 'sent';
            $error = null;

            try {
                Mail::to($email)->send(
                    MailSender::apply(
                        new BusinessAppAccountMail($employee, $app, $this->action, $workflow, $this->reason),
                        MailSender::ONBOARDING
                    )
                );
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                Log::error("SendBusinessAppAccountMailJob: send failed to {$email}: {$error}");
            }

            try {
                EmailLog::create([
                    'to_email' => $email,
                    'to_name' => null,
                    'subject' => $subject,
                    'notification_type' => "business_app_{$this->action}_{$app->key}",
                    'notification_id' => null,
                    'status' => $status,
                    'error_message' => $error,
                    'sent_at' => now(),
                ]);
            } catch (\Throwable) {
                // audit row failure must not break the job
            }
        }

        $this->log("{$app->name} account {$this->action} request emailed to ".implode(', ', $recipients).'.', 'success');
    }

    /**
     * Log to the workflow timeline when there is one; otherwise the Laravel log
     * is the only trail (profile-driven changes have no workflow).
     */
    private function log(string $message, string $level): void
    {
        if (! $this->workflowId) {
            Log::info("[BusinessApp] {$message}");

            return;
        }

        try {
            WorkflowLog::create([
                'workflow_id' => $this->workflowId,
                'level' => $level,
                'message' => $message,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // never let logging break the job
        }
    }
}
