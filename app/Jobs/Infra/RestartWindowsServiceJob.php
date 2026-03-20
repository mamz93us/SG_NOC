<?php

namespace App\Jobs\Infra;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RestartWindowsServiceJob implements ShouldQueue
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
        Log::info("[RestartWindowsServiceJob] workflow={$this->workflowId} params=" . json_encode($this->params));

        // TODO: implement Restart Windows Service
        // Access parameters via: $this->params['key']
    }
}
