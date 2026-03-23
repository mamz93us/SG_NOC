<?php

namespace App\Observers;

use App\Models\WorkflowRequest;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class WorkflowRequestObserver
{
    public function created(WorkflowRequest $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'WorkflowRequest', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(WorkflowRequest $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'WorkflowRequest', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(WorkflowRequest $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'WorkflowRequest', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
