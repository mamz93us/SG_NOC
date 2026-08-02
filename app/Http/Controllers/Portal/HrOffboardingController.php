<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Branch;
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
     */
    public function create(Request $request): View
    {
        $branches = Branch::orderBy('name')->get();
        $settings = Setting::get();

        // Deep link from the employee picker / directory: ?employee=123
        $employee = $request->filled('employee')
            ? Employee::with(['branch', 'department', 'manager'])->find($request->query('employee'))
            : null;

        return view('portal.hr.offboarding.create', compact('branches', 'settings', 'employee'));
    }

    /**
     * POST /portal/hr/offboarding
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'upn' => 'nullable|email|max:200',
            'employee_name' => 'required|string|max:200',
            'last_day' => 'required|date|after_or_equal:today',
            'reason' => 'nullable|string|max:100',
            'manager_email' => 'required|email|max:200',
            'manager_name' => 'nullable|string|max:200',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'hr_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
        ]);

        if (empty($validated['employee_id']) && empty($validated['upn'])) {
            return back()
                ->withInput()
                ->with('error', 'Pick the employee from the search box, or enter their work email.');
        }

        try {
            $offboardingWorkflow = $this->offboarding->create(
                $validated,
                requestedBy: Auth::id(),
                source: 'hr_portal',
            );
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('portal.hr.offboarding.index')
            ->with('success', "Termination request #{$offboardingWorkflow->workflow_id} raised. "
                .'The manager has been emailed the decision form — offboarding starts once they respond.');
    }
}
