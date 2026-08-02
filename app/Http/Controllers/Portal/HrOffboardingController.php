<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OffboardingWorkflow;
use App\Models\Setting;
use App\Models\WorkflowRequest;
use App\Services\Workflow\OffboardingRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * HR-facing termination / offboarding requests.
 *
 * Delegates to OffboardingRequestService so a termination raised here is
 * byte-identical to one raised through POST /api/hr/offboarding — same
 * workflow, same Graph enrichment, same manager decision form.
 */
class HrOffboardingController extends Controller
{
    public function __construct(private OffboardingRequestService $offboarding) {}

    /**
     * GET /portal/hr/offboarding
     */
    public function index(): View
    {
        $requests = WorkflowRequest::with('branch')
            ->where('type', 'employee_offboarding')
            ->where('requested_by', Auth::id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Offboarding state rows carry the manager's decisions + lifecycle dates.
        $states = OffboardingWorkflow::whereIn('workflow_id', $requests->pluck('id'))
            ->get()
            ->keyBy('workflow_id');

        return view('portal.hr.offboarding.index', compact('requests', 'states'));
    }

    /**
     * GET /portal/hr/offboarding/create
     *
     * Two states: no employee chosen (just the picker), or an employee chosen
     * (?employee=123) — in which case their record is rendered read-only and HR
     * only supplies the termination details. Everything about the person is
     * derived from the record, never retyped.
     */
    public function create(Request $request): View
    {
        $settings = Setting::get();

        $employee = $request->filled('employee')
            ? Employee::with(['branch', 'department', 'manager', 'supervisor'])
                ->find($request->query('employee'))
            : null;

        // Counts only — the manager's decision form is where individual assets
        // are actually itemised and chosen. This is just so HR can see there is
        // kit to recover before raising the request.
        $assetCount = $employee
            ? $employee->activeAssets()->count() + $employee->activeItems()->count()
            : 0;

        // The decision form is emailed to the manager, so a missing manager is a
        // blocker, not a cosmetic gap. The view asks HR to pick one in that case.
        $managerMissing = $employee && ! $employee->manager?->email;

        return view('portal.hr.offboarding.create', compact(
            'settings', 'employee', 'assetCount', 'managerMissing'
        ));
    }

    /**
     * POST /portal/hr/offboarding
     */
    public function store(Request $request): RedirectResponse
    {
        // HR only supplies termination details. Identity, branch and the
        // reporting line all come off the employee record — nothing about the
        // person is retyped, so nothing about them can be mistyped.
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'last_day' => 'required|date|after_or_equal:today',
            'reason' => 'nullable|string|max:100',
            'hr_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            // Only used when the employee has no manager on file, or HR
            // deliberately overrides who receives the decision form.
            'manager_override_id' => 'nullable|integer|exists:employees,id',
        ], [
            'employee_id.required' => 'Choose the employee from the search box first.',
        ]);

        $employee = Employee::with('manager')->findOrFail($validated['employee_id']);

        if (! $employee->email) {
            return back()->withInput()->with(
                'error',
                "{$employee->name} has no work email on file, so there is no mailbox to offboard. Ask IT to fix the record first."
            );
        }

        $manager = ! empty($validated['manager_override_id'])
            ? Employee::find($validated['manager_override_id'])
            : $employee->manager;

        if (! $manager?->email) {
            return back()->withInput()->with(
                'error',
                'This employee has no manager with a work email on file. Pick who should receive the decision form.'
            );
        }

        if ($manager->id === $employee->id) {
            return back()->withInput()->with(
                'error',
                'The leaver cannot be their own approver — pick a different manager for the decision form.'
            );
        }

        try {
            $offboardingWorkflow = $this->offboarding->create([
                'employee_id' => $employee->id,
                'upn' => $employee->email,
                'employee_name' => $employee->name,
                'branch_id' => $employee->branch_id,
                'manager_email' => $manager->email,
                'manager_name' => $manager->name,
                'last_day' => $validated['last_day'],
                'reason' => $validated['reason'] ?? null,
                'hr_reference' => $validated['hr_reference'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ], requestedBy: Auth::id(), source: 'hr_portal');
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('portal.hr.offboarding.index')
            ->with('success', "Termination request #{$offboardingWorkflow->workflow_id} raised. "
                .'The manager has been emailed the decision form — offboarding starts once they respond.');
    }
}
