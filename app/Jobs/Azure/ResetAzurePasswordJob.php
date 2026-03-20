<?php

namespace App\Jobs\Azure;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResetAzurePasswordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    public function __construct(
        public readonly int   $workflowId,
        public readonly array $params = []
    ) {}

    public function handle(): void
    {
        Log::info("[ResetAzurePasswordJob] workflow={$this->workflowId} params=" . json_encode($this->params));

        // TODO: implement Reset Azure Password
        // Access parameters via: $this->params['key']
    }
}
