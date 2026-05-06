<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Services\Identity\GraphService;
use Illuminate\Support\Facades\Auth;

class EmployeeObserver
{
    public function created(Employee $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Employee', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(Employee $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Employee', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }

        // Termination cascade: the workflow-driven path already handles this,
        // but HR often flips status directly from the employee form. When we
        // detect that transition, flag open asset/item assignments for return
        // and disable the linked Azure user so access revocation is not forgotten.
        if ($model->wasChanged('status') && $model->status === 'terminated'
            && $model->getOriginal('status') !== 'terminated') {
            $this->cascadeTermination($model);
        }
    }

    public function deleted(Employee $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Employee', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    private function cascadeTermination(Employee $employee): void
    {
        try {
            $assetCount = $employee->activeAssets()->count();
            $itemCount  = method_exists($employee, 'activeItems') ? $employee->activeItems()->count() : 0;

            if ($assetCount > 0) {
                $employee->activeAssets()->update(['notes' => 'PENDING RETURN — employee terminated']);
            }
            if ($itemCount > 0) {
                $employee->activeItems()->update(['notes' => 'PENDING RETURN — employee terminated']);
            }

            $azureDisabled = false;
            if ($employee->azure_id) {
                try {
                    (new GraphService())->disableUser($employee->azure_id);
                    $azureDisabled = true;
                } catch (\Throwable $e) {
                    ActivityLog::create([
                        'model_type' => 'GraphApi',
                        'model_id'   => 0,
                        'action'     => 'api_failed',
                        'changes'    => [
                            'service'     => 'MicrosoftGraph',
                            'operation'   => 'disableUser',
                            'employee_id' => $employee->id,
                            'message'     => mb_substr($e->getMessage(), 0, 1000),
                        ],
                        'user_id' => Auth::id(),
                    ]);
                }
            }

            ActivityLog::create([
                'model_type' => 'Employee',
                'model_id'   => $employee->id,
                'action'     => 'termination_cascade',
                'changes'    => [
                    'assets_flagged' => $assetCount,
                    'items_flagged'  => $itemCount,
                    'azure_disabled' => $azureDisabled,
                ],
                'user_id' => Auth::id(),
            ]);
        } catch (\Throwable) {
            // Termination must succeed even if the cascade has trouble.
        }
    }
}
