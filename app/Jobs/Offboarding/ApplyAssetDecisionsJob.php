<?php

namespace App\Jobs\Offboarding;

use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\OffboardingWorkflow;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyAssetDecisionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(private int $offboardingWorkflowId)
    {
        $this->onQueue('offboarding');
    }

    public function handle(WorkflowEngine $engine): void
    {
        $ow = OffboardingWorkflow::with(['employee', 'workflow', 'assetTarget'])->find($this->offboardingWorkflowId);
        if (! $ow || ! $ow->workflow || ! $ow->employee) return;

        $assets = $ow->employee->activeAssets()->with('device')->get();
        if ($assets->isEmpty()) {
            $engine->logEvent($ow->workflow, 'info', 'No active assets to move.');
            return;
        }

        $now      = now();
        $today    = $now->toDateString();
        $sourceId = $ow->employee->id;
        $sourceName = $ow->employee->name;

        if ($ow->asset_action === 'transfer' && $ow->asset_target_employee_id) {
            $target = $ow->assetTarget ?? Employee::find($ow->asset_target_employee_id);
            if (! $target) {
                $engine->logEvent($ow->workflow, 'warning',
                    'Asset transfer target employee not found — falling back to return-to-IT.');
                $this->returnAllToIt($assets, $ow, $today, $engine, $sourceName);
                return;
            }

            $transferred = 0;
            foreach ($assets as $a) {
                $a->update([
                    'returned_date' => $today,
                    'notes'         => "Transferred to {$target->name} via offboarding #{$ow->id}.",
                ]);
                EmployeeAsset::create([
                    'employee_id'    => $target->id,
                    'asset_id'       => $a->asset_id,
                    'assigned_date'  => $today,
                    'notes'          => "Transferred from {$sourceName} via offboarding #{$ow->id}.",
                ]);
                $transferred++;
            }

            $engine->logEvent($ow->workflow, 'success',
                "Transferred {$transferred} asset(s) to {$target->name}.");
            return;
        }

        // return_to_it (default)
        $this->returnAllToIt($assets, $ow, $today, $engine, $sourceName);
    }

    private function returnAllToIt($assets, OffboardingWorkflow $ow, string $today, WorkflowEngine $engine, string $sourceName): void
    {
        $count = 0;
        foreach ($assets as $a) {
            $a->update([
                'returned_date' => $today,
                'notes'         => "Returned to IT inventory via offboarding #{$ow->id}.",
            ]);
            $count++;
        }
        $engine->logEvent($ow->workflow, 'success',
            "Returned {$count} asset(s) to IT inventory.");
    }
}
