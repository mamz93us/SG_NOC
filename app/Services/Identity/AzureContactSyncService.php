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
     * True when a Graph failure is Entra refusing an app-only write to a
     * PROTECTED user — i.e. an account holding a privileged admin role. Even
     * with User.ReadWrite.All + Directory.ReadWrite.All, app-only principals
     * cannot modify admin users by design; this is not a missing-consent bug.
     */
    public static function isProtectedAdminError(\Throwable $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'Authorization_RequestDenied')
            || str_contains($m, 'Insufficient privileges');
    }

    /**
     * True when Graph 404s because the target user no longer exists in Entra —
     * a stale azure_id link (e.g. the employee was removed/terminated and their
     * Entra account deleted). Not a real sync failure; the account should be
     * skipped and the link reconciled.
     */
    public static function isMissingUserError(\Throwable $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'Request_ResourceNotFound')
            || str_contains($m, 'failed (404)');
    }

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

        // Dual-account users: a secondary account inherits job title / department /
        // extension / mobile from its linked primary (the SSS HR record). displayName,
        // branch, and location stay this account's own.
        $hr = $employee->hrSource();

        // Oracle-sourced HR fields — NOC is the system of record for these.
        if (! empty($employee->name)) {
            $data['displayName'] = $employee->name;
        }
        if (! empty($hr->job_title)) {
            $data['jobTitle'] = $hr->job_title;
        }
        $department = $hr->oracle_department ?: $hr->department?->name;
        if (! empty($department)) {
            $data['department'] = $department;
        }
        $data['mobilePhone'] = $this->resolveMobile($hr);
        // Extension carried in the fax field (read by the signature via %%FaxNumber%%).
        if (! empty($hr->extension_number)) {
            $data['faxNumber'] = (string) $hr->extension_number;
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

        // Business phone = clean office landline (no embedded extension — that goes to fax).
        if (! empty($branch->phone_number)) {
            $data['businessPhones'] = [trim($branch->phone_number)];
        } elseif (! empty($rendered['phone'])) {
            $data['businessPhones'] = [trim(preg_replace('/\s*(?:ext\.?|x)\s*\d+\s*$/i', '', $rendered['phone']))];
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

        // Secondary accounts inherit the HR fields from their linked primary; name,
        // company, and location stay their own. See computeProposedFields().
        $hr = $employee->hrSource();

        if (! empty($employee->name))            { $data['displayName']    = $employee->name; }
        if (! empty($hr->job_title))             { $data['jobTitle']       = $hr->job_title; }
        $department = $hr->oracle_department ?: $hr->department?->name;
        if (! empty($department))                { $data['department']     = $department; }
        if (! empty($employee->company))         { $data['companyName']    = $employee->company; }
        $data['mobilePhone'] = $this->resolveMobile($hr);
        // Business phone = the office landline (employee work_phone, else branch phone),
        // with any embedded "EXT ###" stripped — the extension is carried separately in fax.
        $officePhone = $employee->work_phone ?: $employee->branch?->phone_number;
        if (! empty($officePhone)) {
            $data['businessPhones'] = [trim(preg_replace('/\s*(?:ext\.?|x)\s*\d+\s*$/i', '', $officePhone))];
        }
        // Extension has no native Azure field, so we carry it in the (unused) fax field —
        // the signature transport rule reads it via %%FaxNumber%%.
        if (! empty($hr->extension_number))      { $data['faxNumber']     = (string) $hr->extension_number; }
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
                    if (array_key_exists('displayName', $proposed)) {
                        $update['display_name'] = $proposed['displayName'];
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
     * The mobilePhone value to push for an employee:
     *   1. NOC's own HR mobile if set (system of record),
     *   2. else the number already in Azure (mirrored on identity_users) — so we only
     *      REFORMAT an existing number and never wipe a real one,
     *   3. else a literal "-" so the field is explicitly cleared rather than left stale
     *      (Graph never removes a key we omit — that was the "mobile can't be deleted" bug).
     * Any resolved number is normalised to the canonical Saudi form "+966 5X XXX XXXX".
     */
    private function resolveMobile(Employee $source): string
    {
        $raw = $source->mobile_phone ?: $source->identityUser?->mobile_phone;
        $normalized = $this->normalizeSaudiMobile($raw);

        return $normalized !== '' ? $normalized : '-';
    }

    /**
     * Normalise a Saudi mobile number to "+966 5X XXX XXXX". Accepts the common messy
     * inputs (05xxxxxxxx, 5xxxxxxxx, 9665xxxxxxxx, 009665xxxxxxxx, +966 5x xxx xxxx,
     * with spaces/dashes). A number that is already an explicit FOREIGN country code
     * (e.g. +20 Egypt) or that doesn't match the Saudi mobile shape is returned as-is,
     * so a real non-Saudi number is never mangled. Empty / "-" input returns "".
     */
    private function normalizeSaudiMobile(?string $raw): string
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '-') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        // Explicit non-Saudi country code (has a leading + and isn't 966) — leave untouched.
        if (str_starts_with($raw, '+') && ! str_starts_with($digits, '966')) {
            return $raw;
        }

        // Reduce the various Saudi prefixes to the 9-digit national form (5XXXXXXXX).
        if (str_starts_with($digits, '00966')) {
            $digits = substr($digits, 5);
        } elseif (str_starts_with($digits, '966')) {
            $digits = substr($digits, 3);
        }
        $digits = ltrim($digits, '0');

        if (preg_match('/^5\d{8}$/', $digits)) {
            return sprintf('+966 %s %s %s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5)
            );
        }

        // Not a recognisable Saudi mobile — return the original so we never corrupt it.
        return $raw;
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
