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

class SetMailboxForwardingJob implements ShouldQueue
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

        if ($ow->email_action !== 'forward') return;
        if (empty($ow->forward_emails)) {
            $engine->logEvent($ow->workflow, 'warning',
                'Forward chosen but no targets — skipping forwarding rule.');
            return;
        }

        $upn = $ow->employee?->email;
        if (! $upn) {
            $engine->logEvent($ow->workflow, 'warning',
                'No UPN — cannot set mailbox forwarding.');
            return;
        }

        try {
            $ruleId = (new GraphService())->setMailboxForwarding($upn, $ow->forward_emails);
            $ow->update(['forward_rule_id' => $ruleId]);
            $engine->logEvent($ow->workflow, 'success',
                'Mailbox forwarding rule created (' . count($ow->forward_emails) . ' recipient(s), until '
                . $ow->forward_until?->format('Y-m-d') . '). Rule id: ' . $ruleId);
        } catch (\Throwable $e) {
            $engine->logEvent($ow->workflow, 'error',
                "Failed to set mailbox forwarding: {$e->getMessage()}");
            throw $e;
        }
    }
}
