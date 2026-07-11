<?php

namespace App\Services\Identity;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Setting;
use App\Services\Workflow\ExtensionProvisioningService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Computes and applies Azure AD contact-info updates (officeLocation, city,
 * streetAddress, businessPhones) for existing employees, sourced from their
 * branch settings + extension.
 *
 * Mirrors the logic embedded in UserProvisioningService::createUser() steps
 * "Update Azure profile (branch-aware)" but for the bulk-sync admin flow.
 *
 * TODO: deduplicate with UserProvisioningService.php:358-410 in a follow-up
 *       PR once parity has been verified in production.
 */
class AzureContactSyncService
{
    public function __construct(
        private ExtensionProvisioningService $extProvisioning
    ) {}

    /**
     * Build the Graph PATCH payload for one employee, using their branch
     * settings + extension. Only includes keys for which we have data — the
     * caller can decide whether to send empty values to clear fields.
     *
     * @return array{officeLocation?: string, city?: string, streetAddress?: string, businessPhones?: array<int, string>}
     */
    public function computeProposedFields(Employee $employee, Setting $settings): array
    {
        $data = [];

        // Oracle-sourced HR fields — NOC is the system of record for these.
        if (! empty($employee->job_title)) {
            $data['jobTitle'] = $employee->job_title;
        }
        $department = $employee->oracle_department ?: $employee->department?->name;
        if (! empty($department)) {
            $data['department'] = $department;
        }
        if (! empty($employee->mobile_phone)) {
            $data['mobilePhone'] = $employee->mobile_phone;
        }

        $branch = $employee->branch;
        if (! $branch) {
            // No branch → can't derive office/city/phone, but the HR fields above
            // are still valid to push.
            return $data;
        }

        // City: branch-level field (NEW). No fallback to branch->name — admins
        // must populate the field deliberately.
        if (! empty($branch->city)) {
            $data['city'] = $branch->city;
        }

        // Street: branch-level field (NEW). Same: deliberate population only.
        if (! empty($branch->street)) {
            $data['streetAddress'] = $branch->street;
        }

        // Office + business-phone are template-driven, falling back to
        // branch-level defaults. Render via the existing helper.
        $officeTemplate = $branch->effectiveOfficeTemplate($settings);
        $phoneTemplate = $branch->effectivePhoneTemplate($settings);

        $extension = (string) ($employee->extension_number ?? '');

        // Derive first/last from employee.name for template variables.
        [$firstName, $lastName] = $this->splitName((string) $employee->name);

        $upn = (string) ($employee->identityUser?->user_principal_name
            ?? $employee->email
            ?? '');

        $rendered = $this->extProvisioning->buildProfileFields(
            $branch,
            $extension,
            $firstName,
            $lastName,
            $upn,
            [
                'officeLocation' => $officeTemplate,
                'phone' => $phoneTemplate,
            ]
        );

        if (! empty($rendered['officeLocation'])) {
            $data['officeLocation'] = $rendered['officeLocation'];
        } elseif (! empty($branch->name)) {
            $data['officeLocation'] = $branch->name;
        }

        if (! empty($rendered['phone'])) {
            $data['businessPhones'] = [$rendered['phone']];
        } elseif (! empty($branch->phone_number) && $extension !== '') {
            $data['businessPhones'] = [trim($branch->phone_number).' EXT '.$extension];
        } elseif (! empty($branch->phone_number)) {
            $data['businessPhones'] = [trim($branch->phone_number)];
        }

        return $data;
    }

    /**
     * Build the Graph PATCH payload from the employee's OWN profile fields.
     * Used when the NOC employee profile is the source of truth (per-employee
     * contact data), rather than deriving location from branch templates.
     *
     * @return array<string, mixed>
     */
    public function computeFromEmployee(Employee $employee): array
    {
        $data = [];

        if (! empty($employee->job_title))       { $data['jobTitle']       = $employee->job_title; }
        $department = $employee->oracle_department ?: $employee->department?->name;
        if (! empty($department))                { $data['department']     = $department; }
        if (! empty($employee->company))         { $data['companyName']    = $employee->company; }
        if (! empty($employee->mobile_phone))    { $data['mobilePhone']    = $employee->mobile_phone; }
        if (! empty($employee->work_phone))      { $data['businessPhones'] = [$employee->work_phone]; }
        if (! empty($employee->office_location)) { $data['officeLocation'] = $employee->office_location; }
        if (! empty($employee->city))            { $data['city']           = $employee->city; }
        if (! empty($employee->street_address))  { $data['streetAddress']  = $employee->street_address; }

        return $data;
    }

    /**
     * Per-field comparison between proposed Graph payload and the current
     * cached IdentityUser row.
     *
     * Note: businessPhones is array-shaped in Graph but stored as a string
     * (phone_number) in identity_users. We compare proposed[0] vs the string.
     *
     * @param  array  $proposed  Output of computeProposedFields()
     * @return array<int, array{field: string, current: ?string, proposed: ?string, changed: bool}>
     */
    public function diffAgainstIdentityUser(Employee $employee, ?IdentityUser $user, array $proposed): array
    {
        $rows = [];

        $current = [
            'officeLocation' => $user?->office_location,
            'city' => $user?->city,
            'streetAddress' => $user?->street_address,
            'businessPhones' => $user?->phone_number,
            'jobTitle' => $user?->job_title,
            'department' => $user?->department,
            'mobilePhone' => $user?->mobile_phone,
        ];

        foreach (['officeLocation', 'city', 'streetAddress', 'businessPhones', 'jobTitle', 'department', 'mobilePhone'] as $field) {
            $proposedVal = $proposed[$field] ?? null;
            if ($field === 'businessPhones') {
                $proposedVal = is_array($proposedVal) ? ($proposedVal[0] ?? null) : $proposedVal;
            }

            $currentVal = $current[$field];

            $rows[] = [
                'field' => $field,
                'current' => $currentVal !== null && $currentVal !== '' ? (string) $currentVal : null,
                'proposed' => $proposedVal !== null && $proposedVal !== '' ? (string) $proposedVal : null,
                'changed' => (string) ($currentVal ?? '') !== (string) ($proposedVal ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * PATCH Azure AD then mirror the change locally and write an activity
     * log. Throws on Graph failure; mirror+log are wrapped in a transaction
     * and a mirror failure does not roll back the Graph change (Graph is
     * the source of truth — next IdentitySync reconciles).
     */
    public function applyToEmployee(Employee $employee, array $proposed): void
    {
        if ($proposed === []) {
            return;
        }

        if (empty($employee->azure_id)) {
            throw new \RuntimeException("Employee #{$employee->id} has no azure_id");
        }

        $graph = new GraphService;
        $graph->updateUser($employee->azure_id, $proposed);

        // Mirror locally — Graph already accepted, so any DB failure here
        // leaves Graph correct and local stale (next sync reconciles).
        try {
            DB::transaction(function () use ($employee, $proposed) {
                $user = IdentityUser::where('azure_id', $employee->azure_id)->first();
                if ($user) {
                    $update = [];
                    if (array_key_exists('officeLocation', $proposed)) {
                        $update['office_location'] = $proposed['officeLocation'];
                    }
                    if (array_key_exists('city', $proposed)) {
                        $update['city'] = $proposed['city'];
                    }
                    if (array_key_exists('streetAddress', $proposed)) {
                        $update['street_address'] = $proposed['streetAddress'];
                    }
                    if (array_key_exists('businessPhones', $proposed)) {
                        $update['phone_number'] = $proposed['businessPhones'][0] ?? null;
                    }
                    if (array_key_exists('jobTitle', $proposed)) {
                        $update['job_title'] = $proposed['jobTitle'];
                    }
                    if (array_key_exists('department', $proposed)) {
                        $update['department'] = $proposed['department'];
                    }
                    if (array_key_exists('mobilePhone', $proposed)) {
                        $update['mobile_phone'] = $proposed['mobilePhone'];
                    }
                    if (array_key_exists('companyName', $proposed)) {
                        $update['company_name'] = $proposed['companyName'];
                    }
                    if ($update !== []) {
                        $user->update($update);
                    }
                }

                ActivityLog::create([
                    'model_type' => 'IdentityUser',
                    'model_id' => $user?->id ?? 0,
                    'action' => 'azure_contact_synced',
                    'changes' => [
                        'employee_id' => $employee->id,
                        'azure_id' => $employee->azure_id,
                        'fields' => array_keys($proposed),
                        'proposed' => $proposed,
                    ],
                    'user_id' => Auth::id(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::warning('AzureContactSyncService: Graph PATCH succeeded but local mirror failed', [
                'employee_id' => $employee->id,
                'azure_id' => $employee->azure_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{0: string, 1: string} [firstName, lastName]
     */
    private function splitName(string $fullName): array
    {
        $parts = preg_split('/\s+/', trim($fullName), 2) ?: [];

        return [
            (string) ($parts[0] ?? ''),
            (string) ($parts[1] ?? ''),
        ];
    }
}
