<?php

namespace App\Observers;

use App\Models\Branch;
use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class BranchObserver
{
    /**
     * Handle the Branch "created" event.
     */
    public function created(Branch $branch): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'Branch',
                'model_id' => $branch->id,
                'action' => 'created',
                'changes' => $branch->toArray(),
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Handle the Branch "updated" event.
     */
    public function updated(Branch $branch): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'Branch',
                'model_id' => $branch->id,
                'action' => 'updated',
                'changes' => [
                    'old' => $branch->getOriginal(),
                    'new' => $branch->getChanges(),
                ],
                'user_id' => Auth::id(),
            ]);
        }
    }

    /**
     * Handle the Branch "deleted" event.
     */
    public function deleted(Branch $branch): void
    {
        if (Auth::check()) {
            ActivityLog::create([
                'model_type' => 'Branch',
                'model_id' => $branch->id,
                'action' => 'deleted',
                'changes' => $branch->toArray(),
                'user_id' => Auth::id(),
            ]);
        }
    }
}
