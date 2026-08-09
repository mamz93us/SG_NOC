<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Setting;
use App\Models\WorkflowRequest;
use App\Services\Workflow\OnboardingRequestService;
use App\Services\Workflow\UserProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HrOnboardingController extends Controller
{
    public function __construct(
        private OnboardingRequestService $onboarding,
        private UserProvisioningService $provisioning,
    ) {}

    /**
     * GET /portal/hr/onboarding
     * List HR's previously submitted onboarding requests.
     */
    public function index(): View
    {
        $requests = WorkflowRequest::where('type', 'create_user')
            ->where('requested_by', Auth::id())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('portal.hr_onboarding.index', compact('requests'));
    }

    /**
     * GET /portal/hr/onboarding/create
     * Show the HR onboarding form.
     */
    public function create(): View
    {
        $branches = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get(['id', 'name']);
        $settings = Setting::get();
        $upnDomains = AllowedDomain::orderByDesc('is_primary')->orderBy('domain')->get();

        return view('portal.hr_onboarding.create', compact(
            'branches', 'departments', 'settings', 'upnDomains'
        ));
    }

    /**
     * GET /portal/hr/onboarding/check-availability?first_name=&last_name=&upn_domain=
     *
     * Real availability check for the form's live preview. Delegates to
     * UserProvisioningService::previewIdentity() — the same buildUPN() the
     * actual provisioning run uses — so what HR sees is what gets created,
     * including the numeric suffix applied on a collision.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'upn_domain' => 'required|string|max:255',
        ]);

        $preview = $this->provisioning->previewIdentity(
            $data['first_name'],
            $data['last_name'],
            $data['upn_domain'],
        );

        return response()->json($preview);
    }

    /**
     * POST /portal/hr/onboarding
     * Submit a new create_user workflow from the HR portal.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'upn_domain' => 'required|string|max:255',
            'gender' => 'required|in:male,female',
            'job_title' => 'nullable|string|max:255',
            'department_id' => 'nullable|exists:departments,id',
            'mobile_phone' => 'nullable|string|max:50',
            'suggested_start_date' => 'required|date|after_or_equal:today',
            'oracle_emp_no' => 'nullable|string|max:50',
            // Manager is picked from the employee directory, so the decision
            // form is guaranteed to reach a real mailbox. The email/name are
            // resolved server-side from the id — never trusted from the post.
            'manager_id' => 'required|integer|exists:employees,id',
            'supervisor_id' => 'nullable|integer|exists:employees,id',
            'branch_id' => 'nullable|exists:branches,id',
            'description' => 'nullable|string|max:2000',
        ], [
            // The pickers post hidden ids, so the default "manager id field is
            // required" wording would be meaningless to an HR user.
            'gender.required' => 'Select a gender — it determines which Azure groups are assigned automatically.',
            'manager_id.required' => 'Choose the reporting manager from the employee list.',
            'manager_id.exists' => 'That manager is not an active employee — pick again from the list.',
            'supervisor_id.exists' => 'That supervisor is not an active employee — pick again from the list.',
        ]);

        try {
            $workflow = $this->onboarding->create($validated, requestedBy: Auth::id(), source: 'hr_portal');
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('portal.hr.onboarding.index')
            ->with('success', 'Onboarding request submitted. IT will review and approve.');
    }
}
