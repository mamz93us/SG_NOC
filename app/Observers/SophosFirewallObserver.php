<?php

namespace App\Observers;

use App\Models\SophosFirewall;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class SophosFirewallObserver
{
    public function created(SophosFirewall $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'SophosFirewall', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(SophosFirewall $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'SophosFirewall', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(SophosFirewall $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'SophosFirewall', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
