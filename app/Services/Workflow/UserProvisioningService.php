<?php

namespace App\Services\Workflow;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\IdentityGroup;
use App\Models\IdentityUser;
use App\Models\NetworkFloor;
use App\Models\OnboardingManagerToken;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Models\WorkflowRequest;
use App\Models\WorkflowTask;
use App\Services\Identity\GraphService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class UserProvisioningService
{
    public function __construct(
        private WorkflowEngine              $engine,
        private ExtensionProvisioningService $extProvisioning,
        private NotificationService          $notifications,
        private TicketingApiService          $ticketing
    ) {}

    // ─────────────────────────────────────────────────────────────
    // Full provisioning = core identity, then the manager-dependent
    // half. Kept as one call for workflows with no manager form
    // (and as the resume path — both stages are idempotent, so a
    // re-run after the manager replies skips straight to stage B).
    // ─────────────────────────────────────────────────────────────

    public function provisionUser(WorkflowRequest $workflow): void
    {
        $this->provisionCoreIdentity($workflow);
        $this->completeProvisioning($workflow);
    }

    /**
     * Push the employee's NOC profile to Azure through the same service the
     * rest of the app uses, rather than hand-rolling the field mapping here.
     *
     * That is what gets branch street/city, office location, the office phone
     * with the extension stripped out, and the extension itself in the fax
     * field (Azure has no extension attribute; the signature transport rule
     * reads it as %%FaxNumber%%). Non-fatal — a Graph hiccup must not fail
     * provisioning that has already created the account.
     */
    private function syncAzureProfile(WorkflowRequest $workflow, string $stage): void
    {
        $employeeId = $workflow->payload['employee_id'] ?? null;
        if (! $employeeId) {
            return;
        }

        $employee = Employee::with('branch', 'department')->find($employeeId);
        if (! $employee || ! $employee->azure_id) {
            return;
        }

        try {
            $sync     = app(\App\Services\Identity\AzureContactSyncService::class);
            $proposed = $sync->computeFromEmployee($employee);

            if (empty($proposed)) {
                return;
            }

            $sync->applyToEmployee($employee, $proposed);

            $summary = collect($proposed)
                ->only(['officeLocation', 'city', 'streetAddress', 'faxNumber'])
                ->filter()
                ->map(fn ($v, $k) => "{$k}=".(is_array($v) ? implode(',', $v) : $v))
                ->implode(', ');

            $this->engine->logEvent($workflow, 'success',
                "Azure profile synced ({$stage})".($summary ? ": {$summary}" : '.'));
        } catch (\Throwable $e) {
            $this->engine->logEvent($workflow, 'warning',
                "Azure profile sync failed during {$stage} (non-fatal): ".$e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Stage A — everything that does NOT depend on the manager:
    // the Azure account, licences, branch/department/gender groups
    // and the employee record. Runs as soon as IT approves so the
    // mailbox is live while the manager form is still outstanding.
    // ─────────────────────────────────────────────────────────────

    public function provisionCoreIdentity(WorkflowRequest $workflow): void
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
                    // givenName/surname are separate Azure fields — without them
                    // Azure only knows the combined display name, which breaks
                    // anything reading first/last name (Teams, Outlook cards,
                    // signature tokens). HR gave us both, so send both.
                    'givenName'         => $firstName ?: null,
                    'surname'           => $lastName  ?: null,
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

        $genderForGroups = $payload['gender'] ?? null;

        if ($branchIdForGroups || $deptIdForGroups || $genderForGroups) {
            $groupIds = \App\Models\BranchDepartmentGroupMapping::getGroupsFor(
                $branchIdForGroups ?? 0,
                $deptIdForGroups   ?? 0,
                $genderForGroups
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
                        if (\App\Services\Identity\GraphService::isAlreadyMemberError($e)) {
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

        // ── Step 4: Create employee record ────────────────────────
        // Created here, in the core stage, so the person exists in NOC as soon
        // as the identity does. The extension is attached later by
        // completeProvisioning() once the manager has said whether they need one.
        //
        // Idempotency: skip if already created in a previous attempt, or if an
        // employee with the same azure_id already exists.
        if (! empty($payload['employee_id'])) {
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
                        'gender'           => $payload['gender']       ?? null,
                        'branch_id'        => $workflow->branch_id,
                        'department_id'    => $payload['department_id'] ?? null,
                        'job_title'        => $payload['job_title']     ?? null,
                        // Reporting line picked by HR on the onboarding form.
                        // Both are employee IDs, so the org chart is correct from
                        // day one rather than being back-filled later.
                        'manager_id'       => $payload['manager_id']    ?? null,
                        'supervisor_id'    => $payload['supervisor_id'] ?? null,
                        'mobile_phone'     => $payload['mobile_phone']  ?? null,
                        'status'           => 'active',
                        'hired_date'       => now()->toDateString(),
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

        // ── Step 5: Push the NOC profile to Azure ─────────────────
        $this->syncAzureProfile($workflow, 'core identity');

        $this->engine->logEvent($workflow, 'success',
            'Core identity provisioned (account, licences, groups, employee record).');
    }

    // ─────────────────────────────────────────────────────────────
    // Stage B — everything that depends on the manager's answers:
    // their chosen groups, internet tier, the IP-phone extension, and
    // the resulting tickets and notifications.
    //
    // Runs immediately after the manager submits the form. Safe to
    // re-run: every step checks the payload for prior results first.
    // ─────────────────────────────────────────────────────────────

    public function completeProvisioning(WorkflowRequest $workflow): void
    {
        $payload  = $workflow->payload ?? [];
        $settings = Setting::get();

        $azureId = $payload['azure_id'] ?? null;
        $upn     = $payload['upn']      ?? null;

        if (! $azureId || ! $upn) {
            throw new \RuntimeException(
                'Cannot complete provisioning — the Azure account was never created. Retry the workflow to run core provisioning first.'
            );
        }

        $firstName   = trim($payload['first_name'] ?? '');
        $lastName    = trim($payload['last_name']  ?? '');
        $displayName = $payload['display_name'] ?? trim("{$firstName} {$lastName}");
        $graph       = new GraphService();

        $this->engine->logEvent($workflow, 'info', 'Applying the manager’s setup choices.');

        // ── Step 3b(ii): Floor-specific auto-groups ───────────────
        // The floor is only known now — the manager picked it on the form — so
        // this is the earliest any floor-scoped mapping can be applied. Re-runs
        // the same matcher with the real floor and assigns only what stage A
        // did not already cover (printer groups per floor, typically).
        $floorIdForGroups = $payload['floor_id'] ?? null;

        if ($floorIdForGroups) {
            $alreadyAssigned = collect($payload['auto_assigned_groups'] ?? []);

            $floorGroupIds = \App\Models\BranchDepartmentGroupMapping::getGroupsFor(
                $workflow->branch_id ?? 0,
                isset($payload['department_id']) ? (int) $payload['department_id'] : 0,
                $payload['gender'] ?? null,
                (int) $floorIdForGroups
            )->reject(fn ($id) => $alreadyAssigned->contains($id))->values();

            if ($floorGroupIds->isNotEmpty()) {
                $this->engine->logEvent($workflow, 'info',
                    "Assigning {$floorGroupIds->count()} floor-specific Azure group(s) for the manager's chosen floor.");

                foreach ($floorGroupIds as $identityGroupId) {
                    $group = IdentityGroup::find($identityGroupId);
                    if (! $group) {
                        continue;
                    }
                    try {
                        $graph->addUserToGroup($azureId, $group->azure_id);
                        $this->engine->logEvent($workflow, 'success', "Floor group '{$group->display_name}' assigned.");
                        sleep(1);
                    } catch (\Throwable $e) {
                        if (\App\Services\Identity\GraphService::isAlreadyMemberError($e)) {
                            $this->engine->logEvent($workflow, 'info', "Already in group: {$group->display_name}");
                        } else {
                            $this->engine->logEvent($workflow, 'warning',
                                "Floor group '{$group->display_name}' assignment failed (non-fatal): " . $e->getMessage());
                        }
                    }
                }

                // Record them alongside the stage-A groups so a retry does not
                // try to assign the same ones again.
                $payload['auto_assigned_groups'] = $alreadyAssigned->merge($floorGroupIds)->unique()->values()->all();
                $workflow->payload = $payload;
                $workflow->save();
            }
        }

        // ── Business app accounts (Salesforce, Oracle, …) ─────────
        $this->requestBusinessAppAccounts($workflow, $payload, $graph, $azureId);

        // ── Step 3c: Assign manager-selected groups ───────────────
        // If the manager filled the onboarding form, assign the groups they chose.
        $managerGroupIds = $payload['manager_groups'] ?? [];
        if (! empty($managerGroupIds)) {
            $this->engine->logEvent($workflow, 'info', 'Assigning ' . count($managerGroupIds) . ' manager-selected Azure group(s).');
            foreach ($managerGroupIds as $identityGroupId) {
                $group = IdentityGroup::find($identityGroupId);
                if (! $group) continue;
                try {
                    $this->engine->logEvent($workflow, 'info', "Assigning manager-selected group: {$group->display_name}");
                    $graph->addUserToGroup($azureId, $group->azure_id);
                    $this->engine->logEvent($workflow, 'success', "Group '{$group->display_name}' assigned.");
                    sleep(1);
                } catch (\Throwable $e) {
                    if (\App\Services\Identity\GraphService::isAlreadyMemberError($e)) {
                        $this->engine->logEvent($workflow, 'info', "Already in group: {$group->display_name}");
                    } else {
                        $this->engine->logEvent($workflow, 'warning',
                            "Manager group '{$group->display_name}' assignment failed (non-fatal): " . $e->getMessage());
                    }
                }
            }
        }

        // ── Step 3d: Assign internet access level group ───────────
        // Internet tier chosen on the manager form maps to a specific Azure group.
        $internetAzureGroupId = $payload['internet_access_group_id'] ?? null;
        $internetLevelLabel   = $payload['internet_access_group_name'] ?? ($payload['internet_level'] ?? null);
        if ($internetAzureGroupId) {
            try {
                $this->engine->logEvent($workflow, 'info',
                    "Assigning internet access group: {$internetLevelLabel}");
                $graph->addUserToGroup($azureId, $internetAzureGroupId);
                $this->engine->logEvent($workflow, 'success',
                    "Internet access group '{$internetLevelLabel}' assigned.");
                sleep(1);
            } catch (\Throwable $e) {
                if (\App\Services\Identity\GraphService::isAlreadyMemberError($e)) {
                    $this->engine->logEvent($workflow, 'info',
                        "Already in internet access group: {$internetLevelLabel}");
                } else {
                    $this->engine->logEvent($workflow, 'warning',
                        "Internet access group '{$internetLevelLabel}' assignment failed (non-fatal): " . $e->getMessage());
                }
            }
        }

        // ── Step 4: Create UCM extension (floor-aware, then branch-aware) ──
        // Priority: floor ext range → branch ext range → global settings.
        // Only create extension if manager form says needs_extension (or form not filled yet).
        // Only create extension if manager explicitly said "yes" on the form.
        // If the form hasn't been submitted yet, needs_extension will not be in
        // the payload — default to false so nothing is created prematurely.
        $needsExtension = $payload['needs_extension'] ?? false;
        $extension = null;
        $ucmServer = null;
        $branch    = $workflow->branch_id ? Branch::find($workflow->branch_id) : null;

        // Try floor range first (from manager form)
        $floorId = $payload['floor_id'] ?? null;
        $floor   = $floorId ? NetworkFloor::find($floorId) : null;

        if ($floor && $floor->ext_range_start && $floor->ext_range_end) {
            // Prefer workflow's branch; fall back to the floor's own branch so
            // we can still resolve a UCM server when the workflow isn't tied
            // to a branch directly.
            $floorBranch = $branch ?: ($floor->branch_id ? Branch::find($floor->branch_id) : null);
            $ucmServer   = $floorBranch ? $floorBranch->effectiveUcmServer($settings)
                                        : ($settings->default_ucm_id ? UcmServer::find($settings->default_ucm_id) : null);
            $extRange    = [
                'start' => (int) $floor->ext_range_start,
                'end'   => (int) $floor->ext_range_end,
            ];
            $this->engine->logEvent($workflow, 'info', "Using floor '{$floor->name}' extension range: {$extRange['start']}–{$extRange['end']}");
        } elseif ($branch) {
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
        } elseif (! $needsExtension) {
            $this->engine->logEvent($workflow, 'info', 'Extension skipped — manager indicated no IP phone needed.');
        } elseif ($ucmServer) {
            try {
                $rangeStart = $extRange['start'];
                $rangeEnd   = $extRange['end'];

                $this->engine->logEvent($workflow, 'info', "Finding available extension ({$rangeStart}–{$rangeEnd}) on UCM: {$ucmServer->name}");

                $extension = $this->extProvisioning->getFirstAvailable($ucmServer, $rangeStart, $rangeEnd);
                $this->engine->logEvent($workflow, 'info', "Using extension: {$extension}");

                // createForUser returns the extension details including the generated secret
                $extDetails = $this->extProvisioning->createForUser($ucmServer, $extension, $displayName, $upn, [
                    'first_name'   => $firstName,
                    'last_name'    => $lastName,
                    'department'   => $payload['department'] ?? '',
                    'location'     => 'SA', // Requested: location should be SA
                    'phone_number' => $payload['mobile_phone'] ?? $payload['businessPhones'][0] ?? '',
                ]);
                $this->engine->logEvent($workflow, 'success', "UCM extension {$extension} created (voicemail=no, call_waiting=no).");

                $payload = array_merge($payload, [
                    'extension'            => $extension,
                    'ucm_server_id'        => $ucmServer->id,
                    'ucm_extension_secret' => $extDetails['secret'] ?? '(see UCM admin)',
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
        } else {
            // needs_extension=true, no existing extension, but no UCM server could
            // be resolved (no branch on workflow/floor, no default_ucm_id in settings).
            $this->engine->logEvent($workflow, 'warning',
                'Extension requested but no UCM server is configured. ' .
                'Set a UCM server on the branch, on the floor\'s branch, or as the global default in Settings.');
        }

        // ── Attach the extension to the employee record, then re-sync ──
        // The employee row was created in the core stage before the extension
        // existed. Writing it here is what lets AzureContactSyncService carry the
        // extension into Azure's fax field (%%FaxNumber%% in the signature rule)
        // and compose the "{branch phone} EXT {n}" business phone.
        if (! empty($payload['employee_id'])) {
            $employee = Employee::find($payload['employee_id']);
            if ($employee && $extension && $employee->extension_number !== $extension) {
                $employee->update([
                    'extension_number' => $extension,
                    'ucm_server_id'    => $ucmServer?->id,
                ]);
                $this->engine->logEvent($workflow, 'info', "Employee record updated with extension {$extension}.");
            }
        }

        $this->syncAzureProfile($workflow, 'manager setup');

        // ── Step 7: Notify admins ─────────────────────────────────
        $extInfo     = $extension ? " Extension: {$extension}." : '';
        $commentInfo = ! empty($payload['manager_comments'])
            ? " Manager comments: \"{$payload['manager_comments']}\"."
            : '';
        $this->notifications->notifyAdmins(
            'workflow_complete',
            'User Provisioned',
            "New user '{$displayName}' ({$upn}) has been successfully provisioned.{$extInfo}{$commentInfo}",
            \App\Support\Noc::route('admin.workflows.show', $workflow->id),
            'info'
        );

        // ── Step 7b: Printer deployment (floor-aware, dual path) ─────
        try {
            // Prefer floor-specific printers; fall back to branch-level
            $floorId     = $payload['floor_id'] ?? null;
            $hasPrinters = $floorId
                ? \App\Models\Printer::where('floor_id', $floorId)->exists()
                : \App\Models\Printer::where('branch_id', $workflow->branch_id)->exists();

            if (! $hasPrinters && $floorId) {
                // No floor-specific printers — try branch level
                $hasPrinters = \App\Models\Printer::where('branch_id', $workflow->branch_id)->exists();
            }

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

        // ── Step 7c: Create workflow tasks (based on manager form) ──
        $this->createProvisioningTasks($workflow, $payload, $extension, $ucmServer);

        $this->engine->logEvent($workflow, 'success', 'User provisioning complete.');
    }

    /**
     * Act on the business systems the manager ticked (Salesforce, Oracle, …).
     *
     * NOC cannot create these accounts, so for each one it does the two things
     * it can: put the employee in the security group that governs access, and
     * email the team who administers that system. The employee_app_accounts row
     * is what makes it visible on the profile and stops a duplicate request.
     *
     * Idempotent — an existing row means the email already went out.
     */
    private function requestBusinessAppAccounts(
        WorkflowRequest $workflow,
        array $payload,
        GraphService $graph,
        string $azureId
    ): void {
        $appIds     = $payload['business_apps'] ?? [];
        $employeeId = $payload['employee_id'] ?? null;

        if (empty($appIds) || ! $employeeId) {
            return;
        }

        $apps = \App\Models\BusinessApp::with('identityGroup')->whereIn('id', $appIds)->get();

        foreach ($apps as $app) {
            $existing = \App\Models\EmployeeAppAccount::where('employee_id', $employeeId)
                ->where('business_app_id', $app->id)
                ->first();

            if ($existing) {
                $this->engine->logEvent($workflow, 'info',
                    "{$app->name} account already recorded for this employee — not requesting again.");
                continue;
            }

            // Security group first: if the email lands before access is granted,
            // whoever picks it up may find the person cannot sign in yet.
            if ($app->identityGroup?->azure_id) {
                try {
                    $graph->addUserToGroup($azureId, $app->identityGroup->azure_id);
                    $this->engine->logEvent($workflow, 'success',
                        "{$app->name} security group '{$app->identityGroup->display_name}' assigned.");
                    sleep(1);
                } catch (\Throwable $e) {
                    if (\App\Services\Identity\GraphService::isAlreadyMemberError($e)) {
                        $this->engine->logEvent($workflow, 'info', "Already in {$app->name} security group.");
                    } else {
                        $this->engine->logEvent($workflow, 'warning',
                            "{$app->name} security group assignment failed (non-fatal): " . $e->getMessage());
                    }
                }
            } else {
                $this->engine->logEvent($workflow, 'info',
                    "{$app->name} has no security group configured — skipping group assignment.");
            }

            \App\Models\EmployeeAppAccount::create([
                'employee_id'     => $employeeId,
                'business_app_id' => $app->id,
                'workflow_id'     => $workflow->id,
                'status'          => \App\Models\EmployeeAppAccount::STATUS_REQUESTED,
                'requested_at'    => now(),
            ]);

            try {
                \App\Jobs\SendBusinessAppAccountMailJob::dispatch(
                    $employeeId,
                    $app->id,
                    \App\Mail\BusinessAppAccountMail::ACTIVATE,
                    $workflow->id,
                );
            } catch (\Throwable $e) {
                $this->engine->logEvent($workflow, 'warning',
                    "Failed to queue the {$app->name} account request email: " . $e->getMessage());
            }
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Create post-provisioning tasks and send completion email
    // ─────────────────────────────────────────────────────────────

    private function createProvisioningTasks(
        WorkflowRequest $workflow,
        array           $payload,
        ?string         $extension,
        ?UcmServer      $ucmServer
    ): void {
        // Internal WorkflowTask rows (laptop/IP phone) were removed — the
        // external ticketing system owns laptop/phone provisioning, and the
        // IT summary email (sent below) covers credentials/handoff.

        // ── External ticketing API call ───────────────────────────
        $this->createExternalTickets($workflow, $payload, $extension, $ucmServer);

        // ── IT team summary email (routable via Notification Rules) ──
        try {
            \App\Jobs\SendItOnboardingSummaryJob::dispatch($workflow->id);
            $this->engine->logEvent($workflow, 'info', 'IT onboarding summary email queued.');
        } catch (\Throwable $e) {
            $this->engine->logEvent($workflow, 'warning',
                'Failed to queue IT onboarding summary email: ' . $e->getMessage());
        }

        // ── Welcome email to the new employee ─────────────────────
        try {
            \App\Jobs\SendEmployeeWelcomeJob::dispatch($workflow->id);
            $this->engine->logEvent($workflow, 'info', 'Welcome email queued for new employee.');
        } catch (\Throwable $e) {
            $this->engine->logEvent($workflow, 'warning',
                'Failed to queue employee welcome email: ' . $e->getMessage());
        }

        // ── Details email to HR + the reporting manager ───────────
        // Same facts as the IT summary minus every credential, so it can go to
        // people who need to contact the new starter but not sign in as them.
        try {
            \App\Jobs\SendOnboardingDetailsJob::dispatch($workflow->id);
            $this->engine->logEvent($workflow, 'info', 'Details email queued for HR and the manager.');
        } catch (\Throwable $e) {
            $this->engine->logEvent($workflow, 'warning',
                'Failed to queue HR/manager details email: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Create tickets in the external ticketing system
    // (idempotent: skips if tickets were already created in a prior run)
    // ─────────────────────────────────────────────────────────────

    private function createExternalTickets(
        WorkflowRequest $workflow,
        array           $payload,
        ?string         $extension,
        ?UcmServer      $ucmServer
    ): void {
        if (! empty($payload['ticketing']['laptop_ticket_id'])) {
            $this->engine->logEvent($workflow, 'info',
                'Resuming — ticketing tickets already created, skipping API call.');
            return;
        }

        $displayName = $payload['display_name']
            ?? trim(($payload['first_name'] ?? '') . ' ' . ($payload['last_name'] ?? ''))
            ?: 'New Employee';
        $upn         = $payload['upn'] ?? null;
        if (! $upn) {
            $this->engine->logEvent($workflow, 'warning',
                'Ticketing API skipped — UPN not available for new user.');
            return;
        }

        // Title — "New Employee - {full name}"
        $title = "New Employee - {$displayName}";

        // Description — manager laptop response + extension details
        $laptopStatus = $payload['laptop_status'] ?? 'unspecified';
        $laptopLine   = match ($laptopStatus) {
            'new'  => 'Laptop: NEW',
            'used' => 'Laptop: USED / REFURBISHED',
            'none' => 'Laptop: NOT REQUIRED',
            default => 'Laptop: ' . strtoupper((string) $laptopStatus),
        };

        $descParts = [$laptopLine];
        if ($extension) {
            $ucmHost = $ucmServer->url ?? ($ucmServer->name ?? 'UCM');
            $ucmPass = $payload['ucm_extension_secret'] ?? '(reset via UCM admin)';
            $descParts[] = "Extension Number: {$extension}";
            $descParts[] = "Extension ID (UCM login): {$extension}";
            $descParts[] = "Extension Password: {$ucmPass}";
            $descParts[] = "UCM Server: {$ucmHost}";
        } else {
            $descParts[] = 'Extension: NOT REQUIRED';
        }
        if (! empty($payload['manager_comments'])) {
            $descParts[] = 'Manager comments: ' . $payload['manager_comments'];
        }
        $description = implode("\n", $descParts);

        // Location — take the first 3 uppercase letters of the branch name
        $branch   = $workflow->branch_id ? Branch::find($workflow->branch_id) : null;
        $location = $branch
            ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $branch->name ?? ''), 0, 3))
            : '';
        if (! $location) {
            $location = 'N/A';
        }

        try {
            $this->engine->logEvent($workflow, 'info',
                "Calling ticketing API to create laptop/phone tickets for {$upn} ({$location})...");

            $result = $this->ticketing->provisionNewEmployee(
                title:       $title,
                description: $description,
                location:    $location,
                callerEmail: $upn
            );

            if ($result === null) {
                $this->engine->logEvent($workflow, 'info',
                    'Ticketing API not enabled/configured — skipped.');
                return;
            }

            $payload['ticketing'] = [
                'laptop_ticket_id'     => $result['laptopTicketId']              ?? null,
                'phone_ticket_id'      => $result['phoneTicketId']               ?? null,
                'laptop_engineer_email'=> $result['laptopAssignedEngineerEmail'] ?? null,
                'phone_engineer_email' => $result['phoneAssignedEngineerEmail']  ?? null,
                'created_at'           => now()->toIso8601String(),
            ];
            $workflow->payload = $payload;
            $workflow->save();

            $this->engine->logEvent($workflow, 'success',
                "Ticketing API returned tickets — laptop #{$payload['ticketing']['laptop_ticket_id']} ({$payload['ticketing']['laptop_engineer_email']}), phone #{$payload['ticketing']['phone_ticket_id']} ({$payload['ticketing']['phone_engineer_email']}).");
        } catch (\Throwable $e) {
            $this->engine->logEvent($workflow, 'warning',
                'Ticketing API call failed (non-fatal): ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Build UPN: first.last@domain (with collision suffix)
    // ─────────────────────────────────────────────────────────────

    /**
     * Dry-run of what provisioning would do with this name, for the HR form's
     * availability check. Uses the SAME buildUPN() the real run uses, so the
     * preview cannot drift from what actually gets created.
     *
     * `display_name_taken` mirrors the duplicate check in provisionUser(), which
     * throws — surfacing it here means HR finds out before IT approves rather
     * than after.
     *
     * @return array{upn:?string, exact_taken:bool, display_name_taken:bool,
     *               existing_upn:?string, error:?string}
     */
    public function previewIdentity(string $firstName, string $lastName, string $domain): array
    {
        $displayName = trim("{$firstName} {$lastName}");

        $result = [
            'upn'                => null,
            'exact_taken'        => false,
            'display_name_taken' => false,
            'existing_upn'       => null,
            'error'              => null,
        ];

        $existing = IdentityUser::where('display_name', $displayName)->first();
        if ($existing) {
            $result['display_name_taken'] = true;
            $result['existing_upn']       = $existing->user_principal_name;
        }

        try {
            // What the plain first.last@domain would be, before any suffix.
            $result['upn'] = $this->buildUPN($firstName, $lastName, $domain);
        } catch (\Throwable $e) {
            $result['error'] = $e->getMessage();

            return $result;
        }

        // buildUPN appends a numeric suffix on collision, so a returned UPN that
        // differs from the plain form tells us the plain one was already taken.
        $sanitize = fn (string $s) => preg_replace(
            '/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s)
        );
        $plain = $sanitize($firstName).'.'.$sanitize($lastName).'@'.$domain;

        $result['exact_taken'] = $result['upn'] !== $plain;

        return $result;
    }

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
     * 1. Log that Azure cloud deprovisioning is skipped (on-premise identity only)
     * 2. Forward mailbox note (log only — handle via Exchange admin)
     * 3. Update employee record to terminated
     * 4. Flag active assets for return
     */
    public function deprovisionUserFull(WorkflowRequest $workflow): void
    {
        $payload = $workflow->payload ?? [];

        $this->engine->logEvent($workflow, 'info', 'Starting full offboarding deprovisioning.');

        // Azure cloud deprovisioning not applicable — system uses on-premise identity only
        $this->engine->logEvent($workflow, 'info',
            'Azure cloud deprovisioning skipped — system uses on-premise identity only.');

        // Step 4: Set forwarding note if requested
        if (! empty($payload['forward_to'])) {
            $this->engine->logEvent($workflow, 'info',
                "Mailbox forwarding note: {$payload['forward_to']} — handle via Exchange admin.");
        }

        // Step 5: Update employee record
        $employee = null;
        if (! empty($payload['employee_id'])) {
            $employee = \App\Models\Employee::find($payload['employee_id']);
        }
        if (! $employee && ! empty($payload['upn'])) {
            $employee = \App\Models\Employee::where('email', $payload['upn'])->first();
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
