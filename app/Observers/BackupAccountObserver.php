<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\BackupAccount;
use Illuminate\Support\Facades\Auth;

class BackupAccountObserver
{
    public function created(BackupAccount $model): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'BackupAccount', 'model_id' => $model->id,
                'action' => 'created', 'changes' => $this->safe($model), 'user_id' => Auth::id(),
            ]);
        }
    }

    public function updated(BackupAccount $model): void
    {
        if (! Auth::check()) {
            return;
        }
        $changes = $model->getChanges();
        unset($changes['password']);   // never log the secret value
        if (empty($changes)) {
            return;
        }
        ActivityLog::create([
            'model_type' => 'BackupAccount', 'model_id' => $model->id, 'action' => 'updated',
            'changes' => ['old' => array_intersect_key($model->getOriginal(), $changes), 'new' => $changes],
            'user_id' => Auth::id(),
        ]);
    }

    public function deleted(BackupAccount $model): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'BackupAccount', 'model_id' => $model->id,
                'action' => 'deleted', 'changes' => $this->safe($model), 'user_id' => Auth::id(),
            ]);
        }
    }

    private function safe(BackupAccount $model): array
    {
        return $model->only([
            'id', 'sftpgo_username', 'device_type', 'device_id', 'label',
            'protocols', 'expected_frequency', 'is_active',
        ]);
    }
}
