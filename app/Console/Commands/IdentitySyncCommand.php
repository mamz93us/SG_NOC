<?php

namespace App\Console\Commands;

use App\Jobs\SyncIdentityData;
use Illuminate\Console\Command;

class IdentitySyncCommand extends Command
{
    protected $signature = 'identity:sync';
    protected $description = 'Run a full Azure AD / Entra ID identity sync (users, groups, licenses)';

    public function handle(): int
    {
        $this->info('Starting Identity Sync...');

        try {
            (new SyncIdentityData())->handle();
            $this->info('Identity Sync completed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Identity Sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
