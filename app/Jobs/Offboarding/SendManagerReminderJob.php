<?php

namespace App\Jobs\Offboarding;

use App\Mail\HrOffboardingManagerRequestMail;
use App\Models\OffboardingWorkflow;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendManagerReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $ow = OffboardingWorkflow::with(['workflow', 'token'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow) return;

        $token = $ow->token;
        if (! $token || ! $token->isValid()) return;

        $email = $token->manager_email;
        if (! $email) return;

        Mail::to($email)->send(new HrOffboardingManagerRequestMail($ow->workflow, $token, reminder: true));
    }
}
