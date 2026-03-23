<?php

namespace App\Observers;

use App\Models\IpReservation;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class IpReservationObserver
{
    public function created(IpReservation $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IpReservation', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(IpReservation $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IpReservation', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(IpReservation $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'IpReservation', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
