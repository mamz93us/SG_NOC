<?php

namespace App\Jobs;

use App\Mail\HrOffboardingManagerRequestMail;
use App\Models\OffboardingToken;
use App\Models\WorkflowRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendOffboardingManagerRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private int    $workflowId,
        private string $tokenString
    ) {
        $this->onQueue('emails');
    }

    public function handle(): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);
        $token    = OffboardingToken::where('token', $this->tokenString)->first();

        if (! $workflow || ! $token) return;

        $email = $token->manager_email;
        if (! $email) return;

        Mail::to($email)->send(new HrOffboardingManagerRequestMail($workflow, $token));
    }
}
