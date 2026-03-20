<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendWorkflowEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int   $workflowId,
        public readonly array $params = []
    ) {}

    public function handle(): void
    {
        $to      = $this->params['to'] ?? null;
        $subject = $this->params['subject'] ?? 'Workflow Notification';
        $body    = $this->params['body'] ?? '';

        if (! $to) {
            Log::warning("[SendWorkflowEmailJob] No recipient for workflow #{$this->workflowId}");
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (\Throwable $e) {
            Log::error("[SendWorkflowEmailJob] Failed: {$e->getMessage()}");
        }
    }
}
