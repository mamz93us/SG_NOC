<?php

namespace App\Observers;

use App\Models\VpnTunnel;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class VpnTunnelObserver
{
    public function created(VpnTunnel $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'VpnTunnel', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(VpnTunnel $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'VpnTunnel', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(VpnTunnel $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'VpnTunnel', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
