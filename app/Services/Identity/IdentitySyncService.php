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

            // 5. Sync Managers (paginated with $expand)
            $this->syncManagers($stats['errors']);

            $status = count($stats['errors']) > 0 ? 'completed' : 'completed';

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
            $count     = 0;
            $activeIds = [];

            $this->graph->listUsers(function ($chunk) use (&$count, &$activeIds) {
                if (empty($chunk)) return;

                DB::transaction(function () use ($chunk, &$activeIds) {
                    foreach ($chunk as $u) {
                        $licenseSkus = collect($u['assignedLicenses'] ?? [])->pluck('skuId')->all();

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

            // Remove users no longer in Azure AD
            if ($count > 0) {
                IdentityUser::whereNotIn('azure_id', $activeIds)->delete();
            }

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
    // Step 5: Manager Relationships
    // ─────────────────────────────────────────────────────────────

    public function syncManagers(array &$errors): void
    {
        try {
            $this->graph->listUsersWithManager(function ($chunk) {
                foreach ($chunk as $u) {
                    $managerId = $u['manager']['id'] ?? null;

                    if ($managerId) {
                        IdentityUser::where('azure_id', $u['id'])
                            ->update(['manager_azure_id' => $managerId]);
                    }
                }
                gc_collect_cycles();
            });

            Log::info('IdentitySyncService: Manager relationships synced.');
        } catch (\Throwable $e) {
            // Manager sync is non-fatal — some tenants restrict $expand=manager
            $errors[] = 'Managers: ' . $e->getMessage();
            Log::warning('IdentitySyncService: Manager sync failed (non-fatal): ' . $e->getMessage());
        }
    }
}
