<?php

namespace App\Console\Commands\EmailMarketing;

use App\Services\EmailMarketing\DynamicListSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncDynamicListsCommand extends Command
{
    protected $signature = 'email-marketing:sync-dynamic-lists';

    protected $description = 'Reconcile every dynamic email list (auto_domain) against the employees table.';

    public function handle(DynamicListSyncService $service): int
    {
        try {
            $totals = $service->syncAll();
        } catch (\Throwable $e) {
            Log::error('Dynamic list sync failed: '.$e->getMessage());
            $this->error('Sync failed: '.$e->getMessage());

            return Command::FAILURE;
        }

        $this->info(sprintf(
            'Synced %d dynamic list(s): %d added, %d removed.',
            $totals['lists'],
            $totals['added'],
            $totals['removed'],
        ));

        return Command::SUCCESS;
    }
}
