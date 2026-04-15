<?php

namespace App\Observers;

use App\Models\DnsAccount;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class DnsAccountObserver
{
    public function created(DnsAccount $model): void
    {
        if (Auth::check()) {
            $safe = $model->only(['id', 'label', 'environment', 'shopper_id', 'is_active']);
            ActivityLog::create(['model_type' => 'DnsAccount', 'model_id' => $model->id, 'action' => 'created', 'changes' => $safe, 'user_id' => Auth::id()]);
        }
    }

    public function updated(DnsAccount $model): void
    {
        if (Auth::check()) {
            $changes = $model->getChanges();
            unset($changes['api_key'], $changes['api_secret'], $changes['updated_at']);
            if (!empty($changes)) {
                ActivityLog::create(['model_type' => 'DnsAccount', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => array_intersect_key($model->getOriginal(), $changes), 'new' => $changes], 'user_id' => Auth::id()]);
            }
        }
    }

    public function deleted(DnsAccount $model): void
    {
        if (Auth::check()) {
            $safe = $model->only(['id', 'label', 'environment', 'shopper_id', 'is_active']);
            ActivityLog::create(['model_type' => 'DnsAccount', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $safe, 'user_id' => Auth::id()]);
        }
    }
}
