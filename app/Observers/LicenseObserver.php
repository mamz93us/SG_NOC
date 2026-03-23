<?php

namespace App\Observers;

use App\Models\License;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class LicenseObserver
{
    public function created(License $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'License', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(License $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'License', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(License $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'License', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
