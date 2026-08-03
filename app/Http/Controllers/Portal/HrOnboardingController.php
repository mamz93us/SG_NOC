<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\OnboardingManagerToken;
use App\Models\Setting;
use App\Models\WorkflowRequest;
use App\Services\Workflow\UserProvisioningService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HrOnboardingController extends Controller
{
    public function __construct(
        private WorkflowEngine $engine,
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
            'hr_reference' => 'nullable|string|max:100',
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

        if (! empty($validated['supervisor_id'])
            && (int) $validated['supervisor_id'] === (int) $validated['manager_id']) {
            return back()->withInput()->with(
                'error',
                'Manager and supervisor are the same person. Leave the supervisor blank if there is only one.'
            );
        }

        $manager = Employee::find($validated['manager_id']);

        if (! $manager?->email) {
            return back()->withInput()->with(
                'error',
                "{$manager?->name} has no work email on file, so the manager setup form cannot be sent. Pick someone else or ask IT to add their email."
            );
        }

        $supervisor = ! empty($validated['supervisor_id'])
            ? Employee::find($validated['supervisor_id'])
            : null;

        // Build payload — keep keys aligned with UserProvisioningService expectations
        $payload = [
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'upn_domain' => $validated['upn_domain'],
            // Drives gender-specific Azure group auto-assignment, and lands on
            // the employee record for the gendered signature templates.
            'gender' => $validated['gender'],
            'job_title' => $validated['job_title'] ?? null,
            'department_id' => $validated['department_id'] ?? null,
            'mobile_phone' => $validated['mobile_phone'] ?? null,
            // Keep both keys: suggested_start_date (HR label) + start_date (provisioning uses this)
            'suggested_start_date' => $validated['suggested_start_date'],
            'start_date' => $validated['suggested_start_date'],
            'hr_reference' => $validated['hr_reference'] ?? null,
            // manager_email drives the engine's manager-form gate; manager_id /
            // supervisor_id land on the Employee record at provisioning time.
            'manager_id' => $manager->id,
            'manager_email' => $manager->email,
            'manager_name' => $manager->name,
            'supervisor_id' => $supervisor?->id,
            'supervisor_email' => $supervisor?->email,
            'supervisor_name' => $supervisor?->name,
            'submitted_by_hr' => true,
            'hr_submitter_id' => Auth::id(),
            'hr_submitter_name' => Auth::user()->name ?? null,
        ];

        $title = 'Onboarding: '.trim($validated['first_name'].' '.$validated['last_name']);

        $workflow = $this->engine->createRequest(
            type: 'create_user',
            payload: $payload,
            branchId: $validated['branch_id'] ?? null,
            requestedBy: Auth::id(),
            title: $title,
            description: $validated['description'] ?? null,
        );

        // Mirror admin behaviour: generate manager form token so it shows on workflow page.
        // Email dispatch happens after IT approval completes (handled by the engine).
        OnboardingManagerToken::generate($workflow->id, [
            'manager_email' => $manager->email,
            'manager_name' => $manager->name,
        ]);

        return redirect()
            ->route('portal.hr.onboarding.index')
            ->with('success', 'Onboarding request submitted. IT will review and approve.');
    }
}
