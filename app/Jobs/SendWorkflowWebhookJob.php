<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendWorkflowWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int    $workflowId,
        public readonly string $url,
        public readonly array  $payload = []
    ) {}

    public function handle(): void
    {
        try {
            Http::timeout(10)->post($this->url, array_merge($this->payload, [
                'workflow_id' => $this->workflowId,
                'source'      => config('app.name'),
            ]));
        } catch (\Throwable $e) {
            Log::error("[SendWorkflowWebhookJob] Webhook to {$this->url} failed: {$e->getMessage()}");
        }
    }
}
