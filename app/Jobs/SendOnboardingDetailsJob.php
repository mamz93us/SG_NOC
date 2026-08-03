<?php

namespace App\Jobs;

use App\Mail\OnboardingDetailsMail;
use App\Models\EmailLog;
use App\Models\MailSender;
use App\Models\User;
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
 * Tells HR and the reporting manager that a new starter is set up.
 *
 * Separate from SendItOnboardingSummaryJob on purpose: that one carries the
 * initial password and the UCM extension secret and goes to IT only. This one
 * carries contact details and nothing sensitive, so it can safely reach a
 * wider audience.
 */
class SendOnboardingDetailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $workflowId) {}

    public function handle(SmtpConfigService $smtp): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        if (! $workflow) {
            Log::warning("SendOnboardingDetailsJob: workflow #{$this->workflowId} not found.");

            return;
        }

        $payload = $workflow->payload ?? [];

        // Manager from the form; HR from whoever raised the request. Both are
        // optional — an admin-raised request may have neither.
        $recipients = [];

        if (! empty($payload['manager_email'])) {
            $recipients[strtolower($payload['manager_email'])] = [
                'name' => $payload['manager_name'] ?? null,
                'role' => 'manager',
            ];
        }

        $hrEmail = $payload['hr_submitter_id']
            ? User::find($payload['hr_submitter_id'])?->email
            : null;

        if ($hrEmail) {
            // If HR and the manager are the same person, one email is enough —
            // keyed by address, so the manager entry wins and they get the
            // manager wording.
            $recipients[strtolower($hrEmail)] ??= [
                'name' => $payload['hr_submitter_name'] ?? null,
                'role' => 'hr',
            ];
        }

        if (empty($recipients)) {
            Log::info("SendOnboardingDetailsJob: no HR/manager recipients for workflow #{$workflow->id} — skipping.");

            return;
        }

        $smtp->loadFromSettings();

        $displayName = $payload['display_name'] ?? 'New employee';

        foreach ($recipients as $email => $meta) {
            $status = 'sent';
            $error = null;

            try {
                Mail::to($email, $meta['name'])->send(
                    MailSender::apply(
                        new OnboardingDetailsMail($workflow, $meta['role']),
                        MailSender::ONBOARDING
                    )
                );
            } catch (\Throwable $e) {
                $status = 'failed';
                $error = $e->getMessage();
                Log::error("SendOnboardingDetailsJob: send failed to {$email} for workflow #{$workflow->id}: {$error}");
            }

            try {
                EmailLog::create([
                    'to_email' => $email,
                    'to_name' => $meta['name'],
                    'subject' => "{$displayName} is set up and ready",
                    'notification_type' => 'onboarding_details_'.$meta['role'],
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
            'Onboarding details email sent to '.implode(', ', array_keys($recipients)).' (no credentials included).');
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
