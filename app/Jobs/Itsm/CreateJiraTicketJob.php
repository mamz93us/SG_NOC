<?php

namespace App\Jobs\Itsm;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateJiraTicketJob implements ShouldQueue
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
        Log::info("[CreateJiraTicketJob] workflow={$this->workflowId} params=" . json_encode($this->params));

        // TODO: implement Create Jira Ticket
        // Access parameters via: $this->params['key']
    }
}
