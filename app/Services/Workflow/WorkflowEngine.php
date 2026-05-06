<?php

namespace App\Services\Workflow;

use App\Jobs\ContinueWorkflowAfterWaitJob;
use App\Jobs\ExecuteWorkflowJob;
use App\Jobs\SendOnboardingManagerFormJob;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowLog;
use App\Models\WorkflowRequest;
use App\Models\WorkflowStep;
use App\Models\WorkflowTemplate;
use App\Services\NotificationService;
use App\Services\Workflow\WorkflowStepRegistry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        // Resolve template from DB (falls back to ['it_manager'])
        $template = WorkflowTemplate::where('type_slug', $type)
            ->where('is_active', 1)
            ->first();

        // Graph-based template: use definition
        if ($template && ! empty($template->definition)) {
            $firstNodeId = $this->findFirstExecutableNode($template->definition);
            $workflow = WorkflowRequest::create([
                'type'            => $type,
                'title'           => $title,
                'description'     => $description,
                'payload'         => $payload,
                'branch_id'       => $branchId,
                'requested_by'    => $requestedBy,
                'status'          => 'pending',
                'current_step'    => 1,
                'total_steps'     => 1, // graph-based, steps created dynamically
                'template_id'     => $template->id,
                'current_node_id' => $firstNodeId,
            ]);

            $actor = $requestedBy ? "user #{$requestedBy}" : 'system';
            $this->logEvent($workflow, 'info', "Workflow created by {$actor}: {$title} (graph-based)");

            // Start executing the first node
            $this->executeCurrentNode($workflow);

            return $workflow;
        }

        // Legacy approval-chain-based template
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
            'template_id'  => $template?->id,
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

    public function moveToNextStep(WorkflowRequest $workflow, string $outputPort = 'output_1'): void
    {
        $workflow->refresh();

        // Graph-based: advance by following node connections
        if ($workflow->current_node_id && $workflow->template_id) {
            $this->advanceNode($workflow, $outputPort);
            return;
        }

        // Legacy linear chain
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
    // Graph engine
    // ─────────────────────────────────────────────────────────────

    /**
     * Advance workflow to the next node following the given output port connection.
     */
    public function advanceNode(WorkflowRequest $workflow, string $outputPort = 'output_1'): void
    {
        $template = WorkflowTemplate::find($workflow->template_id);
        if (! $template || empty($template->definition)) {
            $this->markFailed($workflow, 'Template definition missing.');
            return;
        }

        $nodes   = data_get($template->definition, 'drawflow.Home.data', []);
        $current = $nodes[$workflow->current_node_id] ?? null;

        if (! $current) {
            $workflow->update(['status' => 'completed']);
            $this->logEvent($workflow, 'success', 'Workflow completed — no further nodes.');
            return;
        }

        // Find the connected node via the specified output port
        $connections = data_get($current, "outputs.{$outputPort}.connections", []);

        if (empty($connections)) {
            // No more nodes — workflow is done. Requester notification is
            // handled centrally by WorkflowRequestObserver so every completion
            // path (engine, job, offboarding form) produces exactly one.
            $workflow->update(['status' => 'completed']);
            $this->logEvent($workflow, 'success', 'Workflow completed successfully.');
            return;
        }

        $nextNodeId = (string) $connections[0]['node'];
        $workflow->update(['current_node_id' => $nextNodeId]);
        $this->logEvent($workflow, 'info', "Advancing to node {$nextNodeId}.");

        $this->executeCurrentNode($workflow);
    }

    /**
     * Execute whatever node is currently active on the workflow.
     */
    public function executeCurrentNode(WorkflowRequest $workflow): void
    {
        $template = WorkflowTemplate::find($workflow->template_id);
        if (! $template || empty($template->definition)) {
            return;
        }

        $nodes = data_get($template->definition, 'drawflow.Home.data', []);
        $node  = $nodes[$workflow->current_node_id] ?? null;

        if (! $node) {
            return;
        }

        $type = $node['name'] ?? 'approval';

        switch ($type) {
            case 'approval':
                $this->executeApprovalNode($workflow, $node);
                break;
            case 'action':
                $this->executeActionNode($workflow, $node);
                break;
            case 'condition':
                $this->executeConditionNode($workflow, $node);
                break;
            case 'notification':
                $this->executeNotificationNode($workflow, $node);
                break;
            case 'wait':
                $this->executeWaitNode($workflow, $node);
                break;
            default:
                $this->logEvent($workflow, 'warning', "Unknown node type '{$type}', skipping.");
                $this->advanceNode($workflow);
        }
    }

    private function executeApprovalNode(WorkflowRequest $workflow, array $node): void
    {
        $config = $node['data'] ?? [];
        $role   = $config['role'] ?? 'it_manager';
        $label  = $config['label'] ?? 'Approval';

        $stepNumber = $workflow->steps()->max('step_number') + 1;

        WorkflowStep::create([
            'workflow_id'   => $workflow->id,
            'step_number'   => $stepNumber,
            'approver_role' => $role,
            'approver_id'   => $config['user_id'] ?? null,
            'status'        => 'pending',
            'step_type'     => 'approval',
            'step_config'   => $config,
            'node_id'       => $workflow->current_node_id,
        ]);

        $workflow->update([
            'status'       => 'pending',
            'current_step' => $stepNumber,
            'total_steps'  => $stepNumber,
        ]);

        $this->logEvent($workflow, 'info', "Approval required: {$label} ({$role})");
        $this->notifyApprovers($workflow, $stepNumber);
    }

    private function executeActionNode(WorkflowRequest $workflow, array $node): void
    {
        $config   = $node['data'] ?? [];
        $jobClass = $config['job_class'] ?? null;
        $label    = $config['label'] ?? 'Action';

        if (! $jobClass || ! class_exists($jobClass)) {
            $this->logEvent($workflow, 'warning', "Action node: job class '{$jobClass}' not found, skipping.");
            $this->advanceNode($workflow);
            return;
        }

        $params = WorkflowStepRegistry::resolveParams($config['params'] ?? [], $workflow->payload ?? []);

        $this->logEvent($workflow, 'info', "Executing action: {$label}");

        try {
            dispatch(new $jobClass($workflow->id, $params));
        } catch (\Throwable $e) {
            Log::error("[WorkflowEngine] Action node dispatch failed: {$e->getMessage()}");
            $this->logEvent($workflow, 'error', "Action failed: {$e->getMessage()}");
        }

        // Action nodes auto-advance after dispatch
        $this->advanceNode($workflow);
    }

    private function executeConditionNode(WorkflowRequest $workflow, array $node): void
    {
        $config   = $node['data'] ?? [];
        $field    = $config['field'] ?? '';
        $operator = $config['operator'] ?? 'equals';
        $value    = $config['value'] ?? '';
        $payload  = $workflow->payload ?? [];

        $actual   = $payload[$field] ?? null;
        $matched  = match ($operator) {
            'equals'      => (string) $actual === (string) $value,
            'not_equals'  => (string) $actual !== (string) value,
            'contains'    => str_contains((string) $actual, (string) $value),
            'gt'          => is_numeric($actual) && $actual > $value,
            'lt'          => is_numeric($actual) && $actual < $value,
            default       => false,
        };

        $branch = $matched ? 'output_1' : 'output_2';
        $this->logEvent($workflow, 'info', "Condition [{$field} {$operator} {$value}]: " . ($matched ? 'true → output_1' : 'false → output_2'));
        $this->advanceNode($workflow, $branch);
    }

    private function executeNotificationNode(WorkflowRequest $workflow, array $node): void
    {
        $config    = $node['data'] ?? [];
        $channel   = $config['channel'] ?? 'email';
        $recipient = $config['recipient'] ?? null;
        $subject   = $config['subject'] ?? "Workflow: {$workflow->title}";
        $body      = $config['body'] ?? '';

        $this->logEvent($workflow, 'info', "Sending {$channel} notification to: {$recipient}");

        try {
            if ($channel === 'webhook' && $recipient) {
                dispatch(new \App\Jobs\SendWorkflowWebhookJob($workflow->id, $recipient, ['subject' => $subject, 'body' => $body]));
            } elseif ($recipient) {
                // Role-based or direct email
                if (str_starts_with($recipient, 'role:')) {
                    $role = substr($recipient, 5);
                    $this->notifications->notifyAdmins(
                        'workflow_notification',
                        $subject,
                        $body,
                        route('admin.workflows.show', $workflow->id),
                        'info'
                    );
                } elseif ($workflow->requested_by) {
                    $this->notifications->notify(
                        $workflow->requested_by,
                        'workflow_notification',
                        $subject,
                        $body,
                        route('admin.workflows.show', $workflow->id),
                        'info'
                    );
                }
            }
        } catch (\Throwable $e) {
            $this->logEvent($workflow, 'warning', "Notification failed: {$e->getMessage()}");
        }

        // Notification nodes auto-advance
        $this->advanceNode($workflow);
    }

    private function executeWaitNode(WorkflowRequest $workflow, array $node): void
    {
        $config = $node['data'] ?? [];
        $hours  = max(1, (int) ($config['hours'] ?? 1));
        $label  = $config['label'] ?? "Wait {$hours}h";

        $this->logEvent($workflow, 'info', "Pausing workflow: {$label}");

        ContinueWorkflowAfterWaitJob::dispatch($workflow->id, $workflow->current_node_id)
            ->delay(now()->addHours($hours));
    }

    /**
     * Find the first executable node in a Drawflow definition (skip trigger nodes).
     */
    private function findFirstExecutableNode(array $definition): ?string
    {
        $nodes = data_get($definition, 'drawflow.Home.data', []);

        // Find a node with no inputs (root node) that isn't a trigger
        foreach ($nodes as $id => $node) {
            $inputConnections = collect(data_get($node, 'inputs', []))
                ->flatMap(fn($input) => $input['connections'] ?? []);

            if ($inputConnections->isEmpty() && ($node['name'] ?? '') !== 'trigger') {
                return (string) $id;
            }
        }

        // Fallback: first node
        return array_key_first($nodes) ? (string) array_key_first($nodes) : null;
    }

    // ─────────────────────────────────────────────────────────────
    // Execute
    // ─────────────────────────────────────────────────────────────

    public function executeWorkflow(WorkflowRequest $workflow): void
    {
        // For create_user workflows with a manager email, send the manager
        // setup form NOW (post IT approval) and pause execution until the
        // manager submits the form. Provisioning must not start before the
        // manager has declared whether an extension is needed, chosen
        // internet access level, floor, groups, etc.
        if ($this->shouldWaitForManagerForm($workflow)) {
            $this->dispatchManagerFormEmail($workflow);
            $workflow->update(['status' => 'awaiting_manager_form']);
            $this->logEvent(
                $workflow,
                'info',
                'Approvals complete. Manager setup form sent — waiting for manager response before provisioning.'
            );
            return;
        }

        $workflow->update(['status' => 'executing']);
        $this->logEvent($workflow, 'info', 'Executing workflow...');
        ExecuteWorkflowJob::dispatchSync($workflow->id);
    }

    /**
     * Called from OnboardingFormController once the manager submits the form.
     * Resumes execution of a workflow that was paused in awaiting_manager_form.
     */
    public function resumeAfterManagerForm(WorkflowRequest $workflow): void
    {
        $workflow->refresh();

        if ($workflow->status !== 'awaiting_manager_form') {
            // Already executing/completed/failed — don't double-run.
            return;
        }

        $this->logEvent($workflow, 'info', 'Manager submitted setup form. Resuming provisioning.');
        $workflow->update(['status' => 'executing']);
        ExecuteWorkflowJob::dispatchSync($workflow->id);
    }

    /**
     * True when the workflow is a create_user with a manager_email and no
     * form response yet. The manager must fill the form before provisioning.
     */
    private function shouldWaitForManagerForm(WorkflowRequest $workflow): bool
    {
        if ($workflow->type !== 'create_user') {
            return false;
        }

        $payload      = $workflow->payload ?? [];
        $managerEmail = $payload['manager_email'] ?? null;

        if (! $managerEmail) {
            return false;
        }

        // If a manager form response has already been saved into the
        // payload (manager filled it while IT was approving), don't wait.
        if (! empty($payload['manager_form_token_id'])) {
            return false;
        }

        // If any token for this workflow has responded_at set, don't wait.
        $responded = OnboardingManagerToken::where('workflow_id', $workflow->id)
            ->whereNotNull('responded_at')
            ->exists();

        return ! $responded;
    }

    /**
     * Dispatch the manager form email. Reuses the existing valid token
     * (created synchronously at workflow creation) or generates one.
     */
    private function dispatchManagerFormEmail(WorkflowRequest $workflow): void
    {
        $payload      = $workflow->payload ?? [];
        $managerEmail = $payload['manager_email'] ?? null;

        if (! $managerEmail) {
            return;
        }

        $token = OnboardingManagerToken::where('workflow_id', $workflow->id)
            ->whereNull('responded_at')
            ->latest()
            ->first();

        if (! $token || ! $token->isValid()) {
            $managerName = ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');
            OnboardingManagerToken::generate($workflow->id, [
                'manager_email' => $managerEmail,
                'manager_name'  => $managerName,
            ]);
        }

        try {
            // Dispatch to the default queue — that's where the worker is actually
            // running. An `emails` queue is referenced elsewhere but has no worker
            // so jobs sent there would sit in the jobs table indefinitely.
            SendOnboardingManagerFormJob::dispatch($workflow->id);
            $this->logEvent($workflow, 'info', "Manager setup form email queued for {$managerEmail}.");
        } catch (\Throwable $e) {
            Log::error("[WorkflowEngine] Failed to queue manager form email for workflow #{$workflow->id}: {$e->getMessage()}");
            $this->logEvent($workflow, 'warning', "Failed to queue manager form email: {$e->getMessage()}");
        }
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

        // Honor Notification Routing Rules — if a rule targets 'approval_request'
        // (or wildcard '*') only its recipients receive the email. Falls back to
        // notifyAdmins() when no rule is configured, so approvals never vanish.
        $this->notifications->notifyViaRules(
            'approval_request',
            "Approval Required — {$workflow->title}",
            "A workflow request requires {$step->approverRoleLabel()} approval (Step {$stepNumber}).",
            route('admin.workflows.show', $workflow->id),
            'warning'
        );
    }
}
