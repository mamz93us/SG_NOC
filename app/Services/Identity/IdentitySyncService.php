<?php

namespace App\Services\Identity;

use App\Models\ActivityLog;
use App\Models\IdentityGroup;
use App\Models\IdentityLicense;
use App\Models\IdentitySyncLog;
use App\Models\IdentityUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Premium Identity Sync Service
 * Handles bulk synchronization of Users, Groups, and Licenses from Entra ID (Azure AD).
 * Architected for reliability on resource-constrained systems (VPS).
 */
class IdentitySyncService
{
    protected GraphService $graph;

    public function __construct(?GraphService $graph = null)
    {
        $this->graph = $graph ?? new GraphService();
    }

    /**
     * Run the full synchronization process.
     */
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
            // 1. Sync Licenses
            $stats['licenses'] = $this->syncLicenses($stats['errors']);
            $detailedLog->update(['licenses_synced' => $stats['licenses']]);

            // 2. Sync Groups
            $stats['groups'] = $this->syncGroups($stats['errors']);
            $detailedLog->update(['groups_synced' => $stats['groups']]);

            // 3. Sync Users
            $stats['users'] = $this->syncUsers($stats['errors']);
            $detailedLog->update(['users_synced' => $stats['users']]);

            // 4. Sync Relationships (Managers and Group Memberships)
            $this->syncRelationships($stats['errors']);

            // Finalize
            $status = empty($stats['errors']) ? 'completed' : 'partially_failed';
            if ($stats['users'] === 0 && $stats['groups'] === 0 && !empty($stats['errors'])) {
                $status = 'failed';
            }

            $detailedLog->update([
                'status'        => $status === 'partially_failed' ? 'completed' : $status,
                'error_message' => empty($stats['errors']) ? null : implode('; ', $stats['errors']),
                'completed_at'  => now(),
            ]);

            ActivityLog::log(
                'Identity Sync',
                "Full synchronization completed: {$stats['users']} users, {$stats['groups']} groups, {$stats['licenses']} licenses.",
                $status === 'completed' ? 'success' : 'warning'
            );

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

        return $stats;
    }

    /**
     * Sync Subscribed SKUs (Licenses).
     */
    protected function syncLicenses(array &$errors): int
    {
        try {
            $skus = $this->graph->listSubscribedSkus();
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

            // Cleanup stale
            IdentityLicense::whereNotIn('sku_id', $syncedIds)->delete();
            
            Log::info("IdentitySyncService: Licensed synced (" . count($syncedIds) . ")");
            return count($syncedIds);
        } catch (\Throwable $e) {
            $errors[] = 'Licenses: ' . $e->getMessage();
            Log::error('IdentitySyncService: License sync error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sync Groups.
     */
    protected function syncGroups(array &$errors): int
    {
        try {
            $count = 0;
            $activeIds = [];

            $this->graph->listGroups(function($chunk) use (&$count, &$activeIds) {
                DB::transaction(function () use ($chunk, &$activeIds) {
                    foreach ($chunk as $g) {
                        IdentityGroup::updateOrCreate(
                            ['azure_id' => $g['id']],
                            [
                                'display_name'     => $g['displayName'],
                                'description'      => $g['description'] ?? null,
                                'group_type'       => in_array('Unified', $g['groupTypes'] ?? []) ? 'Unified' : null,
                                'mail_enabled'     => $g['mailEnabled'] ?? false,
                                'security_enabled' => $g['securityEnabled'] ?? true,
                            ]
                        );
                        $activeIds[] = $g['id'];
                    }
                });
                $count += count($chunk);
                gc_collect_cycles();
            });

            // Cleanup stale
            IdentityGroup::whereNotIn('azure_id', $activeIds)->delete();

            Log::info("IdentitySyncService: Groups synced ({$count})");
            return $count;
        } catch (\Throwable $e) {
            $errors[] = 'Groups: ' . $e->getMessage();
            Log::error('IdentitySyncService: Group sync error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sync Users.
     */
    protected function syncUsers(array &$errors): int
    {
        try {
            $count = 0;
            $activeIds = [];

            $this->graph->listUsers(function($chunk) use (&$count, &$activeIds) {
                DB::transaction(function () use ($chunk, &$activeIds) {
                    foreach ($chunk as $u) {
                        $licenseSkus = collect($u['assignedLicenses'] ?? [])->pluck('skuId')->all();
                        
                        IdentityUser::updateOrCreate(
                            ['azure_id' => $u['id']],
                            [
                                'user_principal_name' => $u['userPrincipalName'],
                                'display_name'        => $u['displayName'],
                                'mail'                => $u['mail'] ?? null,
                                'job_title'           => $u['jobTitle'] ?? null,
                                'department'          => $u['department'] ?? null,
                                'company_name'        => $u['companyName'] ?? null,
                                'account_enabled'     => $u['accountEnabled'] ?? true,
                                'usage_location'      => $u['usageLocation'] ?? null,
                                'phone_number'        => $u['businessPhones'][0] ?? null,
                                'mobile_phone'        => $u['mobilePhone'] ?? null,
                                'office_location'     => $u['officeLocation'] ?? null,
                                'street_address'      => $u['streetAddress'] ?? null,
                                'city'                => $u['city'] ?? null,
                                'postal_code'         => $u['postalCode'] ?? null,
                                'country'             => $u['country'] ?? null,
                                'licenses_count'      => count($licenseSkus),
                                'assigned_licenses'   => $licenseSkus,
                                'raw_data'            => $u,
                            ]
                        );
                        $activeIds[] = $u['id'];
                    }
                });
                $count += count($chunk);
                gc_collect_cycles();
            });

            // Cleanup stale
            IdentityUser::whereNotIn('azure_id', $activeIds)->delete();

            Log::info("IdentitySyncService: Users synced ({$count})");
            return $count;
        } catch (\Throwable $e) {
            $errors[] = 'Users: ' . $e->getMessage();
            Log::error('IdentitySyncService: User sync error: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Sync Relationships (Bulk Manager and Group Membership updates).
     * This uses a high-performance in-memory mapping to avoid disk thrashing.
     */
    protected function syncRelationships(array &$errors): void
    {
        // 1. Managers
        try {
            $this->graph->listUserManagers(function($map) {
                if (empty($map)) return;
                DB::transaction(function () use ($map) {
                    foreach ($map as $userId => $mgrId) {
                        IdentityUser::where('azure_id', $userId)->update(['manager_azure_id' => $mgrId]);
                    }
                });
            });
            Log::info('IdentitySyncService: Managers synced');
        } catch (\Throwable $e) {
            $errors[] = 'Managers: ' . $e->getMessage();
        }

        // 2. Group Memberships — user-centric (800 users = ~40 batch calls vs. thousands for group-centric)
        try {
            $allUserIds = IdentityUser::pluck('azure_id')->all();
            if (empty($allUserIds)) {
                Log::info('IdentitySyncService: No users to sync memberships for.');
                return;
            }

            // Reset local state
            IdentityUser::query()->update(['member_of' => '[]', 'groups_count' => 0]);
            IdentityGroup::query()->update(['members_count' => 0]);

            // [userId => [groupId, ...]], [groupId => count]
            $membershipMap = [];
            $groupCounts   = [];

            // Build the set of known (security) group IDs so we only count relevant groups
            $knownGroupIds = IdentityGroup::pluck('azure_id')->flip()->all(); // id => true

            $this->graph->batchUserMemberships($allUserIds, function($chunk) use (&$membershipMap, &$groupCounts, $knownGroupIds) {
                foreach ($chunk as $uid => $allGids) {
                    // Keep only groups that exist in our identity_groups table (security groups)
                    $gids = array_values(array_filter($allGids, fn($gid) => isset($knownGroupIds[$gid])));
                    $membershipMap[$uid] = $gids;
                    foreach ($gids as $gid) {
                        $groupCounts[$gid] = ($groupCounts[$gid] ?? 0) + 1;
                    }
                }
            });

            // Bulk update users in chunks of 200
            foreach (array_chunk(array_keys($membershipMap), 200) as $chunk) {
                DB::transaction(function () use ($chunk, $membershipMap) {
                    $users = IdentityUser::whereIn('azure_id', $chunk)->get();
                    foreach ($users as $u) {
                        $groups = $membershipMap[$u->azure_id] ?? [];
                        $u->update(['member_of' => $groups, 'groups_count' => count($groups)]);
                    }
                });
            }

            // Bulk update group member counts
            foreach (array_chunk(array_keys($groupCounts), 200) as $chunk) {
                DB::transaction(function () use ($chunk, $groupCounts) {
                    foreach ($chunk as $gid) {
                        IdentityGroup::where('azure_id', $gid)->update(['members_count' => $groupCounts[$gid]]);
                    }
                });
            }

            Log::info('IdentitySyncService: Group memberships synced (' . count($membershipMap) . ' users processed)');
        } catch (\Throwable $e) {
            $errors[] = 'Memberships: ' . $e->getMessage();
            Log::error('IdentitySyncService: Membership sync error: ' . $e->getMessage());
        }
    }
}
