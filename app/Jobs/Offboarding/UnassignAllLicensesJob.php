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

class UnassignAllLicensesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 180;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('offboarding');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $ow = OffboardingWorkflow::with(['employee', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow) return;

        $azureId = $ow->employee?->azure_id;
        if (! $azureId) return;

        $graph = new GraphService();
        $licenses = $graph->getUserLicenses($azureId);

        $removed = 0;
        foreach ($licenses as $lic) {
            $sku = $lic['skuId'] ?? null;
            if (! $sku) continue;
            try {
                $graph->removeLicense($azureId, $sku);
                $removed++;
                sleep(2); // ConcurrencyViolation guard (mirrors provisioning pattern)
            } catch (\Throwable $e) {
                $engine->logEvent($ow->workflow, 'warning',
                    "License remove failed ({$sku}): {$e->getMessage()}");
            }
        }

        $engine->logEvent($ow->workflow, 'success',
            "Unassigned {$removed} license(s) from user.");
    }
}
