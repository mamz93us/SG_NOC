<?php

namespace App\Services\Workflow;

use App\Jobs\ExecuteWorkflowJob;
use App\Models\WorkflowLog;
use App\Models\WorkflowRequest;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;

class WorkflowEngine
{
    public function __construct(private NotificationService $notifications) {}

    // ─────────────────────────────────────────────────────────────
    // Create
    // ─────────────────────────────────────────────────────────────

    public function createRequest(
        string  $type,
        array   $payload,
        ?int    $branchId,
        ?int    $requestedBy,
        string  $title,
        ?string $description = null
    ): WorkflowRequest {
        // Resolve approval chain from DB template (falls back to ['it_manager'])
        $template = WorkflowTemplate::where('type_slug', $type)
            ->where('is_active', 1)
            ->first();
        $chain = $template?->approval_chain ?? ['it_manager'];

        $workflow = WorkflowRequest::create([
            'type'         => $type,
            'title'        => $title,
            'description'  => $description,
            'payload'      => $payload,
            'branch_id'    => $branchId,
            'requested_by' => $requestedBy,
            'status'       => 'pending',
            'current_step' => 1,
            'total_steps'  => count($chain),
        ]);

        $this->buildApprovalChain($workflow, $chain);

        $actor = $requestedBy ? "user #{$requestedBy}" : 'system';
        $this->logEvent($workflow, 'info', "Workflow created by {$actor}: {$title}");

        // Notify approvers of step 1
        $this->notifyApprovers($workflow, 1);

        return $workflow;
    }

    // ─────────────────────────────────────────────────────────────
    // Build approval steps
    // ─────────────────────────────────────────────────────────────

    public function buildApprovalChain(WorkflowRequest $workflow, array $chain): void
    {
        foreach ($chain as $i => $role) {
            WorkflowStep::create([
                'workflow_id'   => $workflow->id,
                'step_number'   => $i + 1,
                'approver_role' => $role,
                'status'        => 'pending',
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Approve / Reject
    // ─────────────────────────────────────────────────────────────

    public function approveStep(WorkflowRequest $workflow, \App\Models\User $user, ?string $comments = null): void
    {
        $step = $workflow->currentStepRecord();

        if (! $step || $step->status !== 'pending') {
            throw new \RuntimeException('No pending step to approve.');
        }

        $step->update([
            'status'   => 'approved',
            'acted_by' => $user->id,
            'acted_at' => now(),
            'comments' => $comments,
        ]);

        $this->logEvent($workflow, 'success', "Step {$step->step_number} approved by {$user->name}" . ($comments ? ": {$comments}" : ''));

        if ($workflow->requested_by) {
            $this->notifications->notify(
                $workflow->requested_by,
                'approval_action',
                "Step {$step->step_number} Approved — {$workflow->title}",
                "{$user->name} approved step {$step->step_number} ({$step->approverRoleLabel()}).",
                route('admin.workflows.show', $workflow->id),
                'info'
            );
        }

        $this->moveToNextStep($workflow);
    }

    public function rejectStep(WorkflowRequest $workflow, \App\Models\User $user, ?string $comments = null): void
    {
        $step = $workflow->currentStepRecord();

        if (! $step || $step->status !== 'pending') {
            throw new \RuntimeException('No pending step to reject.');
        }

        $step->update([
            'status'   => 'rejected',
            'acted_by' => $user->id,
            'acted_at' => now(),
            'comments' => $comments,
        ]);

        $workflow->update(['status' => 'rejected']);
        $this->logEvent($workflow, 'error', "Step {$step->step_number} rejected by {$user->name}" . ($comments ? ": {$comments}" : ''));

        if ($workflow->requested_by) {
            $this->notifications->notify(
                $workflow->requested_by,
                'approval_action',
                "Request Rejected — {$workflow->title}",
                "{$user->name} rejected this request at step {$step->step_number}." . ($comments ? " Reason: {$comments}" : ''),
                route('admin.workflows.show', $workflow->id),
                'warning'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Advance to next step
    // ─────────────────────────────────────────────────────────────

    public function moveToNextStep(WorkflowRequest $workflow): void
    {
        $workflow->refresh();
        $nextStep = $workflow->current_step + 1;

        if ($nextStep > $workflow->total_steps) {
            $workflow->update(['status' => 'approved']);
            $this->logEvent($workflow, 'success', 'All approval steps completed. Queuing execution.');
            $this->executeWorkflow($workflow);
        } else {
            $workflow->update(['current_step' => $nextStep]);
            $this->logEvent($workflow, 'info', "Advanced to step {$nextStep}.");
            $this->notifyApprovers($workflow, $nextStep);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Execute
    // ─────────────────────────────────────────────────────────────

    public function executeWorkflow(WorkflowRequest $workflow): void
    {
        $workflow->update(['status' => 'executing']);
        $this->logEvent($workflow, 'info', 'Executing workflow...');
        ExecuteWorkflowJob::dispatchSync($workflow->id);
    }

    // ─────────────────────────────────────────────────────────────
    // Mark failed
    // ─────────────────────────────────────────────────────────────

    public function markFailed(WorkflowRequest $workflow, string $message): void
    {
        $workflow->update(['status' => 'failed']);
        $this->logEvent($workflow, 'error', "Workflow failed: {$message}");

        $this->notifications->notifyAdmins(
            'workflow_failed',
            "Workflow Failed — {$workflow->title}",
            "Workflow #{$workflow->id} ({$workflow->typeLabel()}) failed: {$message}",
            route('admin.workflows.show', $workflow->id),
            'critical'
        );

        if ($workflow->requested_by) {
            $this->notifications->notify(
                $workflow->requested_by,
                'workflow_failed',
                "Your Request Failed — {$workflow->title}",
                "Your request could not be completed: {$message}",
                route('admin.workflows.show', $workflow->id),
                'critical'
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Logging
    // ─────────────────────────────────────────────────────────────

    public function logEvent(WorkflowRequest $workflow, string $level, string $message, array $context = []): void
    {
        WorkflowLog::create([
            'workflow_id' => $workflow->id,
            'level'       => $level,
            'message'     => $message,
            'context'     => empty($context) ? null : $context,
            'created_at'  => now(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Notify approvers for a given step
    // ─────────────────────────────────────────────────────────────

    private function notifyApprovers(WorkflowRequest $workflow, int $stepNumber): void
    {
        $step = WorkflowStep::where('workflow_id', $workflow->id)
            ->where('step_number', $stepNumber)
            ->first();

        if (! $step) return;

        if ($step->approver_id) {
            $this->notifications->notify(
                $step->approver_id,
                'approval_request',
                "Approval Required — {$workflow->title}",
                "A workflow request requires your approval (Step {$stepNumber}: {$step->approverRoleLabel()}).",
                route('admin.workflows.show', $workflow->id),
                'warning'
            );
            return;
        }

        $this->notifications->notifyAdmins(
            'approval_request',
            "Approval Required — {$workflow->title}",
            "A workflow request requires {$step->approverRoleLabel()} approval (Step {$stepNumber}).",
            route('admin.workflows.show', $workflow->id),
            'warning'
        );
    }
}
