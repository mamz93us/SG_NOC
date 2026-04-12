<?php

namespace App\Services\Identity;

use App\Models\ActivityLog;
use App\Models\IdentityGroup;
use App\Models\IdentityLicense;
use App\Models\IdentitySyncLog;
use App\Models\IdentityUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IdentitySyncService
{
    protected GraphService $graph;

    public function __construct(?GraphService $graph = null)
    {
        $this->graph = $graph ?? new GraphService();
    }

    // ─────────────────────────────────────────────────────────────
    // Full Sync Orchestrator
    // ─────────────────────────────────────────────────────────────

    public function syncAll(): array
    {
        Log::info('IdentitySyncService: Starting full sync.');

        $detailedLog = IdentitySyncLog::create([
            'type'       => 'full',
            'status'     => 'started',
            'started_at' => now(),
        ]);

        $stats = [
            'users'    => 0,
            'groups'   => 0,
            'licenses' => 0,
            'errors'   => [],
        ];

        try {
            // 1. Sync Licenses (fast — single API call)
            $stats['licenses'] = $this->syncLicenses($stats['errors']);
            $detailedLog->update(['licenses_synced' => $stats['licenses']]);

            // 2. Sync Groups (paginated)
            $stats['groups'] = $this->syncGroups($stats['errors']);
            $detailedLog->update(['groups_synced' => $stats['groups']]);

            // 3. Sync Users (paginated — the heaviest step)
            $stats['users'] = $this->syncUsers($stats['errors']);
            $detailedLog->update(['users_synced' => $stats['users']]);

            // 4. Sync Group Memberships (batch API)
            $this->syncRelationships($stats['errors']);

            $status = 'completed';

            $detailedLog->update([
                'status'        => $status,
                'error_message' => empty($stats['errors']) ? null : implode("\n", array_slice($stats['errors'], 0, 10)),
                'completed_at'  => now(),
            ]);

            ActivityLog::log(
                'Identity Sync',
                "Full synchronization completed: {$stats['users']} users, {$stats['groups']} groups, {$stats['licenses']} licenses.",
                ['stats' => $stats]
            );

            return $stats;
        } catch (\Throwable $e) {
            Log::error('IdentitySyncService: Fatal sync error: ' . $e->getMessage());
            $stats['errors'][] = 'Fatal: ' . $e->getMessage();

            $detailedLog->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            throw $e;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Step 1: Licenses
    // ─────────────────────────────────────────────────────────────

    public function syncLicenses(array &$errors): int
    {
        try {
            $skus      = $this->graph->listSubscribedSkus();
            $syncedIds = [];

            DB::transaction(function () use ($skus, &$syncedIds) {
                foreach ($skus as $sku) {
                    IdentityLicense::updateOrCreate(
                        ['sku_id' => $sku['skuId']],
                        [
                            'sku_part_number'   => $sku['skuPartNumber'],
                            'display_name'      => $sku['skuPartNumber'],
                            'total'             => $sku['prepaidUnits']['enabled'] ?? 0,
                            'consumed'          => $sku['consumedUnits'] ?? 0,
                            'available'         => max(0, ($sku['prepaidUnits']['enabled'] ?? 0) - ($sku['consumedUnits'] ?? 0)),
                            'applies_to'        => $sku['appliesTo'] ?? null,
                            'capability_status' => $sku['capabilityStatus'] ?? 'Enabled',
                        ]
                    );
                    $syncedIds[] = $sku['skuId'];
                }
            });

            if (! empty($syncedIds)) {
                IdentityLicense::whereNotIn('sku_id', $syncedIds)->delete();
            }

            Log::info('IdentitySyncService: Synced ' . count($syncedIds) . ' licenses.');
            return count($syncedIds);
        } catch (\Throwable $e) {
            $errors[] = 'Licenses: ' . $e->getMessage();
            Log::error('IdentitySyncService: License sync failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Step 2: Groups
    // ─────────────────────────────────────────────────────────────

    public function syncGroups(array &$errors): int
    {
        try {
            $count     = 0;
            $activeIds = [];

            $this->graph->listGroups(function ($chunk) use (&$count, &$activeIds) {
                DB::transaction(function () use ($chunk, &$activeIds) {
                    foreach ($chunk as $g) {
                        IdentityGroup::updateOrCreate(
                            ['azure_id' => $g['id']],
                            [
                                'display_name'    => $g['displayName'] ?? 'Unknown Group',
                                'description'     => $g['description'] ?? null,
                                'group_type'      => in_array('Unified', $g['groupTypes'] ?? []) ? 'Unified' : null,
                                'mail_enabled'    => $g['mailEnabled'] ?? false,
                                'security_enabled' => $g['securityEnabled'] ?? true,
                            ]
                        );
                        $activeIds[] = $g['id'];
                    }
                });
                $count += count($chunk);
                gc_collect_cycles();
            });

            if ($count > 0) {
                IdentityGroup::whereNotIn('azure_id', $activeIds)->delete();
            }

            Log::info("IdentitySyncService: Synced {$count} groups.");
            return $count;
        } catch (\Throwable $e) {
            $errors[] = 'Groups: ' . $e->getMessage();
            Log::error('IdentitySyncService: Group sync failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Step 3: Users
    // ─────────────────────────────────────────────────────────────

    public function syncUsers(array &$errors): int
    {
        try {
            $count         = 0;
            $activeIds     = [];
            $newlyDisabled = [];

            // Pre-load existing account_enabled status snapshot for transition detection.
            // We do this once before chunked processing so we compare against the
            // state that was in the DB before this sync run started.
            $existingEnabledMap = IdentityUser::pluck('account_enabled', 'azure_id');

            $this->graph->listUsers(function ($chunk) use (&$count, &$activeIds, &$newlyDisabled, $existingEnabledMap) {
                if (empty($chunk)) return;

                DB::transaction(function () use ($chunk, &$activeIds, &$newlyDisabled, $existingEnabledMap) {
                    foreach ($chunk as $u) {
                        // ── Detect account_enabled: true → false transition ──────
                        $wasEnabled = isset($existingEnabledMap[$u['id']])
                            ? (bool) $existingEnabledMap[$u['id']]
                            : null; // null = new user (never seen before)
                        $nowEnabled = (bool) ($u['accountEnabled'] ?? true);

                        if ($wasEnabled === true && ! $nowEnabled) {
                            $newlyDisabled[] = $u['id'];
                        }
                        // ────────────────────────────────────────────────────────

                        // Extract skuIds from assignedLicenses array of objects
                        $rawLicenses = $u['assignedLicenses'] ?? [];
                        $licenseSkus = [];
                        foreach ($rawLicenses as $lic) {
                            if (is_array($lic) && !empty($lic['skuId'])) {
                                $licenseSkus[] = $lic['skuId'];
                            }
                        }

                        IdentityUser::updateOrCreate(
                            ['azure_id' => $u['id']],
                            [
                                'display_name'         => $u['displayName'],
                                'user_principal_name'  => $u['userPrincipalName'],
                                'mail'                 => $u['mail'] ?? null,
                                'job_title'            => $u['jobTitle'] ?? null,
                                'department'           => $u['department'] ?? null,
                                'company_name'         => $u['companyName'] ?? null,
                                'account_enabled'      => $u['accountEnabled'] ?? true,
                                'usage_location'       => $u['usageLocation'] ?? null,
                                'phone_number'         => $u['businessPhones'][0] ?? null,
                                'mobile_phone'         => $u['mobilePhone'] ?? null,
                                'office_location'      => $u['officeLocation'] ?? null,
                                'street_address'       => $u['streetAddress'] ?? null,
                                'city'                 => $u['city'] ?? null,
                                'postal_code'          => $u['postalCode'] ?? null,
                                'country'              => $u['country'] ?? null,
                                'licenses_count'       => count($licenseSkus),
                                'assigned_licenses'    => $licenseSkus,
                                'raw_data'             => $u,
                            ]
                        );
                        $activeIds[] = $u['id'];
                    }
                });
                $count += count($chunk);
                gc_collect_cycles();
            });

            // ── Handle newly-disabled accounts ──────────────────────────────
            // Done OUTSIDE the transaction so notification email jobs dispatch correctly.
            if (! empty($newlyDisabled)) {
                $this->handleDisabledAccounts($newlyDisabled);
            }

            // Remove users no longer in Azure AD
            if ($count > 0) {
                // Capture removed azure_ids BEFORE deleting so we can notify
                $removedAzureIds = IdentityUser::whereNotIn('azure_id', $activeIds)
                    ->pluck('azure_id')->toArray();
                if (! empty($removedAzureIds)) {
                    $this->handleRemovedAccounts($removedAzureIds);
                }
                IdentityUser::whereNotIn('azure_id', $activeIds)->delete();
            }

            // Fix assigned_licenses from raw_data (Eloquent updateOrCreate has
            // a dirty-check issue with array-cast fields on existing rows)
            IdentityUser::whereNotNull('raw_data')
                ->where('licenses_count', '>', 0)
                ->chunk(200, function ($users) {
                    foreach ($users as $user) {
                        $raw  = $user->raw_data;
                        $skus = [];
                        foreach ($raw['assignedLicenses'] ?? [] as $lic) {
                            if (isset($lic['skuId'])) {
                                $skus[] = $lic['skuId'];
                            }
                        }
                        // Use DB query to bypass Eloquent cast/dirty issues
                        DB::table('identity_users')
                            ->where('id', $user->id)
                            ->update(['assigned_licenses' => json_encode($skus)]);
                    }
                });

            Log::info("IdentitySyncService: Synced {$count} users.");
            return $count;
        } catch (\Throwable $e) {
            $errors[] = 'Users: ' . $e->getMessage();
            Log::error('IdentitySyncService: User sync failed: ' . $e->getMessage());
            return 0;
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Step 4: Group Memberships (Batch API)
    // ─────────────────────────────────────────────────────────────

    public function syncRelationships(array &$errors): void
    {
        try {
            $allGroupIds = IdentityGroup::pluck('azure_id')->all();
            if (empty($allGroupIds)) return;

            // Reset all memberships
            IdentityUser::query()->update(['member_of' => '[]', 'groups_count' => 0]);

            // Fetch memberships via batch API
            $groupMembers = $this->graph->batchGroupMembers($allGroupIds);

            // Build inverse map: userId → [groupId, ...]
            $userMemberOf     = [];
            $groupMemberCounts = [];

            foreach ($groupMembers as $groupId => $userIds) {
                $groupMemberCounts[$groupId] = count($userIds);
                foreach ($userIds as $uid) {
                    $userMemberOf[$uid][] = $groupId;
                }
            }

            // Bulk update users
            foreach (array_chunk(array_keys($userMemberOf), 200) as $chunk) {
                DB::transaction(function () use ($chunk, $userMemberOf) {
                    foreach ($chunk as $uid) {
                        $gids = $userMemberOf[$uid] ?? [];
                        IdentityUser::where('azure_id', $uid)->update([
                            'member_of'    => $gids,
                            'groups_count' => count($gids),
                        ]);
                    }
                });
            }

            // Bulk update group counts
            foreach (array_chunk($allGroupIds, 200) as $chunk) {
                DB::transaction(function () use ($chunk, $groupMemberCounts) {
                    foreach ($chunk as $gid) {
                        IdentityGroup::where('azure_id', $gid)->update([
                            'members_count' => $groupMemberCounts[$gid] ?? 0,
                        ]);
                    }
                });
            }

            Log::info('IdentitySyncService: Group memberships synced.');
        } catch (\Throwable $e) {
            $errors[] = 'Memberships: ' . $e->getMessage();
            Log::error('IdentitySyncService: Membership sync error: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Azure Account Lifecycle Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Called when accounts transition from enabled → disabled in Azure AD.
     * Sets azure_disabled_at on the linked Employee record and notifies IT so
     * the team can arrange device return.
     */
    private function handleDisabledAccounts(array $azureIds): void
    {
        if (empty($azureIds)) return;

        /** @var \App\Services\NotificationService $notifier */
        $notifier = app(\App\Services\NotificationService::class);

        foreach ($azureIds as $azureId) {
            try {
                $employee = \App\Models\Employee::where('azure_id', $azureId)->first();
                if (! $employee) continue;

                // Only stamp once (idempotent)
                if (! $employee->azure_disabled_at) {
                    $employee->update(['azure_disabled_at' => now()]);
                }

                // Collect active device assignments to mention in the alert
                $activeAssets = $employee->activeAssets()->with('device')->get();
                $assetNames   = $activeAssets->map(fn ($a) => $a->device?->name ?? "Asset #{$a->asset_id}")->implode(', ');

                $message = "Azure AD account for {$employee->name} ({$employee->email}) was disabled.";
                if ($activeAssets->isNotEmpty()) {
                    $message .= " {$activeAssets->count()} device(s) still assigned and require return: {$assetNames}.";
                }

                $link = route('admin.employees.show', $employee->id);

                $notifier->notifyViaRules(
                    'account_disabled',
                    "Azure Account Disabled: {$employee->name}",
                    $message,
                    $link,
                    'warning'
                );

                Log::info("IdentitySyncService: Azure account disabled — employee={$employee->name}, azure_id={$azureId}");
            } catch (\Throwable $e) {
                Log::error("IdentitySyncService: handleDisabledAccounts failed for azure_id={$azureId}: " . $e->getMessage());
            }
        }
    }

    /**
     * Called for accounts that were present in the last sync but are absent from
     * the current Azure AD response (i.e. permanently deleted from the directory).
     * Marks the Employee as terminated and records azure_removed_at.
     */
    private function handleRemovedAccounts(array $azureIds): void
    {
        if (empty($azureIds)) return;

        /** @var \App\Services\NotificationService $notifier */
        $notifier = app(\App\Services\NotificationService::class);

        foreach ($azureIds as $azureId) {
            try {
                $employee = \App\Models\Employee::where('azure_id', $azureId)->first();
                if (! $employee) continue;

                // Mark employee as terminated + record removal timestamp
                $employee->update([
                    'azure_removed_at' => now(),
                    'terminated_date'  => $employee->terminated_date ?? now()->toDateString(),
                    'status'           => 'terminated',
                ]);

                // Collect active device assignments
                $activeAssets = $employee->activeAssets()->with('device')->get();
                $assetNames   = $activeAssets->map(fn ($a) => $a->device?->name ?? "Asset #{$a->asset_id}")->implode(', ');

                $message = "Azure AD account for {$employee->name} ({$employee->email}) was permanently removed from the directory. Employee status set to Terminated.";
                if ($activeAssets->isNotEmpty()) {
                    $message .= " Please collect {$activeAssets->count()} device(s): {$assetNames}.";
                }

                $link = route('admin.employees.show', $employee->id);

                $notifier->notifyViaRules(
                    'account_removed',
                    "Azure Account Removed: {$employee->name}",
                    $message,
                    $link,
                    'danger'
                );

                Log::info("IdentitySyncService: Azure account removed — employee={$employee->name}, azure_id={$azureId}");
            } catch (\Throwable $e) {
                Log::error("IdentitySyncService: handleRemovedAccounts failed for azure_id={$azureId}: " . $e->getMessage());
            }
        }
    }

}
