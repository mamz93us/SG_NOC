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
 * Lists the user's Intune managed devices and deletes each — equivalent to
 * "offboarding from Defender" since the Intune→Defender connector treats the
 * device-delete as an offboard signal.
 *
 * Idempotent: re-running after partial completion just skips devices already
 * deleted (Graph returns 404 which we swallow).
 */
class RemoveIntuneDevicesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('offboarding');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $ow = OffboardingWorkflow::with(['employee', 'workflow'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow) return;

        $upn = $ow->employee?->email;
        if (! $upn) {
            $engine->logEvent($ow->workflow, 'warning',
                'No UPN — cannot list Intune devices.');
            return;
        }

        $graph = new GraphService();

        try {
            $devices = $graph->listIntuneDevicesForUpn($upn);
        } catch (\Throwable $e) {
            $engine->logEvent($ow->workflow, 'warning',
                "Failed to list Intune devices for {$upn}: {$e->getMessage()}");
            return;
        }

        if (empty($devices)) {
            $engine->logEvent($ow->workflow, 'info',
                "No Intune managed devices found for {$upn}.");
            return;
        }

        $deleted = 0;
        $failed  = 0;
        foreach ($devices as $d) {
            $id   = $d['id'] ?? null;
            $name = $d['deviceName'] ?? $id;
            if (! $id) continue;

            try {
                $graph->deleteIntuneDevice($id);
                $deleted++;
                $engine->logEvent($ow->workflow, 'success',
                    "Intune device deleted: {$name}");
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), '404')) {
                    // Already gone — fine.
                    continue;
                }
                $failed++;
                $engine->logEvent($ow->workflow, 'warning',
                    "Intune delete failed for {$name}: {$e->getMessage()}");
            }
        }

        $engine->logEvent($ow->workflow, 'info',
            "Intune device cleanup complete — deleted={$deleted}, failed={$failed}.");
    }
}
