<?php

namespace App\Jobs;

use App\Models\IdentityGroup;
use App\Models\IdentityLicense;
use App\Models\IdentitySyncLog;
use App\Models\IdentityUser;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SyncIdentityData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // Allow it more time since Graph Batching 800+ groups can be slow
    public int $tries   = 1;

    public function handle(): void
    {
        $settings = Setting::get();

        if (!$settings->identity_sync_enabled) {
            Log::info('SyncIdentityData: disabled — skipping.');
            return;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            Log::warning('SyncIdentityData: Graph credentials not configured — skipping.');
            return;
        }

        // Detect and prevent parallel runs using an atomic Cache lock (1 hour)
        $lock = Cache::lock('sync_identity_running', 3600);
        if (!$lock->get()) {
            Log::warning('SyncIdentityData: Another sync process is already running (Cache Lock) — stopping this one.');
            return;
        }

        // Also check DB as a secondary safety
        $alreadyRunningInDb = IdentitySyncLog::where('status', 'started')
            ->where('started_at', '>', now()->subHours(1))
            ->exists();

        if ($alreadyRunningInDb) {
            $lock->release();
            Log::warning('SyncIdentityData: Already running in DB — stopping.');
            return;
        }

        // Clean up any orphaned "started" entries from interrupted old runs
        IdentitySyncLog::where('status', 'started')
            ->where('started_at', '<', now()->subHours(2))
            ->update([
                'status'        => 'failed',
                'error_message' => 'Sync aborted — process timed out or was interrupted.',
                'completed_at'  => now(),
            ]);

        $log = IdentitySyncLog::create([
            'type'       => 'full',
            'status'     => 'started',
            'started_at' => now(),
        ]);

        // Keep running even if the HTTP client (NGINX) closes the connection early.
        // Without this, a fastcgi_read_timeout would silently kill the process mid-sync,
        // leaving the log entry permanently in "started" state.
        ignore_user_abort(true);
        set_time_limit(3600); // 1 hour

        // Increase memory for the sync job — Graph returns 1000+ users and 800+
        // groups. Some response payloads can be large.
        ini_set('memory_limit', '1024M');
        gc_enable(); // Ensure garbage collection is active

        $graph  = new GraphService();
        $errors = [];

        $licenseCount = 0;
        $groupCount   = 0;
        $userCount    = 0;

        Log::info('SyncIdentityData: started. Memory limit: ' . ini_get('memory_limit'));

        // ── 1. Sync licenses (non-fatal) ───────────────────────────
        try {
            $skus = $graph->listSubscribedSkus();
            DB::transaction(function () use ($skus) {
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
                }
            });
            $licenseCount = count($skus);

            // Remove licenses no longer in Azure
            $activeSkuIds = collect($skus)->pluck('skuId')->filter()->all();
            if (!empty($activeSkuIds)) {
                $deletedLicenses = IdentityLicense::whereNotIn('sku_id', $activeSkuIds)->delete();
                if ($deletedLicenses) Log::info("SyncIdentityData: removed {$deletedLicenses} stale license(s).");
            }

            unset($skus); // free memory
            $log->update(['licenses_synced' => $licenseCount]);
            Log::info('SyncIdentityData: licenses OK (' . $licenseCount . ')');
        } catch (\Throwable $e) {
            $errors[] = 'Licenses: ' . $e->getMessage();
            Log::error('SyncIdentityData: license sync failed — ' . $e->getMessage());
        }

        // ── 2. Sync groups (non-fatal) ─────────────────────────────
        try {
            $activeGroupIds = [];
            $graph->listGroups(function($groupChunk) use (&$activeGroupIds, &$groupCount) {
                DB::transaction(function () use ($groupChunk, &$activeGroupIds) {
                    foreach ($groupChunk as $group) {
                        IdentityGroup::updateOrCreate(
                            ['azure_id' => $group['id']],
                            [
                                'display_name'     => $group['displayName'],
                                'description'      => $group['description'] ?? null,
                                'group_type'       => in_array('Unified', $group['groupTypes'] ?? []) ? 'Unified' : null,
                                'mail_enabled'     => $group['mailEnabled'] ?? false,
                                'security_enabled' => $group['securityEnabled'] ?? true,
                            ]
                        );
                        $activeGroupIds[] = $group['id'];
                    }
                });
                $groupCount += count($groupChunk);
            });

            // Remove groups no longer in Azure
            if (!empty($activeGroupIds)) {
                $deletedGroups = IdentityGroup::whereNotIn('azure_id', $activeGroupIds)->delete();
                if ($deletedGroups) Log::info("SyncIdentityData: removed {$deletedGroups} stale group(s).");
            }

            unset($activeGroupIds);
            gc_collect_cycles();
            $log->update(['groups_synced' => $groupCount]);
            Log::info('SyncIdentityData: groups OK (' . $groupCount . ')');
        } catch (\Throwable $e) {
            $errors[] = 'Groups: ' . $e->getMessage();
            Log::error('SyncIdentityData: group sync failed — ' . $e->getMessage());
        }

        // ── 3. Sync users (non-fatal) ──────────────────────────────
        try {
            $activeUserIds = [];
            $graph->listUsers(function($userChunk) use (&$activeUserIds, &$userCount) {
                DB::transaction(function () use ($userChunk, &$activeUserIds) {
                    foreach ($userChunk as $u) {
                        $activeUserIds[] = $u['id'];
                        $licenseSkus = collect($u['assignedLicenses'] ?? [])->pluck('skuId')->all();

                        $dbUser = IdentityUser::where('azure_id', $u['id'])
                            ->orWhere('user_principal_name', $u['userPrincipalName'])
                            ->first();

                        if (!$dbUser) {
                            $dbUser = new IdentityUser();
                        }

                        $dbUser->fill([
                            'azure_id'            => $u['id'],
                            'manager_azure_id'    => $u['manager_id'] ?? null,
                            'display_name'        => $u['displayName'],
                            'user_principal_name' => $u['userPrincipalName'],
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
                        ]);
                        $dbUser->save();
                    }
                });
                $userCount += count($userChunk);
            });

            // Remove users no longer in Azure
            if (!empty($activeUserIds)) {
                $deletedUsers = IdentityUser::whereNotIn('azure_id', $activeUserIds)->delete();
                if ($deletedUsers) Log::info("SyncIdentityData: removed {$deletedUsers} stale user(s).");
            }

            unset($activeUserIds);
            gc_collect_cycles();
            $log->update(['users_synced' => $userCount]);
            Log::info('SyncIdentityData: users OK (' . $userCount . ')');
        } catch (\Throwable $e) {
            $errors[] = 'Users: ' . $e->getMessage();
            Log::error('SyncIdentityData: user sync failed — ' . $e->getMessage());
        }

        // ── 3b. Back-fill manager relationships (non-fatal, separate pass) ──
        try {
            $graph->listUserManagers(function($map) {
                if (!empty($map)) {
                    DB::transaction(function () use ($map) {
                        foreach ($map as $userId => $managerId) {
                            IdentityUser::where('azure_id', $userId)
                                ->update(['manager_azure_id' => $managerId]);
                        }
                    });
                }
                gc_collect_cycles();
            });
            Log::info('SyncIdentityData: manager relationships OK');
        } catch (\Throwable $e) {
            Log::warning('SyncIdentityData: manager sync skipped — ' . $e->getMessage());
        }

        // ── 4. Sync group memberships (non-fatal) ──────────────────
        try {
            $allGroupIds  = IdentityGroup::pluck('azure_id')->all();
            
            // Reset all memberships locally before re-syncing.
            IdentityUser::query()->update(['member_of' => '[]', 'groups_count' => 0]);
            IdentityGroup::query()->update(['members_count' => 0]);

            $processedGroups = 0;
            $totalGroups = count($allGroupIds);

            $graph->batchGroupMembers($allGroupIds, function($chunkResult) use (&$processedGroups, $totalGroups) {
                // $chunkResult is [groupId => [userId, ...]] for 5 groups
                $processedGroups += count($chunkResult);
                Log::debug("SyncIdentityData: membership progress {$processedGroups}/{$totalGroups} groups...");

                $userMap = [];
                $counts  = [];
                foreach ($chunkResult as $gid => $uids) {
                    $counts[$gid] = count($uids);
                    foreach ($uids as $uid) {
                        $userMap[$uid][] = $gid;
                    }
                }

                DB::transaction(function() use ($userMap, $counts) {
                    // Optimized batch fetch users in this chunk
                    $uIds = array_keys($userMap);
                    $users = IdentityUser::whereIn('azure_id', $uIds)->get(['id', 'azure_id', 'member_of', 'groups_count']);
                    
                    foreach ($users as $u) {
                        $newGroups = $userMap[$u->azure_id];
                        $current   = $u->member_of ?? [];
                        $merged    = array_unique(array_merge($current, $newGroups));
                        $u->update([
                            'member_of'    => $merged,
                            'groups_count' => count($merged)
                        ]);
                    }

                    // Update group counts
                    foreach ($counts as $gid => $c) {
                        IdentityGroup::where('azure_id', $gid)->increment('members_count', $c);
                    }
                });
                gc_collect_cycles();
            });

            Log::info('SyncIdentityData: group memberships OK');
        } catch (\Throwable $e) {
            $errors[] = 'Group memberships: ' . $e->getMessage();
            Log::error('SyncIdentityData: group membership sync failed — ' . $e->getMessage());
        }

        // A sync is 'failed' only if ALL three phases produced zero records AND there were errors.
        // If at least one phase succeeded (users, groups, or licenses), mark as 'completed'.
        $status       = empty($errors) ? 'completed' : ($userCount === 0 && $groupCount === 0 && $licenseCount === 0 ? 'failed' : 'completed');
        $errorMessage = empty($errors) ? null : implode('; ', $errors);

        $log->update([
            'status'          => $status,
            'users_synced'    => $userCount,
            'licenses_synced' => $licenseCount,
            'groups_synced'   => $groupCount,
            'error_message'   => $errorMessage,
            'completed_at'    => now(),
        ]);

        Log::info("SyncIdentityData: {$status}. Users: {$userCount}, Licenses: {$licenseCount}, Groups: {$groupCount}" . ($errorMessage ? " | Errors: {$errorMessage}" : ''));
        
        // Release the lock at the very end
        $lock->release();
    }
}
