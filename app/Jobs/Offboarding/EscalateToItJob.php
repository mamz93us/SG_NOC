<?php

namespace App\Jobs\Offboarding;

use App\Mail\OffboardingEscalationMail;
use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Fired when the manager grace window expires without a response.
 * Notifies the IT escalation address and (optionally) HR; the offboarding
 * now requires manual handling via the admin UI.
 */
class EscalateToItJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $ow = OffboardingWorkflow::with(['workflow', 'employee', 'token'])->find($this->offboardingWorkflowId);
        if (! $ow) return;

        $settings = Setting::get();
        $itEmail  = $settings->offboarding_it_escalation_email;
        if (! $itEmail) return;

        Mail::to($itEmail)->send(new OffboardingEscalationMail($ow));
    }
}
