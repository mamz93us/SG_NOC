<?php

namespace App\Jobs;

use App\Mail\ItOnboardingSummaryMail;
use App\Models\EmailLog;
use App\Models\NotificationRule;
use App\Models\Setting;
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

class SendItOnboardingSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 60;

    public function __construct(public int $workflowId) {}

    public function handle(SmtpConfigService $smtp): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        if (! $workflow) {
            Log::warning("SendItOnboardingSummaryJob: workflow #{$this->workflowId} not found.");
            return;
        }

        // Resolve recipients via routing rules for `it_onboarding_summary`.
        // Fall back to all admins if no rule is configured so the info isn't
        // silently dropped.
        $recipients = $this->resolveRecipients();

        if ($recipients->isEmpty()) {
            Log::warning("SendItOnboardingSummaryJob: no recipients resolved for workflow #{$workflow->id}.");
            $this->logToWorkflow($workflow->id, 'warning',
                'IT onboarding summary email skipped — no recipients resolved via routing rules or admin fallback.');
            return;
        }

        // Queue worker runs in its own process — must load SMTP config from DB.
        $smtp->loadFromSettings();

        $setting  = Setting::first();
        $fromAddr = $setting?->smtp_from_address ?: config('mail.from.address');
        $fromName = $setting?->smtp_from_name    ?: 'SG NOC';

        $displayName = $workflow->payload['display_name'] ?? 'New Employee';
        $subject     = "IT Onboarding Summary: {$displayName}";

        foreach ($recipients as $user) {
            $toEmail = $user->email;
            $toName  = $user->name ?? '';
            if (! $toEmail) continue;

            $status       = 'sent';
            $errorMessage = null;

            try {
                Mail::to($toEmail, $toName)
                    ->send(
                        (new ItOnboardingSummaryMail($workflow, $toEmail))
                            ->from($fromAddr, $fromName)
                    );
                Log::info("SendItOnboardingSummaryJob: sent to {$toEmail} for workflow #{$workflow->id}");
            } catch (\Throwable $e) {
                $status       = 'failed';
                $errorMessage = $e->getMessage();
                Log::error("SendItOnboardingSummaryJob: send failed to {$toEmail} for workflow #{$workflow->id}: {$errorMessage}");
                $this->logToWorkflow($workflow->id, 'warning',
                    "IT onboarding summary send failed for {$toEmail}: {$errorMessage}");
            }

            try {
                EmailLog::create([
                    'to_email'          => $toEmail,
                    'to_name'           => $toName,
                    'subject'           => "[SG NOC] {$subject}",
                    'notification_type' => 'it_onboarding_summary',
                    'notification_id'   => null,
                    'status'            => $status,
                    'error_message'     => $errorMessage,
                    'sent_at'           => now(),
                ]);
            } catch (\Throwable) {
                // audit row failure must not break the job
            }
        }

        $this->logToWorkflow($workflow->id, 'success',
            "IT onboarding summary sent to {$recipients->count()} recipient(s).");
    }

    public function failed(\Throwable $e): void
    {
        Log::error("SendItOnboardingSummaryJob permanently failed for workflow #{$this->workflowId}: "
            . $e->getMessage());
        $this->logToWorkflow($this->workflowId, 'error',
            "IT onboarding summary permanently failed: {$e->getMessage()}");
    }

    /**
     * Match against routing rules first. Fall back to all admins if no
     * rule is configured so nothing is silently dropped.
     */
    private function resolveRecipients(): \Illuminate\Support\Collection
    {
        $rules = NotificationRule::active()->forEvent('it_onboarding_summary')->get();
        $users = collect();

        foreach ($rules as $rule) {
            if ($rule->recipient_type === 'role' && $rule->recipient_role) {
                $users = $users->merge(User::where('role', $rule->recipient_role)->get());
            } elseif ($rule->recipient_user_id && $rule->recipientUser) {
                $users->push($rule->recipientUser);
            }
        }

        if ($users->isEmpty()) {
            // No rule — fall back to admin roles
            $users = User::whereIn('role', ['super_admin', 'admin'])->get();
        }

        return $users->unique('id')->values();
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
