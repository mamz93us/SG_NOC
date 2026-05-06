<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\WorkflowRequest;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;

class WorkflowRequestObserver
{
    public function created(WorkflowRequest $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'WorkflowRequest', 'model_id' => $model->id, 'action' => 'created', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    public function updated(WorkflowRequest $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'WorkflowRequest', 'model_id' => $model->id, 'action' => 'updated', 'changes' => ['old' => $model->getOriginal(), 'new' => $model->getChanges()], 'user_id' => Auth::id()]);
        }

        // Notify the requester on terminal status transitions. Several code paths
        // flip status directly (ExecuteWorkflowJob, OffboardingFormController,
        // admin UI) without calling NotificationService — centralising it here
        // guarantees the requester hears back regardless of which path fires.
        if ($model->wasChanged('status')) {
            $this->notifyOnTerminalStatus($model);
        }
    }

    public function deleted(WorkflowRequest $model): void
    {
        if (Auth::check()) {
            ActivityLog::create(['model_type' => 'WorkflowRequest', 'model_id' => $model->id, 'action' => 'deleted', 'changes' => $model->toArray(), 'user_id' => Auth::id()]);
        }
    }

    private function notifyOnTerminalStatus(WorkflowRequest $model): void
    {
        $terminal = ['completed', 'failed', 'rejected', 'cancelled'];
        if (! in_array($model->status, $terminal, true)) {
            return;
        }
        if (! $model->requested_by) {
            return;
        }

        try {
            $notifications = app(NotificationService::class);

            [$title, $message, $severity] = match ($model->status) {
                'completed' => [
                    "Request Completed — {$model->title}",
                    'Your workflow request has been completed successfully.',
                    'success',
                ],
                'rejected'  => [
                    "Request Rejected — {$model->title}",
                    'Your workflow request was rejected by an approver.',
                    'warning',
                ],
                'failed'    => [
                    "Request Failed — {$model->title}",
                    'Your workflow request failed during execution. Please contact IT.',
                    'critical',
                ],
                'cancelled' => [
                    "Request Cancelled — {$model->title}",
                    'Your workflow request was cancelled.',
                    'info',
                ],
            };

            $notifications->notify(
                $model->requested_by,
                'workflow_' . $model->status,
                $title,
                $message,
                route('admin.workflows.show', $model->id),
                $severity
            );
        } catch (\Throwable) {
            // Notification delivery must not block the workflow state change.
        }
    }
}
