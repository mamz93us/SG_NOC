<?php

namespace App\Jobs;

use App\Models\WorkflowRequest;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ContinueWorkflowAfterWaitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int    $workflowId,
        public readonly string $waitNodeId
    ) {}

    public function handle(WorkflowEngine $engine): void
    {
        $workflow = WorkflowRequest::find($this->workflowId);

        if (! $workflow) {
            Log::warning("[ContinueWorkflowAfterWaitJob] Workflow #{$this->workflowId} not found.");
            return;
        }

        // Only continue if still waiting at this exact node
        if ($workflow->current_node_id !== $this->waitNodeId) {
            Log::info("[ContinueWorkflowAfterWaitJob] Workflow #{$this->workflowId} is no longer at wait node {$this->waitNodeId}, skipping.");
            return;
        }

        if (in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled'])) {
            return;
        }

        $engine->logEvent($workflow, 'info', "Wait period elapsed, resuming workflow.");
        $engine->advanceNode($workflow);
    }
}
