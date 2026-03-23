<?php

namespace App\Observers;

use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class UserObserver
{
    public function created(User $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'User', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->only(['name', 'email', 'role']), 'user_id' => Auth::id()]);
        }
    }

    public function updated(User $model): void
    {
        if (Auth::check()) {
            $changes = $model->getChanges();
            unset($changes['password'], $changes['remember_token']);
            ActivityLog::create(['model_type' => 'User', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => array_intersect_key($model->getOriginal(), $changes), 'new' => $changes], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(User $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'User', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->only(['name', 'email', 'role']), 'user_id' => Auth::id()]);
        }
    }
}
