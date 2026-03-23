<?php

namespace App\Observers;

use App\Models\Credential;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class CredentialObserver
{
    public function created(Credential $model): void
    {
        if (Auth::check()) {
            // Never log the password/secret value
            $safe = $model->only(['id', 'name', 'category', 'username', 'url', 'branch_id']);
            ActivityLog::create(['model_type' => 'Credential', 'model_id' => $model->id, 'action' => 'created', 'changes' => $safe, 'user_id' => Auth::id()]);
        }
    }

    public function updated(Credential $model): void
    {
        if (Auth::check()) {
            $changes = $model->getChanges();
            unset($changes['password'], $changes['secret']);
            ActivityLog::create(['model_type' => 'Credential', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => array_intersect_key($model->getOriginal(), $changes), 'new' => $changes], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(Credential $model): void
    {
        if (Auth::check()) {
            $safe = $model->only(['id', 'name', 'category', 'username', 'url', 'branch_id']);
            ActivityLog::create(['model_type' => 'Credential', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $safe, 'user_id' => Auth::id()]);
        }
    }
}
