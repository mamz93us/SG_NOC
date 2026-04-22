<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\EmployeeAsset;
use App\Models\WorkflowRequest;
use App\Models\WorkflowTask;
use Illuminate\Support\Facades\Auth;

class EmployeeAssetObserver
{
    /**
     * When an asset is returned (returned_date flipped from null → value),
     * close any open workflow tasks that were waiting on this return and
     * audit the transition. This avoids stuck offboarding workflows where
     * HR forgot to manually mark the asset-return task complete.
     */
    public function updated(EmployeeAsset $asset): void
    {
        if (! $asset->wasChanged('returned_date')) {
            return;
        }

        // We only act on the null → value transition (a real return).
        if ($asset->getOriginal('returned_date') !== null || $asset->returned_date === null) {
            return;
        }

        $closed = $this->closeRelatedTasks($asset);

        try {
            ActivityLog::create([
                'model_type' => EmployeeAsset::class,
                'model_id'   => $asset->id,
                'action'     => 'asset_returned',
                'changes'    => [
                    'employee_id'  => $asset->employee_id,
                    'asset_id'     => $asset->asset_id,
                    'tasks_closed' => $closed,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Audit failures must not block the return.
        }
    }

    private function closeRelatedTasks(EmployeeAsset $asset): int
    {
        $count = 0;

        // Find open asset-return tasks whose payload points at this asset
        // or its employee. Payload is JSON, so we can't use a tight SQL
        // filter across drivers — keep the query narrow, match in PHP.
        $tasks = WorkflowTask::whereIn('type', ['asset_return', 'collect_equipment', 'offboarding_asset_return'])
            ->whereIn('status', ['pending', 'in_progress'])
            ->whereHas('workflow', function ($q) use ($asset) {
                $q->whereIn('status', ['pending', 'in_progress'])
                  ->where(function ($inner) use ($asset) {
                      $inner->whereJsonContains('payload->employee_id', $asset->employee_id)
                            ->orWhereJsonContains('payload->asset_id', $asset->asset_id);
                  });
            })
            ->get();

        foreach ($tasks as $task) {
            $payload = $task->payload ?? [];
            $matchesAsset    = isset($payload['asset_id'])    && (int) $payload['asset_id']    === (int) $asset->asset_id;
            $matchesEmployee = isset($payload['employee_id']) && (int) $payload['employee_id'] === (int) $asset->employee_id;

            if (! $matchesAsset && ! $matchesEmployee) {
                continue;
            }

            $task->update([
                'status'       => 'completed',
                'completed_at' => now(),
                'completed_by' => Auth::id(),
                'notes'        => trim(($task->notes ? $task->notes . "\n" : '') . 'Auto-closed on asset return.'),
            ]);
            $count++;
        }

        return $count;
    }
}
