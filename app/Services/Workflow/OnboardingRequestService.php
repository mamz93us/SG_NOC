<?php

namespace App\Services\Workflow;

use App\Models\Employee;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowRequest;

/**
 * Single entry point for raising an onboarding (create user) request.
 *
 * Both the HR portal form (Portal\HrOnboardingController) and the HR API
 * (Api\HrOnboardingController) call this, so a new starter raised from either
 * surface produces exactly the same artefacts:
 *
 *   1. WorkflowRequest (type=create_user) with the payload keys
 *      UserProvisioningService expects
 *   2. OnboardingManagerToken so the manager setup form link exists straight away
 *
 * The manager email is NOT sent here — WorkflowEngine dispatches it once IT has
 * approved, which is the behaviour the portal has always had.
 *
 * Same shape as OffboardingRequestService: business rules throw
 * \RuntimeException with a message that is safe to show a user or return as a
 * 422 body.
 */
class OnboardingRequestService
{
    public function __construct(private WorkflowEngine $engine) {}

    /**
     * @param  array{first_name:string, last_name:string, upn_domain:string,
     *               gender:string, job_title?:string|null, department_id?:int|null,
     *               mobile_phone?:string|null, suggested_start_date:string,
     *               oracle_emp_no?:string|null, manager_id:int,
     *               supervisor_id?:int|null, branch_id?:int|null,
     *               description?:string|null}  $data
     *
     * @throws \RuntimeException on a business-rule violation
     */
    public function create(array $data, ?int $requestedBy = null, string $source = 'hr_portal'): WorkflowRequest
    {
        $manager = Employee::find($data['manager_id'] ?? null);

        if (! $manager) {
            throw new \RuntimeException('The reporting manager could not be found in the employee directory.');
        }

        if (! $manager->email) {
            throw new \RuntimeException(
                "{$manager->name} has no work email on file, so the manager setup form cannot be sent. "
                .'Pick someone else or ask IT to add their email.'
            );
        }

        $supervisor = ! empty($data['supervisor_id'])
            ? Employee::find($data['supervisor_id'])
            : null;

        if ($supervisor && $supervisor->id === $manager->id) {
            throw new \RuntimeException(
                'Manager and supervisor are the same person. Leave the supervisor blank if there is only one.'
            );
        }

        $firstName = trim($data['first_name']);
        $lastName = trim($data['last_name']);
        $displayName = trim($firstName.' '.$lastName);
        $startDate = $data['suggested_start_date'];

        // The Oracle employee number is the reference HR and Finance both key
        // off. Mirrored into hr_reference so the emails, the manager forms and
        // the workflow views that already read that key keep working.
        $oracleEmpNo = $this->normaliseOracleNumber($data['oracle_emp_no'] ?? $data['hr_reference'] ?? null);

        $payload = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'display_name' => $displayName,
            'upn_domain' => $data['upn_domain'],
            // Drives gender-specific Azure group auto-assignment, and lands on
            // the employee record for the gendered signature templates.
            'gender' => $data['gender'],
            'job_title' => $data['job_title'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'mobile_phone' => $data['mobile_phone'] ?? null,
            // Keep both keys: suggested_start_date (HR label) + start_date
            // (what UserProvisioningService reads).
            'suggested_start_date' => $startDate,
            'start_date' => $startDate,
            'oracle_emp_no' => $oracleEmpNo,
            'hr_reference' => $oracleEmpNo,
            // manager_email drives the engine's manager-form gate; manager_id /
            // supervisor_id land on the Employee record at provisioning time.
            'manager_id' => $manager->id,
            'manager_email' => $manager->email,
            'manager_name' => $manager->name,
            'supervisor_id' => $supervisor?->id,
            'supervisor_email' => $supervisor?->email,
            'supervisor_name' => $supervisor?->name,
            'source' => $source,
            'submitted_by_hr' => true,
            'hr_submitter_id' => $requestedBy,
        ];

        $workflow = $this->engine->createRequest(
            type: 'create_user',
            payload: $payload,
            branchId: $data['branch_id'] ?? null,
            requestedBy: $requestedBy,
            title: "Onboarding: {$displayName}",
            description: $data['description'] ?? null,
        );

        // Generated now so the form link shows on the workflow page. The email
        // itself goes out after IT approval, dispatched by the engine.
        OnboardingManagerToken::generate($workflow->id, [
            'manager_email' => $manager->email,
            'manager_name' => $manager->name,
        ]);

        $this->engine->logEvent(
            $workflow,
            'info',
            "Onboarding raised via {$source} for {$displayName}. Manager setup form will go to {$manager->email} after approval."
        );

        return $workflow;
    }

    /**
     * Oracle exports the employee number with leading zeros and stray spaces in
     * places. Store it the way `employees:sync-hr-list` matches on it.
     */
    private function normaliseOracleNumber(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
