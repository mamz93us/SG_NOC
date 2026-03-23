<?php

namespace App\Observers;

use App\Models\NetworkSwitch;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class NetworkSwitchObserver
{
    public function created(NetworkSwitch $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'NetworkSwitch', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(NetworkSwitch $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'NetworkSwitch', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(NetworkSwitch $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'NetworkSwitch', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
