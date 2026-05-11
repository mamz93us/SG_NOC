<?php

namespace App\Jobs\Offboarding;

use App\Models\OffboardingWorkflow;
use App\Services\Identity\GraphService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tears down the inbox forwarding rule created by SetMailboxForwardingJob.
 * Dispatched by OffboardingScheduler once forward_until is reached.
 */
class RemoveMailboxForwardingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('offboarding');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $ow = OffboardingWorkflow::with(['employee', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow) return;
        if (! $ow->forward_rule_id) return;

        $upn = $ow->employee?->email;
        if (! $upn) return;

        try {
            (new GraphService())->removeInboxRule($upn, $ow->forward_rule_id);
            $engine->logEvent($ow->workflow, 'success',
                'Mailbox forwarding rule removed (forward_until reached).');
            $ow->update(['forward_rule_id' => null]);
        } catch (\Throwable $e) {
            // 404 = already gone — fine.
            if (str_contains($e->getMessage(), '404')) {
                $ow->update(['forward_rule_id' => null]);
                return;
            }
            $engine->logEvent($ow->workflow, 'warning',
                "Failed to remove forwarding rule: {$e->getMessage()}");
            throw $e;
        }
    }
}
