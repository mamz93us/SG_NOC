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

class SyncIdentityData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
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

        // Clean up any orphaned "started" entries from interrupted previous runs
        IdentitySyncLog::where('status', 'started')
            ->where('started_at', '<', now()->subMinutes(10))
            ->update([
                'status'        => 'failed',
                'error_message' => 'Sync aborted — process was interrupted before completion.',
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
        set_time_limit(600);

        // Increase memory for the sync job — Graph returns 888+ users and 842+
        // groups. 128 MB (server default) is not enough for two full paginated
        // result sets in memory simultaneously.
        ini_set('memory_limit', '256M');

        $graph  = new GraphService();
        $errors = [];

        $licenseCount = 0;
        $groupCount   = 0;
        $userCount    = 0;

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
            unset($skus); // free memory
            Log::info('SyncIdentityData: licenses OK (' . $licenseCount . ')');
        } catch (\Throwable $e) {
            $errors[] = 'Licenses: ' . $e->getMessage();
            Log::error('SyncIdentityData: license sync failed — ' . $e->getMessage());
        }

        // ── 2. Sync groups (non-fatal) ─────────────────────────────
        try {
            $groups = $graph->listGroups();
            DB::transaction(function () use ($groups) {
                foreach ($groups as $group) {
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
                }
            });
            $groupCount = count($groups);
            unset($groups); // free memory before user sync
            Log::info('SyncIdentityData: groups OK (' . $groupCount . ')');
        } catch (\Throwable $e) {
            $errors[] = 'Groups: ' . $e->getMessage();
            Log::error('SyncIdentityData: group sync failed — ' . $e->getMessage());
        }

        // ── 3. Sync users (non-fatal) ──────────────────────────────
        try {
            $users = $graph->listUsers();
            DB::transaction(function () use ($users) {
                foreach ($users as $user) {
                    $licenseSkus = collect($user['assignedLicenses'] ?? [])->pluck('skuId')->all();

                    IdentityUser::updateOrCreate(
                        ['azure_id' => $user['id']],
                        [
                            'manager_azure_id'    => $user['manager_id'] ?? null,
                            'display_name'        => $user['displayName'],
                            'user_principal_name' => $user['userPrincipalName'],
                            'mail'                => $user['mail'] ?? null,
                            'job_title'           => $user['jobTitle'] ?? null,
                            'department'          => $user['department'] ?? null,
                            'company_name'        => $user['companyName'] ?? null,
                            'account_enabled'     => $user['accountEnabled'] ?? true,
                            'usage_location'      => $user['usageLocation'] ?? null,
                            'phone_number'        => $user['businessPhones'][0] ?? null,
                            'mobile_phone'        => $user['mobilePhone'] ?? null,
                            'office_location'     => $user['officeLocation'] ?? null,
                            'street_address'      => $user['streetAddress'] ?? null,
                            'city'                => $user['city'] ?? null,
                            'postal_code'         => $user['postalCode'] ?? null,
                            'country'             => $user['country'] ?? null,
                            'licenses_count'      => count($licenseSkus),
                            'assigned_licenses'   => $licenseSkus,
                            'raw_data'            => $user,
                        ]
                    );
                }
            });
            $userCount = count($users);
            unset($users); // free ~888 full user objects before manager + group passes
            Log::info('SyncIdentityData: users OK (' . $userCount . ')');
        } catch (\Throwable $e) {
            $errors[] = 'Users: ' . $e->getMessage();
            Log::error('SyncIdentityData: user sync failed — ' . $e->getMessage());
        }

        // ── 3b. Back-fill manager relationships (non-fatal, separate pass) ──
        // listUserManagers() fetches only id + manager expand — much lighter
        // than including it in the main users query.
        try {
            $managerMap = $graph->listUserManagers();
            if (!empty($managerMap)) {
                DB::transaction(function () use ($managerMap) {
                    foreach ($managerMap as $userId => $managerId) {
                        IdentityUser::where('azure_id', $userId)
                            ->update(['manager_azure_id' => $managerId]);
                    }
                });
                Log::info('SyncIdentityData: manager relationships OK (' . count($managerMap) . ')');
            }
        } catch (\Throwable $e) {
            // Non-fatal — manager data is supplementary
            Log::warning('SyncIdentityData: manager sync skipped — ' . $e->getMessage());
        }

        // ── 4. Sync group memberships via Graph Batch API (non-fatal) ──
        try {
            $allGroupIds  = IdentityGroup::pluck('azure_id')->all();
            $groupMembers = $graph->batchGroupMembers($allGroupIds);

            $userMemberOf      = [];
            $groupMemberCounts = [];
            foreach ($groupMembers as $groupId => $userIds) {
                $groupMemberCounts[$groupId] = count($userIds);
                foreach ($userIds as $uid) {
                    $userMemberOf[$uid][] = $groupId;
                }
            }

            DB::transaction(function () use ($userMemberOf) {
                foreach ($userMemberOf as $userId => $groupIds) {
                    IdentityUser::where('azure_id', $userId)->update([
                        'member_of'    => $groupIds,
                        'groups_count' => count($groupIds),
                    ]);
                }
            });

            // ── 5. Back-fill members_count on groups ───────────────────
            DB::transaction(function () use ($groupMemberCounts, $allGroupIds) {
                foreach ($groupMemberCounts as $gid => $count) {
                    IdentityGroup::where('azure_id', $gid)->update(['members_count' => $count]);
                }
                IdentityGroup::whereNotIn('azure_id', array_keys($groupMemberCounts))
                    ->update(['members_count' => 0]);
            });
            Log::info('SyncIdentityData: group memberships OK');
        } catch (\Throwable $e) {
            $errors[] = 'Group memberships: ' . $e->getMessage();
            Log::error('SyncIdentityData: group membership sync failed — ' . $e->getMessage());
        }

        // ── Finalise log ───────────────────────────────────────────
        $status       = empty($errors) ? 'completed' : (empty($users) && empty($groups) && empty($skus) ? 'failed' : 'completed');
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
    }
}
