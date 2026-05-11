<?php

namespace App\Jobs\Offboarding;

use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AddToOffboardingGroupJob implements ShouldQueue
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

        $settings = Setting::get();
        $groupId  = $settings->offboarding_group_id;

        if (! $groupId) {
            $engine->logEvent($ow->workflow, 'warning',
                'Offboarding group ID not configured — skipping group add.');
            return;
        }

        $azureId = $ow->employee?->azure_id;
        if (! $azureId) {
            $engine->logEvent($ow->workflow, 'warning',
                'Employee has no azure_id — cannot add to offboarding group.');
            return;
        }

        try {
            (new GraphService())->addUserToGroup($azureId, $groupId);
            $engine->logEvent($ow->workflow, 'success',
                "Added user to offboarding Azure group ({$groupId}).");
        } catch (\Throwable $e) {
            // 409 = already a member — not an error.
            if (str_contains($e->getMessage(), '409')) {
                $engine->logEvent($ow->workflow, 'info',
                    "User already in offboarding group ({$groupId}).");
                return;
            }
            throw $e;
        }
    }
}
