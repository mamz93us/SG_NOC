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
 * Used when manager picks 'forward' for email — keeps the mailbox alive by
 * ensuring an Exchange-only SKU is assigned and removing every other SKU.
 */
class UnassignAllExceptExchangeOnlyJob implements ShouldQueue
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

        $settings    = Setting::get();
        $exchangeSku = $settings->offboarding_exchange_only_sku;
        if (! $exchangeSku) {
            $engine->logEvent($ow->workflow, 'error',
                'offboarding_exchange_only_sku not set in Settings — cannot downgrade for forwarding.');
            return;
        }

        $graph    = new GraphService();
        $licenses = $graph->getUserLicenses($azureId);
        $current  = collect($licenses)->pluck('skuId')->all();

        // Assign Exchange-only SKU if not already there.
        if (! in_array($exchangeSku, $current, true)) {
            try {
                $graph->assignLicense($azureId, $exchangeSku);
                $engine->logEvent($ow->workflow, 'success',
                    'Assigned Exchange-only SKU to keep mailbox/forwarding alive.');
                sleep(3);
            } catch (\Throwable $e) {
                $engine->logEvent($ow->workflow, 'error',
                    "Failed to assign Exchange-only SKU: {$e->getMessage()}");
                throw $e;
            }
        }

        // Remove every other SKU.
        $removed = 0;
        foreach ($current as $sku) {
            if ($sku === $exchangeSku) continue;
            try {
                $graph->removeLicense($azureId, $sku);
                $removed++;
                sleep(2);
            } catch (\Throwable $e) {
                $engine->logEvent($ow->workflow, 'warning',
                    "License remove failed ({$sku}): {$e->getMessage()}");
            }
        }

        $engine->logEvent($ow->workflow, 'success',
            "Forwarding mode active: kept Exchange-only SKU, removed {$removed} other license(s).");
    }
}
