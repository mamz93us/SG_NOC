<?php

namespace App\Observers;

use App\Models\AlertRule;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class AlertRuleObserver
{
    public function created(AlertRule $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'AlertRule', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(AlertRule $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'AlertRule', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(AlertRule $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'AlertRule', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
