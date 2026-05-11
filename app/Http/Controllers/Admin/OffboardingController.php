<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Azure\DeleteAzureUserJob;
use App\Jobs\SendOffboardingManagerRequestJob;
use App\Models\OffboardingToken;
use App\Models\OffboardingWorkflow;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OffboardingController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * GET /admin/offboarding
     * Index of all offboarding workflows (active + historical).
     */
    public function index(Request $request): View
    {
        $status = $request->query('status'); // optional filter

        $rows = OffboardingWorkflow::query()
            ->with(['employee', 'workflow', 'backups'])
            ->when($status, fn($q) => $q->where('status', $status))
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.offboarding.index', [
            'rows'   => $rows,
            'status' => $status,
        ]);
    }

    /**
     * GET /admin/offboarding/{offboardingWorkflow}
     * Detail page with manager decisions, backup links, action buttons.
     */
    public function show(OffboardingWorkflow $offboardingWorkflow): View
    {
        $offboardingWorkflow->load([
            'workflow',
            'employee.activeAssets.device',
            'assetTarget',
            'backups' => fn($q) => $q->orderBy('type'),
            'token',
        ]);

        return view('admin.offboarding.show', [
            'ow' => $offboardingWorkflow,
        ]);
    }

    /**
     * POST /admin/offboarding/{offboardingWorkflow}/resend
     * Resend the manager email (same token if still valid).
     */
    public function resendManagerEmail(OffboardingWorkflow $offboardingWorkflow)
    {
        $token = $offboardingWorkflow->token;
        if (! $token || ! $token->isValid()) {
            return back()->with('error', 'No valid token to resend (expired or used).');
        }

        SendOffboardingManagerRequestJob::dispatch($offboardingWorkflow->workflow_id, $token->token)
            ->onQueue('emails');

        $this->engine->logEvent($offboardingWorkflow->workflow, 'info', 'Manager email resent by admin.');

        return back()->with('success', "Manager email resent to {$token->manager_email}.");
    }

    /**
     * POST /admin/offboarding/{offboardingWorkflow}/cancel
     * Cancel an in-flight offboarding. Does NOT undo actions already taken.
     */
    public function cancel(OffboardingWorkflow $offboardingWorkflow, Request $request)
    {
        $request->validate(['reason' => 'required|string|max:500']);

        $offboardingWorkflow->update([
            'status'       => 'cancelled',
            'completed_at' => now(),
        ]);

        $workflow = $offboardingWorkflow->workflow;
        if ($workflow) {
            $workflow->update(['status' => 'cancelled']);
            $this->engine->logEvent($workflow, 'warning',
                'Offboarding cancelled by admin. Reason: ' . $request->input('reason'));
        }

        return redirect()
            ->route('admin.offboarding.index')
            ->with('success', 'Offboarding cancelled. Actions already taken are NOT undone.');
    }

    /**
     * POST /admin/offboarding/{offboardingWorkflow}/force-delete
     * Manually fire the final Azure DeleteUser. Gated on backups complete
     * AND delete_after window reached (or the admin explicitly forces).
     */
    public function forceDelete(OffboardingWorkflow $offboardingWorkflow, Request $request)
    {
        $request->validate(['confirm' => 'required|in:CONFIRM']);

        $employee = $offboardingWorkflow->employee;
        if (! $employee || ! $employee->azure_id) {
            return back()->with('error', 'Employee has no azure_id — nothing to delete.');
        }

        DeleteAzureUserJob::dispatch($employee->azure_id);

        $offboardingWorkflow->update([
            'azure_deleted_at' => now(),
            'status'           => 'completed',
            'completed_at'     => now(),
        ]);

        $this->engine->logEvent($offboardingWorkflow->workflow, 'warning',
            'Force-delete dispatched by admin (skipped retention window check).');

        return back()->with('success', 'Azure delete dispatched. Employee record preserved.');
    }
}
