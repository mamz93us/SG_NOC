<?php

namespace App\Observers;

use App\Models\Printer;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class PrinterObserver
{
    public function created(Printer $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Printer', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(Printer $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Printer', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }
    }

    public function deleted(Printer $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'Printer', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }
}
