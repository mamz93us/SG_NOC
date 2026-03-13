<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\ActivityLog;
use App\Models\ServiceSyncLog;
use App\Services\GdmsService;
use App\Services\GdmsBranchMapper;
use Illuminate\Console\Command;
       use App\Models\User;

class SyncGdmsContacts extends Command
{
    protected $signature = 'gdms:sync-contacts';
    protected $description = 'Sync contacts from GDMS SIP accounts into local contacts table';

    public function handle(GdmsService $gdms, GdmsBranchMapper $branchMapper): int
    {
        $this->info('Fetching SIP accounts from GDMS...');

        $log = ServiceSyncLog::start('gdms');

        $pageNum   = 1;
        $pageSize  = 200;
        $processed = 0;

        $failed = 0;

        do {
            $pageData = $gdms->listSipAccounts($pageNum, $pageSize);
            
            // GDMS API returns the items inside 'result' or 'list'
            $accounts = $pageData['list'] ?? $pageData['result'] ?? [];
            $total    = $pageData['total'] ?? count($accounts);

            if ($pageNum === 1) {
                $pages = (int) ceil($total / $pageSize);
                $this->info("Total accounts: {$total}, pages: {$pages}");
            }

            foreach ($accounts as $acc) {
                $sipUserId   = $acc['sipUserId'] ?? null;
                $displayName = $acc['displayName'] ?? '';
                $sipServer   = $acc['sipServer'] ?? '';
                $email       = $acc['extensionEmail'] ?? null;

                if (!$sipUserId) {
                    continue;
                }

                // Resolve branch — null if not mapped (avoids FK constraint errors)
                try {
                    $branchId = $branchMapper->resolveBranchId($sipServer);
                    // Verify branch actually exists, otherwise set null
                    if (!\App\Models\Branch::find($branchId)) {
                        $branchId = null;
                    }
                } catch (\Throwable) {
                    $branchId = null;
                }

                $parts     = preg_split('/\s+/', trim($displayName));
                $firstName = $parts[0] ?? (string) $sipUserId;
                $lastName  = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';

                try {
                    Contact::updateOrCreate(
                        ['phone' => (string) $sipUserId],
                        [
                            'first_name' => $firstName,
                            'last_name'  => $lastName,
                            'email'      => $email ?: null,
                            'branch_id'  => $branchId,
                        ]
                    );
                    $processed++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->error("Failed account {$sipUserId}: " . $e->getMessage());
                }
            }

            $pageNum++;
        } while (!empty($accounts) && isset($total) && $processed < $total);

        $this->info("GDMS contacts sync complete. Processed: {$processed}, Failed: {$failed}.");

        $log->update([
            'status'         => 'completed',
            'records_synced' => $processed,
            'completed_at'   => now(),
        ]);

// Log activity — use the first admin user if one exists, otherwise skip
$adminUser = \App\Models\User::where('is_admin', true)->first()
          ?? \App\Models\User::first();
if ($adminUser) {
    try {
        \App\Models\ActivityLog::create([
            'model_type' => 'System',
            'model_id'   => 0,
            'action'     => 'gdms_sync_contacts',
            'changes'    => ['processed' => $processed, 'source' => 'gdms', 'run_from' => 'cli'],
            'user_id'    => $adminUser->id,
        ]);
    } catch (\Throwable) {
        // Non-critical — don't let activity log failure break the sync
    }
}


        return Command::SUCCESS;
    }
}
