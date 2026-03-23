<?php

namespace App\Observers;

use App\Models\NotificationRule;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class NotificationRuleObserver
{
    public function created(NotificationRule $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'NotificationRule', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(NotificationRule $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'NotificationRule', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(NotificationRule $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'NotificationRule', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
