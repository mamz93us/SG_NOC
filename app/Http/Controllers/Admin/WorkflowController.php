<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendOnboardingManagerFormJob;
use App\Models\AllowedDomain;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\OnboardingManagerToken;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Models\WorkflowRequest;
use App\Models\WorkflowStep;
use App\Models\WorkflowTask;
use App\Services\Identity\GraphService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkflowController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

    // All workflows (admin)
    public function index(Request $request)
    {
        $query = WorkflowRequest::with('requester', 'branch')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $workflows = $query->paginate(20)->withQueryString();

        return view('admin.workflows.index', compact('workflows'));
    }

    // My submitted requests
    public function myRequests()
    {
        $workflows = WorkflowRequest::where('requested_by', Auth::id())
            ->with('branch')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.workflows.my-requests', compact('workflows'));
    }

    // Pending my approval
    public function pending()
    {
        $user      = Auth::user();
        $workflows = WorkflowRequest::where('status', 'pending')
            ->with('steps', 'requester', 'branch')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($w) => $w->isAwaitingMyApproval($user->id));

        return view('admin.workflows.pending', compact('workflows'));
    }

    // Detail view
    public function show(int $id)
    {
        $workflow = WorkflowRequest::with('steps.actor', 'steps.approver', 'logs', 'requester', 'branch')
            ->findOrFail($id);

        $canApprove = $workflow->isAwaitingMyApproval(Auth::id());

        // Device assignment panel (post-provisioning, create_user only).
        // Only load the picker data when an employee has been created for
        // this workflow — avoids unnecessary queries on other workflow types.
        $employeeId        = $workflow->payload['employee_id'] ?? null;
        $employee          = $employeeId ? Employee::find($employeeId) : null;
        $currentAssignments = collect();
        $availableDevices   = collect();

        if ($employee) {
            $currentAssignments = EmployeeAsset::with('device')
                ->where('employee_id', $employee->id)
                ->whereNull('returned_date')
                ->orderByDesc('assigned_date')
                ->get();

            // Prefer devices in the workflow's branch; if none, show all
            // available. Keeps the picker useful when branch isn't set.
            $availableQuery = Device::where('status', 'available');
            if ($workflow->branch_id) {
                $branchScoped = (clone $availableQuery)
                    ->where('branch_id', $workflow->branch_id);
                $availableDevices = $branchScoped
                    ->orderBy('type')->orderBy('name')
                    ->get(['id', 'name', 'type', 'asset_code', 'serial_number']);
                if ($availableDevices->isEmpty()) {
                    $availableDevices = $availableQuery
                        ->with('branch:id,name')
                        ->orderBy('type')->orderBy('name')
                        ->get(['id', 'name', 'type', 'asset_code', 'serial_number', 'branch_id']);
                }
            } else {
                $availableDevices = $availableQuery
                    ->with('branch:id,name')
                    ->orderBy('type')->orderBy('name')
                    ->get(['id', 'name', 'type', 'asset_code', 'serial_number', 'branch_id']);
            }
        }

        return view('admin.workflows.show', compact(
            'workflow', 'canApprove', 'employee', 'currentAssignments', 'availableDevices'
        ));
    }

    // Create form
    public function create(Request $request)
    {
        $type        = $request->query('type');
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get(['id', 'name']);
        $settings    = Setting::get();
        // UPN domains for the email domain picker (primary first, then alphabetical)
        $upnDomains  = AllowedDomain::orderByDesc('is_primary')->orderBy('domain')->get();
        $types       = [
            'create_user'          => 'Create New User',
            'delete_user'          => 'Deactivate User',
            'employee_offboarding' => 'Employee Offboarding',
            'license_change'       => 'License Change',
            'asset_assign'         => 'Assign Asset',
            'asset_return'         => 'Return Asset',
            'extension_create'     => 'Create Extension',
            'extension_delete'     => 'Delete Extension',
            'group_assignment'     => 'Group Assignment',
            'other'                => 'Other Request',
        ];

        return view('admin.workflows.create', compact('type', 'types', 'branches', 'departments', 'settings', 'upnDomains'));
    }

    // AJAX: preview provisioning data for create_user form
    public function previewUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings  = Setting::get();
        $firstName = trim($request->query('first_name', ''));
        $lastName  = trim($request->query('last_name', ''));
        $branchId  = $request->query('branch_id');
        // Use domain selected in the form, fall back to global default
        $domain    = trim($request->query('domain', $settings->upn_domain ?? 'example.com')) ?: 'example.com';

        // Build preview UPN (no collision check needed — just a preview)
        $sanitize = function (string $s): string {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            return strtolower(preg_replace('/[^a-z0-9]/', '', $s));
        };
        $upn = ($firstName && $lastName)
            ? $sanitize($firstName) . '.' . $sanitize($lastName) . '@' . $domain
            : null;

        // Extension range (branch-aware)
        $branch = $branchId ? Branch::find($branchId) : null;
        $range  = $branch
            ? $branch->effectiveExtRange($settings)
            : ['start' => (int) ($settings->ext_range_start ?? 1000), 'end' => (int) ($settings->ext_range_end ?? 1999)];

        // UCM server name
        $ucmName = null;
        if ($branch) {
            $ucmName = $branch->effectiveUcmServer($settings)?->name;
        } elseif ($settings->default_ucm_id) {
            $ucmName = UcmServer::find($settings->default_ucm_id)?->name;
        }

        // Multi-license: return array of {sku, name} objects for display
        $licenseSkus = $settings->graph_default_license_skus ?? [];
        if (empty($licenseSkus) && $settings->graph_default_license_sku) {
            $licenseSkus = [$settings->graph_default_license_sku];
        }

        // Resolve friendly names via cached Azure SKU map
        $licenseData = [];
        if (!empty($licenseSkus)) {
            try {
                $skuMap = (new GraphService())->getSkuNameMap();
                foreach ($licenseSkus as $sku) {
                    $licenseData[] = ['sku' => $sku, 'name' => $skuMap[$sku] ?? $sku];
                }
            } catch (\Throwable) {
                // Azure unreachable — fall back to raw SKU IDs
                foreach ($licenseSkus as $sku) {
                    $licenseData[] = ['sku' => $sku, 'name' => $sku];
                }
            }
        }

        return response()->json(compact('upn', 'range', 'ucmName', 'licenseData'));
    }

    // Submit new request
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'        => 'required|in:create_user,delete_user,employee_offboarding,license_change,asset_assign,asset_return,extension_create,extension_delete,group_assignment,other',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'branch_id'   => 'nullable|exists:branches,id',
        ]);

        $payload = $request->except(['type', 'title', 'description', 'branch_id', '_token']);

        // Convert group_names_raw (textarea, one per line) → group_names array
        if (! empty($payload['group_names_raw'])) {
            $payload['group_names'] = array_values(array_filter(
                array_map('trim', explode("\n", $payload['group_names_raw']))
            ));
            unset($payload['group_names_raw']);
        }

        // Normalize: the form uses "employee_email" — rename to "upn" for unified payload
        if (! empty($payload['employee_email']) && empty($payload['upn'])) {
            $payload['upn'] = $payload['employee_email'];
        }
        unset($payload['employee_email']);

        $workflow = $this->engine->createRequest(
            type:        $validated['type'],
            payload:     $payload,
            branchId:    $validated['branch_id'] ?? null,
            requestedBy: Auth::id(),
            title:       $validated['title'],
            description: $validated['description'] ?? null,
        );

        // For create_user workflows: create manager form token immediately so
        // it appears on the workflow page. The email is dispatched by the
        // engine AFTER IT approval completes — not now.
        if ($validated['type'] === 'create_user' && ! empty($payload['manager_email'])) {
            $managerEmail = $payload['manager_email'];
            $managerName  = ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');
            OnboardingManagerToken::generate($workflow->id, [
                'manager_email' => $managerEmail,
                'manager_name'  => $managerName,
            ]);
        }

        return redirect()
            ->route('admin.workflows.show', $workflow->id)
            ->with('success', 'Workflow request submitted. Awaiting approval.');
    }

    // Approve current step
    public function approve(Request $request, int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);
        $user     = Auth::user();

        if (!$workflow->isAwaitingMyApproval($user->id)) {
            return back()->with('error', 'You are not authorized to approve this step.');
        }

        $comments = $request->input('comments');

        $step = $workflow->currentStepRecord();
        $this->engine->approveStep($workflow, $user, $comments);

        \App\Models\ActivityLog::create([
            'model_type' => \App\Models\WorkflowRequest::class,
            'model_id'   => $workflow->id,
            'action'     => 'workflow_step_approved',
            'changes'    => [
                'step_id'     => $step?->id,
                'step_role'   => $step?->assignee_role ?? $step?->role,
                'type'        => $workflow->type,
                'comments'    => $comments,
            ],
            'user_id'    => $user->id,
        ]);

        return redirect()
            ->route('admin.workflows.show', $id)
            ->with('success', 'Step approved successfully.');
    }

    // Reject current step
    public function reject(Request $request, int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);
        $user     = Auth::user();

        if (!$workflow->isAwaitingMyApproval($user->id)) {
            return back()->with('error', 'You are not authorized to reject this step.');
        }

        $request->validate(['comments' => 'nullable|string|max:1000']);

        $this->engine->rejectStep($workflow, $user, $request->input('comments'));

        \App\Models\ActivityLog::create([
            'model_type' => \App\Models\WorkflowRequest::class,
            'model_id'   => $workflow->id,
            'action'     => 'workflow_step_rejected',
            'changes'    => [
                'type'     => $workflow->type,
                'comments' => $request->input('comments'),
            ],
            'user_id'    => $user->id,
        ]);

        return redirect()
            ->route('admin.workflows.show', $id)
            ->with('success', 'Request rejected.');
    }

    // Cancel a workflow (requester on their own draft, OR an approver on any non-terminal workflow)
    public function cancel(Request $request, int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);
        $user     = Auth::user();

        // Terminal states — nothing to cancel
        if (in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled'])) {
            return back()->with('error', "Workflow is already {$workflow->status} — cannot cancel.");
        }

        // Authorization: requester on their own draft, OR a user with
        // approve-workflows / manage-workflows permission.
        $isOwner    = $workflow->requested_by === $user?->id;
        $canApprove = $user?->can('approve-workflows') || $user?->can('manage-workflows');

        if (! $isOwner && ! $canApprove) {
            abort(403, 'You are not allowed to cancel this workflow.');
        }

        $reason = trim((string) $request->input('reason', ''));

        $workflow->update(['status' => 'cancelled']);

        // Cancel any pending approval steps so dashboards reflect state.
        // workflow_steps.status is an ENUM — reuse 'skipped' for cancelled runs.
        WorkflowStep::where('workflow_id', $workflow->id)
            ->where('status', 'pending')
            ->update(['status' => 'skipped']);

        // Expire any unfilled manager tokens so the link can't be used later
        OnboardingManagerToken::where('workflow_id', $workflow->id)
            ->whereNull('responded_at')
            ->whereNull('used_at')
            ->update(['expires_at' => now()->subMinute()]);

        $this->engine->logEvent(
            $workflow,
            'warning',
            'Workflow cancelled by ' . ($user?->name ?? 'system') . ($reason !== '' ? ": {$reason}" : '.')
        );

        \App\Models\ActivityLog::create([
            'model_type' => \App\Models\WorkflowRequest::class,
            'model_id'   => $workflow->id,
            'action'     => 'workflow_cancelled',
            'changes'    => [
                'type'   => $workflow->type,
                'reason' => $reason ?: null,
            ],
            'user_id'    => $user?->id,
        ]);

        // Send the user back to where they came from (show page for approvers,
        // my-requests list for the requester cancelling their own draft).
        if ($canApprove && ! $request->has('from_my_requests')) {
            return redirect()
                ->route('admin.workflows.show', $workflow->id)
                ->with('success', 'Workflow cancelled.');
        }

        return redirect()
            ->route('admin.workflows.my-requests')
            ->with('success', 'Request cancelled.');
    }

    // Permanently delete a workflow and its related rows.
    // Only terminal states are deletable to prevent orphaning an in-flight
    // provisioning run.
    public function destroy(int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);
        $user     = Auth::user();

        if (! in_array($workflow->status, ['completed', 'rejected', 'failed', 'cancelled'])) {
            return back()->with('error',
                "Workflow must be completed, rejected, failed, or cancelled before it can be deleted (current: {$workflow->status}).");
        }

        $typeLabel = $workflow->typeLabel();
        $title     = $workflow->title;
        $workflowId = $workflow->id;

        // Related rows cascade via foreign-key constraints:
        //   workflow_steps, workflow_logs, workflow_tasks, onboarding_manager_tokens
        $workflow->delete();

        \App\Models\ActivityLog::create([
            'model_type' => \App\Models\WorkflowRequest::class,
            'model_id'   => $workflowId,
            'action'     => 'workflow_deleted',
            'changes'    => ['type' => $typeLabel, 'title' => $title],
            'user_id'    => $user?->id,
        ]);

        return redirect()
            ->route('admin.workflows.index')
            ->with('success', "Workflow \"{$title}\" deleted.");
    }

    // (Re)create manager form token and dispatch email — useful for existing workflows
    // or if the manager never received / lost the link.
    public function resendManagerForm(WorkflowRequest $workflow)
    {
        if ($workflow->type !== 'create_user') {
            return back()->with('error', 'Manager form is only for create_user workflows.');
        }

        $payload      = $workflow->payload ?? [];
        $managerEmail = $payload['manager_email'] ?? null;

        if (! $managerEmail) {
            return back()->with('error', 'No manager email in this workflow payload.');
        }

        $managerName = ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');

        // Expire any old unfilled tokens
        OnboardingManagerToken::where('workflow_id', $workflow->id)
            ->whereNull('responded_at')
            ->update(['expires_at' => now()->subMinute()]);

        // Create a fresh token
        OnboardingManagerToken::generate($workflow->id, [
            'manager_email' => $managerEmail,
            'manager_name'  => $managerName,
        ]);

        // Send the email (default queue — that's where the worker is running)
        SendOnboardingManagerFormJob::dispatch($workflow->id);

        return redirect()
            ->route('admin.workflows.show', $workflow->id)
            ->with('success', "Manager form (re)sent to {$managerEmail}. The form link is now available on this page.");
    }

    // Mark a workflow task as completed
    public function completeTask(Request $request, WorkflowTask $task)
    {
        if ($task->status === 'completed') {
            return back()->with('error', 'Task is already completed.');
        }

        $task->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'completed_by' => Auth::id(),
            'notes'        => $request->input('notes'),
        ]);

        \App\Models\ActivityLog::create([
            'model_type' => \App\Models\WorkflowTask::class,
            'model_id'   => $task->id,
            'action'     => 'workflow_task_completed',
            'changes'    => [
                'workflow_id' => $task->workflow_id,
                'title'       => $task->title ?? null,
                'notes'       => $request->input('notes'),
            ],
            'user_id'    => Auth::id(),
        ]);

        // Check if all tasks for this workflow are now done — notify IT team
        $workflow = $task->workflow;
        if ($workflow) {
            $pendingCount = WorkflowTask::where('workflow_id', $workflow->id)
                ->whereNotIn('status', ['completed', 'cancelled'])
                ->count();

            if ($pendingCount === 0) {
                $payload     = $workflow->payload ?? [];
                $displayName = $payload['display_name'] ?? 'New Employee';
                // Notify IT team that all tasks are done
                app(\App\Services\NotificationService::class)->notifyAdmins(
                    'workflow_all_tasks_done',
                    'All Setup Tasks Complete',
                    "All setup tasks for '{$displayName}' are completed. The employee is ready.",
                    route('admin.workflows.show', $workflow->id),
                    'success'
                );
            }
        }

        return back()->with('success', 'Task marked as completed.');
    }

    // ─────────────────────────────────────────────────────────────
    // Assign a device (asset) to the employee created by this workflow.
    // Post-provisioning manual step — visible on the workflow show page.
    // ─────────────────────────────────────────────────────────────
    public function assignDevice(Request $request, int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);

        $employeeId = $workflow->payload['employee_id'] ?? null;
        if (! $employeeId) {
            return back()->with('error', 'No employee is linked to this workflow yet — provisioning must finish first.');
        }
        $employee = Employee::find($employeeId);
        if (! $employee) {
            return back()->with('error', 'The employee for this workflow could not be found.');
        }

        $validated = $request->validate([
            'asset_id'  => 'required|exists:devices,id',
            'condition' => 'required|in:good,fair,poor',
            'notes'     => 'nullable|string|max:500',
        ]);

        // Guard: not already actively assigned to someone else
        $active = EmployeeAsset::where('asset_id', $validated['asset_id'])
            ->whereNull('returned_date')
            ->first();
        if ($active) {
            $assignedTo = $active->employee?->name ?? 'another employee';
            return back()->with('error', "This device is already assigned to {$assignedTo}.");
        }

        $device = Device::find($validated['asset_id']);

        \Illuminate\Support\Facades\DB::transaction(function () use ($employee, $validated) {
            EmployeeAsset::create([
                'employee_id'   => $employee->id,
                'asset_id'      => $validated['asset_id'],
                'assigned_date' => now()->toDateString(),
                'condition'     => $validated['condition'],
                'notes'         => $validated['notes'] ?? null,
            ]);
            Device::where('id', $validated['asset_id'])->update(['status' => 'assigned']);
        });

        $deviceLabel = trim(($device->type ?? '') . ' ' . ($device->name ?? $device->asset_code ?? "#{$device->id}"));
        $this->engine->logEvent($workflow, 'info',
            "Device assigned to {$employee->name}: {$deviceLabel} (by " . Auth::user()->name . ').');

        return back()->with('success', "Device '{$deviceLabel}' assigned to {$employee->name}.");
    }

    public function returnDevice(int $id, int $assignmentId)
    {
        $workflow = WorkflowRequest::findOrFail($id);

        $assignment = EmployeeAsset::find($assignmentId);
        if (! $assignment) {
            return back()->with('error', 'Assignment not found.');
        }
        if ($assignment->returned_date !== null) {
            return back()->with('error', 'This device has already been returned.');
        }
        if (($workflow->payload['employee_id'] ?? null) !== $assignment->employee_id) {
            return back()->with('error', 'This assignment does not belong to this workflow\'s employee.');
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($assignment) {
            $assignment->update([
                'returned_date' => now()->toDateString(),
            ]);
            Device::where('id', $assignment->asset_id)->update(['status' => 'available']);
        });

        $device  = Device::find($assignment->asset_id);
        $label   = $device ? trim(($device->type ?? '') . ' ' . ($device->name ?? $device->asset_code ?? "#{$device->id}")) : "#{$assignment->asset_id}";
        $empName = $assignment->employee?->name ?? 'employee';
        $this->engine->logEvent($workflow, 'info',
            "Device returned from {$empName}: {$label} (by " . Auth::user()->name . ').');

        return back()->with('success', "Device '{$label}' returned.");
    }

    // Retry a failed workflow execution (skips approval — already approved)
    public function retry(int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);

        if (!in_array($workflow->status, ['failed', 'completed'])) {
            return back()->with('error', 'Only failed or completed workflows can be retried.');
        }

        try {
            $this->engine->logEvent($workflow, 'info', 'Manual retry triggered by ' . Auth::user()->name . '.');
            $this->engine->executeWorkflow($workflow);
        } catch (\Throwable $e) {
            $this->engine->markFailed($workflow, $e->getMessage());
            return redirect()
                ->route('admin.workflows.show', $id)
                ->with('error', 'Retry failed: ' . $e->getMessage());
        }

        return redirect()
            ->route('admin.workflows.show', $id)
            ->with('success', 'Workflow retried successfully.');
    }
}
