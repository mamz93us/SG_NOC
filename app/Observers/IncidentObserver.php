<?php

namespace App\Observers;

use App\Models\Incident;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class IncidentObserver
{
    public function created(Incident $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Incident', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(Incident $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Incident', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(Incident $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Incident', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
