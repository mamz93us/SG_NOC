<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\Branch;
use App\Models\Department;
use App\Models\OnboardingManagerToken;
use App\Models\Setting;
use App\Models\WorkflowRequest;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class HrOnboardingController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

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
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get(['id', 'name']);
        $settings    = Setting::get();
        $upnDomains  = AllowedDomain::orderByDesc('is_primary')->orderBy('domain')->get();

        return view('portal.hr_onboarding.create', compact(
            'branches', 'departments', 'settings', 'upnDomains'
        ));
    }

    /**
     * POST /portal/hr/onboarding
     * Submit a new create_user workflow from the HR portal.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'upn_domain'       => 'required|string|max:255',
            'job_title'        => 'nullable|string|max:255',
            'department_id'    => 'nullable|exists:departments,id',
            'mobile_phone'     => 'nullable|string|max:50',
            'initial_password' => 'nullable|string|min:8|max:100',
            'suggested_start_date' => 'required|date|after_or_equal:today',
            'hr_reference'     => 'nullable|string|max:100',
            'manager_email'    => 'required|email|max:255',
            'manager_name'     => 'nullable|string|max:150',
            'branch_id'        => 'nullable|exists:branches,id',
            'description'      => 'nullable|string|max:2000',
        ]);

        // Build payload — keep keys aligned with UserProvisioningService expectations
        $payload = [
            'first_name'       => $validated['first_name'],
            'last_name'        => $validated['last_name'],
            'upn_domain'       => $validated['upn_domain'],
            'job_title'        => $validated['job_title'] ?? null,
            'department_id'    => $validated['department_id'] ?? null,
            'mobile_phone'     => $validated['mobile_phone'] ?? null,
            'initial_password' => $validated['initial_password'] ?? null,
            // Keep both keys: suggested_start_date (HR label) + start_date (provisioning uses this)
            'suggested_start_date' => $validated['suggested_start_date'],
            'start_date'       => $validated['suggested_start_date'],
            'hr_reference'     => $validated['hr_reference'] ?? null,
            'manager_email'    => $validated['manager_email'],
            'manager_name'     => $validated['manager_name'] ?? null,
            'submitted_by_hr'  => true,
            'hr_submitter_id'  => Auth::id(),
            'hr_submitter_name'=> Auth::user()->name ?? null,
        ];

        $title = 'Onboarding: ' . trim($validated['first_name'] . ' ' . $validated['last_name']);

        $workflow = $this->engine->createRequest(
            type:        'create_user',
            payload:     $payload,
            branchId:    $validated['branch_id'] ?? null,
            requestedBy: Auth::id(),
            title:       $title,
            description: $validated['description'] ?? null,
        );

        // Mirror admin behaviour: generate manager form token so it shows on workflow page.
        // Email dispatch happens after IT approval completes (handled by the engine).
        $managerEmail = $payload['manager_email'];
        $managerName  = $payload['manager_name']
            ?: ucfirst(explode('.', explode('@', $managerEmail)[0])[0] ?? 'Manager');
        OnboardingManagerToken::generate($workflow->id, [
            'manager_email' => $managerEmail,
            'manager_name'  => $managerName,
        ]);

        return redirect()
            ->route('portal.hr.onboarding.index')
            ->with('success', 'Onboarding request submitted. IT will review and approve.');
    }
}
