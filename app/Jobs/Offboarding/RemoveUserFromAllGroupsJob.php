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

/**
 * Removes the user from every Azure group they're a member of, EXCEPT the
 * offboarding group itself (which we just added them to a moment ago).
 */
class RemoveUserFromAllGroupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('offboarding');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $ow = OffboardingWorkflow::with(['employee', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow) return;

        $azureId = $ow->employee?->azure_id;
        if (! $azureId) {
            $engine->logEvent($ow->workflow, 'warning',
                'Employee has no azure_id — cannot list/remove groups.');
            return;
        }

        $settings = Setting::get();
        $skipGroupId = $settings->offboarding_group_id;

        $graph = new GraphService();
        // Get ALL groups (including security) — the user's spec is to remove from all.
        try {
            $groups = $graph->listUserGroups($azureId, excludeSecurity: false);
        } catch (\Throwable $e) {
            $engine->logEvent($ow->workflow, 'warning',
                "Failed to list groups: {$e->getMessage()}");
            return;
        }

        $removed = 0;
        $skipped = 0;
        foreach ($groups as $g) {
            $gid  = $g['id'] ?? null;
            $name = $g['displayName'] ?? $gid;
            if (! $gid) continue;
            if ($gid === $skipGroupId) {
                $skipped++;
                continue;
            }
            try {
                $graph->removeUserFromGroup($azureId, $gid);
                $removed++;
                // small pause to avoid Graph throttling
                usleep(300_000);
            } catch (\Throwable $e) {
                $engine->logEvent($ow->workflow, 'warning',
                    "Group remove failed for {$name}: {$e->getMessage()}");
            }
        }

        $engine->logEvent($ow->workflow, 'success',
            "Removed user from {$removed} group(s) ({$skipped} preserved as offboarding gate).");
    }
}
