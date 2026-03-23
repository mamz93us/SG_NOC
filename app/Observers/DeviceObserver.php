<?php

namespace App\Observers;

use App\Models\Device;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class DeviceObserver
{
    public function created(Device $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Device', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(Device $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Device', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(Device $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Device', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
