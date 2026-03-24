<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ServiceSyncLog;
use App\Models\User;
use App\Services\GdmsBranchMapper;
use App\Services\GdmsService;
use Illuminate\Console\Command;

class SyncGdmsContacts extends Command
{
    protected $signature = 'gdms:sync-contacts';
    protected $description = 'Sync contacts from GDMS SIP accounts (create / update / delete orphans)';

    public function handle(GdmsService $gdms, GdmsBranchMapper $branchMapper): int
    {
        $this->info('Fetching SIP accounts from GDMS...');

        $log = ServiceSyncLog::start('gdms');

        $pageNum      = 1;
        $pageSize     = 200;
        $processed    = 0;
        $failed       = 0;
        $syncedPhones = [];   // every sipUserId seen this run — used for orphan pruning

        do {
            $pageData = $gdms->listSipAccounts($pageNum, $pageSize);

            // GDMS API nests data inside data.result with data.total
            $inner    = $pageData['data'] ?? $pageData;
            $accounts = $inner['result'] ?? $inner['list'] ?? [];
            $total    = $inner['total'] ?? count($accounts);

            if ($pageNum === 1) {
                $pages = (int) ceil($total / $pageSize);
                $this->info("Total accounts: {$total}, pages: {$pages}");
            }

            foreach ($accounts as $acc) {
                $sipUserId   = $acc['sipUserId']      ?? null;
                $displayName = $acc['displayName']    ?? '';
                $sipServer   = $acc['sipServer']      ?? '';
                $email       = $acc['extensionEmail'] ?? null;

                if (! $sipUserId) {
                    continue;
                }

                $syncedPhones[] = (string) $sipUserId;

                // Resolve branch — null if not mapped (avoids FK constraint errors)
                $branchId = null;
                try {
                    $bid = $branchMapper->resolveBranchId($sipServer);
                    if ($bid && Branch::find($bid)) {
                        $branchId = $bid;
                    }
                } catch (\Throwable) {
                    // leave null
                }

                $parts     = preg_split('/\s+/', trim($displayName));
                $firstName = $parts[0] ?? (string) $sipUserId;
                $lastName  = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';

                try {
                    Contact::updateOrCreate(
                        ['phone' => (string) $sipUserId],
                        [
                            'first_name'     => $firstName,
                            'last_name'      => $lastName,
                            'email'          => $email ?: null,
                            'branch_id'      => $branchId,
                            'source'         => 'gdms',
                            'gdms_synced_at' => now(),
                        ]
                    );
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("Failed account {$sipUserId}: " . $e->getMessage());
                }
            }

            $pageNum++;
        } while (! empty($accounts) && isset($total) && $processed < $total);

        // ── Remove GDMS-sourced contacts that no longer exist in GDMS ────────
        // Only contacts marked source=gdms are eligible — manually-added contacts
        // (source=manual) are never touched by the sync.
        $deleted = 0;
        if (! empty($syncedPhones)) {
            $deleted = Contact::where('source', 'gdms')
                ->whereNotIn('phone', $syncedPhones)
                ->delete();

            if ($deleted > 0) {
                $this->warn("Removed {$deleted} orphaned GDMS contact(s) no longer present in GDMS.");
            }
        }

        $this->info("GDMS sync complete. Updated: {$processed}, Removed: {$deleted}, Failed: {$failed}.");

        $log->update([
            'status'         => 'completed',
            'records_synced' => $processed,
            'completed_at'   => now(),
        ]);

        // Activity log — non-critical
        try {
            $adminUser = User::first();
            if ($adminUser) {
                ActivityLog::create([
                    'model_type' => 'System',
                    'model_id'   => 0,
                    'action'     => 'gdms_sync_contacts',
                    'changes'    => [
                        'processed' => $processed,
                        'deleted'   => $deleted,
                        'failed'    => $failed,
                        'source'    => 'gdms',
                        'run_from'  => 'cli',
                    ],
                    'user_id'    => $adminUser->id,
                ]);
            }
        } catch (\Throwable) {
            // Non-critical — don't let activity log failure break the sync
        }

        return Command::SUCCESS;
    }
}
