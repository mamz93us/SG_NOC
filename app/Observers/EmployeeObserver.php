<?php

namespace App\Observers;

use App\Models\Employee;
use App\Models\ActivityLog;
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
    }

    public function deleted(Employee $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Employee', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
