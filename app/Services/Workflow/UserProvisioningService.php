<?php

namespace App\Services\Workflow;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\IdentityUser;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Models\WorkflowRequest;
use App\Services\Identity\GraphService;
use App\Services\NotificationService;
use Illuminate\Support\Str;

class UserProvisioningService
{
    public function __construct(
        private WorkflowEngine              $engine,
        private ExtensionProvisioningService $extProvisioning,
        private NotificationService          $notifications
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Provision new user (7 steps)
    // ─────────────────────────────────────────────────────────────

    public function provisionUser(WorkflowRequest $workflow): void
    {
        $payload  = $workflow->payload ?? [];
        $settings = Setting::get();

        $this->engine->logEvent($workflow, 'info', 'Starting user provisioning.');

        $firstName   = trim($payload['first_name']   ?? '');
        $lastName    = trim($payload['last_name']    ?? '');
        $displayName = trim("{$firstName} {$lastName}");
        $graph       = new GraphService();

        // ── Steps 0-2: Azure user (idempotent) ───────────────────
        // If a previous attempt already created the Azure user, payload['azure_id']
        // and payload['upn'] will be set — skip creation entirely and resume
        // from where we left off instead of getting a UPN conflict error.
        if (!empty($payload['azure_id']) && !empty($payload['upn'])) {
            $azureId = $payload['azure_id'];
            $upn     = $payload['upn'];
            $this->engine->logEvent($workflow, 'info', "Resuming — Azure user already created: {$upn} (ID: {$azureId})");
        } else {
            // ── Step 0: Duplicate name check ─────────────────────
            $this->engine->logEvent($workflow, 'info', "Checking for duplicate display name: {$displayName}");
            $existingUser = IdentityUser::where('display_name', $displayName)->first();
            if ($existingUser) {
                throw new \RuntimeException(
                    "User '{$displayName}' already exists in Azure (UPN: {$existingUser->user_principal_name})."
                );
            }

            // ── Step 1: Build UPN ─────────────────────────────────
            // Use domain chosen on the create-user form (payload['upn_domain']),
            // then fall back to the global default, then a hard-coded placeholder.
            $domain = trim($payload['upn_domain'] ?? $settings->upn_domain ?? 'example.com') ?: 'example.com';
            $upn    = $this->buildUPN($firstName, $lastName, $domain);
            $this->engine->logEvent($workflow, 'info', "Generated UPN: {$upn}");

            // ── Step 2: Create Azure user ─────────────────────────
            $this->engine->logEvent($workflow, 'info', 'Creating Azure AD user...');
            $password = $payload['initial_password'] ?? (Str::random(12) . '!1A');

            try {
                $azureUser = $graph->createUser([
                    'displayName'       => $displayName,
                    'userPrincipalName' => $upn,
                    'mailNickname'      => explode('@', $upn)[0],
                    'password'          => $password,
                    'usageLocation'     => 'EG',
                    'jobTitle'          => $payload['job_title']   ?? null,
                    'department'        => $payload['department']  ?? null,
                    'accountEnabled'    => true,
                ]);

                $azureId = $azureUser['id'] ?? null;
                if (! $azureId) {
                    throw new \RuntimeException('Azure user created but no ID returned.');
                }

                $this->engine->logEvent($workflow, 'success', "Azure user created: {$upn} (ID: {$azureId})");

                $payload = array_merge($payload, [
                    'upn'              => $upn,
                    'azure_id'         => $azureId,
                    'display_name'     => $displayName,
                    'initial_password' => $password,
                ]);
                $workflow->payload = $payload;
                $workflow->save();

            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to create Azure user: ' . $e->getMessage());
            }
        }

        // ── Step 3: Assign default license(s) ────────────────────
        // If licenses were already assigned in a previous attempt, skip entirely.
        // Priority: multi-sku array → legacy single sku → payload override
        $licenseSkus = $settings->graph_default_license_skus ?? [];
        if (empty($licenseSkus) && $settings->graph_default_license_sku) {
            $licenseSkus = [$settings->graph_default_license_sku];
        }
        // Allow payload to override (future: per-request license selection)
        if (!empty($payload['license_skus'])) {
            $licenseSkus = (array) $payload['license_skus'];
        } elseif (!empty($payload['license_sku']) && empty($licenseSkus)) {
            $licenseSkus = [$payload['license_sku']];
        }
        // Idempotency: skip licenses already recorded as assigned in a previous run
        $alreadyAssignedSkus = collect($payload['assigned_licenses'] ?? [])->pluck('sku')->toArray();
        $licenseSkus = array_values(array_filter($licenseSkus, fn($s) => !in_array($s, $alreadyAssignedSkus)));
        if (!empty($alreadyAssignedSkus) && empty($licenseSkus)) {
            $this->engine->logEvent($workflow, 'info', 'Licenses already assigned in previous attempt — skipping.');
        }

        if (!empty($licenseSkus)) {
            // Build a name map so we can log/store friendly names
            $skuNameMap = [];
            try {
                $skuNameMap = $graph->getSkuNameMap();
            } catch (\Throwable) {
                // Azure name lookup non-fatal
            }

            // Start with licenses already assigned in a previous attempt
            $assignedLicenses = $payload['assigned_licenses'] ?? [];
            $licenseIndex     = 0;
            foreach (array_filter($licenseSkus) as $sku) {
                $skuName = $skuNameMap[$sku] ?? $sku;

                // Space out consecutive assignments to avoid Azure ConcurrencyViolation
                if ($licenseIndex > 0) {
                    sleep(2);
                }
                $licenseIndex++;

                $this->engine->logEvent($workflow, 'info', "Assigning license: {$skuName}");

                // Retry up to 3 times on ConcurrencyViolation (transient Azure error)
                $assigned = false;
                for ($attempt = 1; $attempt <= 3; $attempt++) {
                    try {
                        $graph->assignLicense($azureId, $sku);
                        $this->engine->logEvent($workflow, 'success', "License '{$skuName}' assigned.");
                        $assignedLicenses[] = ['sku' => $sku, 'name' => $skuName];
                        $assigned = true;
                        break;
                    } catch (\Throwable $e) {
                        if ($attempt < 3 && str_contains($e->getMessage(), 'ConcurrencyViolation')) {
                            $wait = $attempt * 3;
                            $this->engine->logEvent($workflow, 'warning', "License '{$skuName}' ConcurrencyViolation — retrying in {$wait}s (attempt {$attempt}/3)...");
                            sleep($wait);
                        } else {
                            $this->engine->logEvent($workflow, 'warning', "License '{$skuName}' assignment failed (non-fatal): " . $e->getMessage());
                            break;
                        }
                    }
                }
            }

            // Persist assigned licenses to payload for the show-page summary
            if (!empty($assignedLicenses)) {
                $payload = array_merge($payload, ['assigned_licenses' => $assignedLicenses]);
                $workflow->payload = $payload;
                $workflow->save();
            }
        }

        // ── Step 3b: Auto-assign Azure groups based on branch + department ─
        $branchIdForGroups = $workflow->branch_id;
        $deptIdForGroups   = isset($payload['department_id']) ? (int) $payload['department_id'] : null;

        if ($branchIdForGroups || $deptIdForGroups) {
            $groupIds = \App\Models\BranchDepartmentGroupMapping::getGroupsFor(
                $branchIdForGroups ?? 0,
                $deptIdForGroups   ?? 0
            );

            if ($groupIds->isNotEmpty()) {
                $this->engine->logEvent($workflow, 'info', "Auto-assigning {$groupIds->count()} Azure group(s) from branch/dept mapping.");

                foreach ($groupIds as $identityGroupId) {
                    $group = \App\Models\IdentityGroup::find($identityGroupId);
                    if (! $group) {
                        continue;
                    }
                    try {
                        $this->engine->logEvent($workflow, 'info', "Auto-assigning group: {$group->display_name}");
                        $graph->addUserToGroup($azureId, $group->azure_id);
                        $this->engine->logEvent($workflow, 'success', "Group '{$group->display_name}' assigned.");
                        sleep(1); // Small delay to avoid Graph throttling
                    } catch (\Throwable $e) {
                        // 409 = already a member — not an error
                        if (str_contains($e->getMessage(), '409')) {
                            $this->engine->logEvent($workflow, 'info', "Already in group: {$group->display_name}");
                        } else {
                            $this->engine->logEvent($workflow, 'warning',
                                "Group '{$group->display_name}' assignment failed (non-fatal): " . $e->getMessage());
                        }
                    }
                }

                // Save group assignment IDs to payload for audit trail
                $payload['auto_assigned_groups'] = $groupIds->toArray();
                $workflow->payload = $payload;
                $workflow->save();
            }
        }

        // ── Step 4: Create UCM extension (branch-aware) ──────────
        // Branch UCM + range take priority; global settings are the fallback.
        $extension = null;
        $ucmServer = null;
        $branch    = $workflow->branch_id ? Branch::find($workflow->branch_id) : null;

        if ($branch) {
            $ucmServer = $branch->effectiveUcmServer($settings);
            $extRange  = $branch->effectiveExtRange($settings);
        } else {
            $ucmServer = $settings->default_ucm_id ? UcmServer::find($settings->default_ucm_id) : null;
            $extRange  = [
                'start' => (int) ($settings->ext_range_start ?? 1000),
                'end'   => (int) ($settings->ext_range_end   ?? 1999),
            ];
        }

        // Idempotency: if a previous attempt already created the extension, reuse it
        if (!empty($payload['extension'])) {
            $extension = $payload['extension'];
            $this->engine->logEvent($workflow, 'info', "Resuming — UCM extension already created: {$extension}");
        } elseif ($ucmServer) {
            try {
                $rangeStart = $extRange['start'];
                $rangeEnd   = $extRange['end'];

                $this->engine->logEvent($workflow, 'info', "Finding available extension ({$rangeStart}–{$rangeEnd}) on UCM: {$ucmServer->name}");

                $extension = $this->extProvisioning->getFirstAvailable($ucmServer, $rangeStart, $rangeEnd);
                $this->engine->logEvent($workflow, 'info', "Using extension: {$extension}");

                $this->extProvisioning->createForUser($ucmServer, $extension, $displayName, $upn, [
                    'department'   => $payload['department'] ?? '',
                    'location'     => 'SA', // Requested: location should be SA
                    'phone_number' => $payload['mobile_phone'] ?? $payload['businessPhones'][0] ?? '',
                ]);
                $this->engine->logEvent($workflow, 'success', "UCM extension {$extension} created (voicemail=no, call_waiting=no).");

                $payload = array_merge($payload, [
                    'extension'     => $extension,
                    'ucm_server_id' => $ucmServer->id,
                ]);
                $workflow->payload = $payload;
                $workflow->save();

            } catch (\Throwable $e) {
                $errMsg = $e->getMessage();
                // UCM status -25 ("Failed to update data") can mean extension already exists —
                // treat as a soft failure and continue rather than blocking the whole workflow.
                if (str_contains($errMsg, '-25') || str_contains($errMsg, 'Failed to update data')) {
                    $this->engine->logEvent($workflow, 'warning', "UCM extension creation returned conflict error (non-fatal, may already exist): {$errMsg}");
                } else {
                    $this->engine->logEvent($workflow, 'warning', "UCM extension creation failed (non-fatal): {$errMsg}");
                }
            }
        }

        // ── Step 5: Update Azure profile with templates (branch-aware) ──
        // Branch templates override global settings.
        $officeTemplate = $branch ? $branch->effectiveOfficeTemplate($settings) : $settings->profile_office_template;
        $phoneTemplate  = $branch ? $branch->effectivePhoneTemplate($settings)  : $settings->profile_phone_template;

        if ($branch && ($officeTemplate || $phoneTemplate)) {
            try {
                $this->engine->logEvent($workflow, 'info', 'Updating Azure profile with templates...');

                $profileFields = $this->extProvisioning->buildProfileFields(
                    $branch,
                    $extension ?? '',
                    $firstName,
                    $lastName,
                    $upn,
                    [
                        'officeLocation' => $officeTemplate,
                        'phone'          => $phoneTemplate,
                    ]
                );

                $updateData = [];
                if (! empty($profileFields['officeLocation'])) {
                    $updateData['officeLocation'] = $profileFields['officeLocation'];
                }
                if (! empty($profileFields['phone'])) {
                    $updateData['businessPhones'] = [$profileFields['phone']];
                }

                if (! empty($updateData)) {
                    $graph->updateUser($azureId, $updateData);
                    $this->engine->logEvent($workflow, 'success', 'Azure profile updated with office/phone templates.');
                }
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning', 'Azure profile update failed (non-fatal): ' . $e->getMessage());
            }
        }

        // ── Step 6: Create employee record ────────────────────────
        // Idempotency: skip if already created in a previous attempt,
        // or if an employee with the same azure_id already exists.
        if (!empty($payload['employee_id'])) {
            $this->engine->logEvent($workflow, 'info', "Resuming — employee record already created (ID: {$payload['employee_id']})");
        } else {
            $this->engine->logEvent($workflow, 'info', 'Creating employee record...');
            try {
                // Guard against duplicate if a previous attempt created the employee
                // but failed before saving the ID to the payload
                $employee = Employee::where('azure_id', $azureId)->first()
                    ?? Employee::create([
                        'azure_id'         => $azureId,
                        'name'             => $displayName,
                        'email'            => $upn,
                        'branch_id'        => $workflow->branch_id,
                        'department_id'    => $payload['department_id'] ?? null,
                        'job_title'        => $payload['job_title']     ?? null,
                        'status'           => 'active',
                        'hired_date'       => now()->toDateString(),
                        'extension_number' => $extension,
                        'ucm_server_id'    => $ucmServer?->id,
                    ]);
                $this->engine->logEvent($workflow, 'success', 'Employee record created.');

                // Save employee ID to payload so the show page can link to the profile
                $payload = array_merge($payload, ['employee_id' => $employee->id]);
                $workflow->payload = $payload;
                $workflow->save();
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning', 'Employee record creation failed (non-fatal): ' . $e->getMessage());
            }
        }

        // ── Step 7: Notify admins ─────────────────────────────────
        $extInfo = $extension ? " Extension: {$extension}." : '';
        $this->notifications->notifyAdmins(
            'workflow_complete',
            'User Provisioned',
            "New user '{$displayName}' ({$upn}) has been successfully provisioned.{$extInfo}",
            route('admin.workflows.show', $workflow->id),
            'info'
        );

        // ── Step 7b: Printer deployment (dual path) ───────────────
        try {
            $hasPrinters = \App\Models\Printer::where('branch_id', $workflow->branch_id)->exists();
            if ($hasPrinters && ! empty($upn)) {
                // Check if Intune scripts already deployed for this branch
                $intuneDeployed = \App\Models\Printer::where('branch_id', $workflow->branch_id)
                    ->whereNotNull('intune_script_id')
                    ->exists();

                if ($intuneDeployed) {
                    $this->engine->logEvent($workflow, 'info',
                        'Branch printers are deployed via Intune — will auto-install on device enrollment.');
                }

                // Always send self-service email as backup / for non-enrolled devices
                $employeeId = $payload['employee_id'] ?? null;
                $printerToken = \App\Models\PrinterDeployToken::create([
                    'employee_id'   => $employeeId,
                    'branch_id'     => $workflow->branch_id,
                    'token'         => \Illuminate\Support\Str::random(64),
                    'expires_at'    => now()->addDays(14),
                    'sent_to_email' => $upn,
                ]);

                if ($employeeId) {
                    \App\Jobs\SendPrinterSetupEmailJob::dispatch($printerToken->id)->onQueue('emails');
                    $this->engine->logEvent($workflow, 'info', "Printer setup email queued for: {$upn}");
                }
            }
        } catch (\Throwable $e) {
            $this->engine->logEvent($workflow, 'warning',
                'Printer setup step failed (non-fatal): ' . $e->getMessage());
        }

        $this->engine->logEvent($workflow, 'success', 'User provisioning complete.');
    }

    // ─────────────────────────────────────────────────────────────
    // Build UPN: first.last@domain (with collision suffix)
    // ─────────────────────────────────────────────────────────────

    private function buildUPN(string $firstName, string $lastName, string $domain): string
    {
        $sanitize = function (string $s): string {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            $s = strtolower($s);
            $s = preg_replace('/[^a-z0-9]/', '', $s);
            return $s;
        };

        $first = $sanitize($firstName);
        $last  = $sanitize($lastName);
        $base  = $first . '.' . $last;

        $upn = "{$base}@{$domain}";
        if (! IdentityUser::where('user_principal_name', $upn)->exists()) {
            return $upn;
        }

        for ($i = 2; $i <= 99; $i++) {
            $candidate = "{$base}{$i}@{$domain}";
            if (! IdentityUser::where('user_principal_name', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException("No available UPN for {$firstName} {$lastName}@{$domain}");
    }

    // ─────────────────────────────────────────────────────────────
    // Deprovision user
    // ─────────────────────────────────────────────────────────────

    public function deprovisionUser(WorkflowRequest $workflow): void
    {
        $payload = $workflow->payload ?? [];
        $azureId = $payload['azure_id'] ?? null;

        $this->engine->logEvent($workflow, 'info', 'Starting user deprovisioning.');

        if ($azureId) {
            $this->engine->logEvent($workflow, 'info', 'Disabling Azure user...');
            try {
                $graph = new GraphService();
                $graph->disableUser($azureId);
                $this->engine->logEvent($workflow, 'success', 'Azure user disabled.');
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed to disable Azure user: ' . $e->getMessage());
            }

            $employee = Employee::where('azure_id', $azureId)->first();
            if ($employee) {
                $employee->update([
                    'status'          => 'terminated',
                    'terminated_date' => now()->toDateString(),
                ]);
                $this->engine->logEvent($workflow, 'success', 'Employee record updated to terminated.');
                $employee->activeAssets()->update(['notes' => 'PENDING RETURN — employee terminated']);
                $this->engine->logEvent($workflow, 'info', 'Active asset assignments flagged for return.');
            }
        }

        $this->engine->logEvent($workflow, 'success', 'User deprovisioning complete.');
    }

    // ─────────────────────────────────────────────────────────────
    // Full offboarding (HR-initiated — manager-approved)
    // ─────────────────────────────────────────────────────────────

    /**
     * Full offboarding sequence triggered after manager approval:
     * 1. Disable Azure account
     * 2. Archive mailbox (log intent for Exchange Online PS)
     * 3. Downgrade to Exchange-only license
     * 4. Forward mailbox (log intent) if forward_to specified
     * 5. Update employee record to terminated
     * 6. Flag active assets for return
     */
    public function deprovisionUserFull(WorkflowRequest $workflow): void
    {
        $payload = $workflow->payload ?? [];
        $azureId = $payload['azure_id'] ?? null;

        $this->engine->logEvent($workflow, 'info', 'Starting full offboarding deprovisioning.');

        $graph = new \App\Services\Identity\GraphService();

        if ($azureId) {
            // Step 1: Disable Azure account
            try {
                $graph->disableUser($azureId);
                $this->engine->logEvent($workflow, 'success', 'Azure account disabled.');
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning', 'Azure disable failed (non-fatal): ' . $e->getMessage());
            }

            // Step 2: Archive mailbox
            try {
                $graph->archiveMailbox($azureId);
                $this->engine->logEvent($workflow, 'info', 'Mailbox archive intent logged.');
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning', 'Mailbox archive failed (non-fatal): ' . $e->getMessage());
            }

            // Step 3: Downgrade to Exchange-only license
            try {
                $graph->downgradeToExchangeOnly($azureId);
                $this->engine->logEvent($workflow, 'info', 'License downgraded to Exchange-only.');
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning', 'License downgrade failed (non-fatal): ' . $e->getMessage());
            }

            // Step 4: Set forwarding if requested
            if (! empty($payload['forward_to'])) {
                try {
                    $graph->forwardMailbox($azureId, $payload['forward_to']);
                    $this->engine->logEvent($workflow, 'info', "Mailbox forwarding intent logged to: {$payload['forward_to']}.");
                } catch (\Throwable $e) {
                    $this->engine->logEvent($workflow, 'warning', 'Mailbox forward failed (non-fatal): ' . $e->getMessage());
                }
            }
        }

        // Step 5: Update employee record
        $employee = null;
        if ($azureId) {
            $employee = \App\Models\Employee::where('azure_id', $azureId)->first();
        } elseif (! empty($payload['employee_id'])) {
            $employee = \App\Models\Employee::find($payload['employee_id']);
        }

        if ($employee) {
            $employee->update([
                'status'          => 'terminated',
                'terminated_date' => $payload['last_day'] ?? now()->toDateString(),
            ]);
            $this->engine->logEvent($workflow, 'success', 'Employee record updated to terminated.');

            // Step 6: Flag active assets
            $employee->activeAssets()->update(['notes' => 'PENDING RETURN — employee offboarded']);
            $this->engine->logEvent($workflow, 'info', 'Active asset assignments flagged for return.');
            $employee->activeItems()->update(['notes'  => 'PENDING RETURN — employee offboarded']);
        }

        $this->engine->logEvent($workflow, 'success', 'Full offboarding deprovisioning complete.');
    }
}
