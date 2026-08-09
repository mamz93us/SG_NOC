<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Services\Workflow\OnboardingRequestService;
use App\Services\Workflow\UserProvisioningService;
use App\Support\HrLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/hr/onboarding — raise a new-starter request.
 *
 * Field-for-field the same request the HR portal onboarding form raises: both
 * go through OnboardingRequestService, so approval, provisioning and the
 * manager setup form behave identically whichever surface was used.
 *
 * The one difference is identifier resolution. The portal has pickers that post
 * NOC ids; the HR system does not know them, so every reference here can be
 * given as an id OR as something HR already holds (Oracle employee number,
 * work email, branch/department name).
 */
class HrOnboardingController extends Controller
{
    public function __construct(
        private OnboardingRequestService $onboarding,
        private UserProvisioningService $provisioning,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'gender' => 'required|in:male,female',
            'upn_domain' => 'nullable|string|max:255',
            'job_title' => 'nullable|string|max:255',
            'mobile_phone' => 'nullable|string|max:50',
            'start_date' => 'nullable|date',
            'suggested_start_date' => 'nullable|date',

            // The Oracle employee number. hr_reference is kept as an alias for
            // callers written against the old field name.
            'oracle_emp_no' => 'nullable|string|max:50',
            'hr_reference' => 'nullable|string|max:50',

            // Reporting line — give any one of the three per person.
            'manager_id' => 'nullable|integer',
            'manager_email' => 'nullable|email|max:200',
            'manager_oracle_emp_no' => 'nullable|string|max:50',
            'supervisor_id' => 'nullable|integer',
            'supervisor_email' => 'nullable|email|max:200',
            'supervisor_oracle_emp_no' => 'nullable|string|max:50',

            // Branch / department — id or name.
            'branch_id' => 'nullable|integer',
            'branch' => 'nullable|string|max:150',
            'department_id' => 'nullable|integer',
            'department' => 'nullable|string|max:150',

            'notes' => 'nullable|string|max:2000',
            'description' => 'nullable|string|max:2000',
        ]);

        // ── Resolve the reporting line ──────────────────────────────────────
        $manager = HrLookup::employee(
            id: $data['manager_id'] ?? null,
            oracleEmpNo: $data['manager_oracle_emp_no'] ?? null,
            email: $data['manager_email'] ?? null,
        );

        if (! $manager) {
            return $this->fail(
                'Could not resolve the reporting manager. Send manager_id, manager_email or manager_oracle_emp_no.',
                ['field' => 'manager']
            );
        }

        $supervisor = HrLookup::employee(
            id: $data['supervisor_id'] ?? null,
            oracleEmpNo: $data['supervisor_oracle_emp_no'] ?? null,
            email: $data['supervisor_email'] ?? null,
        );

        // ── Resolve branch / department ─────────────────────────────────────
        $branch = HrLookup::branch($data['branch_id'] ?? null, $data['branch'] ?? null);
        $department = HrLookup::department($data['department_id'] ?? null, $data['department'] ?? null);

        if (! empty($data['branch']) && ! $branch) {
            return $this->fail("Unknown branch \"{$data['branch']}\". Call GET /api/hr/reference-data for the list.", ['field' => 'branch']);
        }
        if (! empty($data['department']) && ! $department) {
            return $this->fail("Unknown department \"{$data['department']}\". Call GET /api/hr/reference-data for the list.", ['field' => 'department']);
        }

        // ── Defaults that the form supplies from its own UI ─────────────────
        $startDate = $data['suggested_start_date'] ?? $data['start_date'] ?? null;

        if (! $startDate) {
            return $this->fail('start_date is required.', ['field' => 'start_date']);
        }

        $upnDomain = $data['upn_domain'] ?? AllowedDomain::orderByDesc('is_primary')->value('domain');

        if (! $upnDomain) {
            return $this->fail('upn_domain is required — no primary allowed domain is configured in NOC.', ['field' => 'upn_domain']);
        }

        try {
            $workflow = $this->onboarding->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'upn_domain' => $upnDomain,
                'gender' => $data['gender'],
                'job_title' => $data['job_title'] ?? null,
                'department_id' => $department?->id,
                'mobile_phone' => $data['mobile_phone'] ?? null,
                'suggested_start_date' => $startDate,
                'oracle_emp_no' => $data['oracle_emp_no'] ?? $data['hr_reference'] ?? null,
                'manager_id' => $manager->id,
                'supervisor_id' => $supervisor?->id,
                'branch_id' => $branch?->id ?? $manager->branch_id,
                'description' => $data['description'] ?? $data['notes'] ?? null,
            ], requestedBy: null, source: 'hr_api');
        } catch (\RuntimeException $e) {
            return $this->fail($e->getMessage());
        }

        // Same preview the form shows HR before they submit, so the caller can
        // record the address that will be created without waiting for approval.
        $preview = $this->provisioning->previewIdentity(
            $data['first_name'],
            $data['last_name'],
            $upnDomain,
        );

        return response()->json([
            'ok' => true,
            'workflow_id' => $workflow->id,
            'status' => $workflow->status,
            'display_name' => $workflow->payload['display_name'] ?? null,
            'oracle_emp_no' => $workflow->payload['oracle_emp_no'] ?? null,
            'proposed_upn' => $preview['upn'] ?? null,
            'branch_id' => $workflow->branch_id,
            'department_id' => $department?->id,
            'manager' => ['id' => $manager->id, 'name' => $manager->name, 'email' => $manager->email],
            'supervisor' => $supervisor ? ['id' => $supervisor->id, 'name' => $supervisor->name] : null,
            'message' => 'Onboarding request created. IT will review it; the manager setup form is sent after approval.',
        ], 201);
    }

    /**
     * GET /api/hr/onboarding/check-availability
     *
     * The same live check the portal form runs as HR types, so an integration
     * can confirm the address before committing to a request.
     */
    public function checkAvailability(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'upn_domain' => 'nullable|string|max:255',
        ]);

        $domain = $data['upn_domain'] ?? AllowedDomain::orderByDesc('is_primary')->value('domain');

        if (! $domain) {
            return $this->fail('upn_domain is required — no primary allowed domain is configured in NOC.');
        }

        return response()->json([
            'ok' => true,
        ] + $this->provisioning->previewIdentity($data['first_name'], $data['last_name'], $domain));
    }

    private function fail(string $message, array $extra = [], int $status = 422): JsonResponse
    {
        return response()->json(['ok' => false, 'message' => $message] + $extra, $status);
    }
}
