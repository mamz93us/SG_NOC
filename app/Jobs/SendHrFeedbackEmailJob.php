<?php

namespace App\Jobs;

use App\Mail\HrOnboardingCompleteMail;
use App\Mail\HrOffboardingCompleteMail;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched at the end of provisionUser() / deprovisionUserFull() to send
 * a completion confirmation back to the HR system contact.
 */
class SendHrFeedbackEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private int    $workflowId,
        private string $type      // 'onboarding' | 'offboarding'
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        if (! $workflow) return;

        $payload      = $workflow->payload ?? [];
        $hrReference  = $payload['hr_reference']  ?? null;
        $managerEmail = $payload['manager_email']  ?? null;
        $displayName  = $payload['display_name']   ?? ($payload['first_name'] . ' ' . $payload['last_name']);

        if ($this->type === 'onboarding') {
            // Send to manager and/or HR contact
            $recipients = array_filter(array_unique([
                $managerEmail,
                $payload['requester_email'] ?? null,
            ]));

            if (empty($recipients)) return;

            foreach ($recipients as $email) {
                \Illuminate\Support\Facades\Mail::to($email)
                    ->send(new HrOnboardingCompleteMail($workflow, $email));
            }

        } elseif ($this->type === 'offboarding') {
            $email = $managerEmail ?? ($payload['requester_email'] ?? null);
            if (! $email) return;

            \Illuminate\Support\Facades\Mail::to($email)
                ->send(new HrOffboardingCompleteMail($workflow, $email));
        }
    }
}
