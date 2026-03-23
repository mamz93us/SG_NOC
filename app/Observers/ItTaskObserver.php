<?php

namespace App\Observers;

use App\Models\ItTask;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ItTaskObserver
{
    public function created(ItTask $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'ItTask', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(ItTask $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'ItTask', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(ItTask $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'ItTask', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
