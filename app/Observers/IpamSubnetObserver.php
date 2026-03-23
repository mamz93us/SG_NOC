<?php

namespace App\Observers;

use App\Models\IpamSubnet;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class IpamSubnetObserver
{
    public function created(IpamSubnet $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IpamSubnet', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(IpamSubnet $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IpamSubnet', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(IpamSubnet $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IpamSubnet', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
