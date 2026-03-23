<?php

namespace App\Observers;

use App\Models\IspConnection;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class IspConnectionObserver
{
    public function created(IspConnection $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IspConnection', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(IspConnection $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IspConnection', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(IspConnection $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IspConnection', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
