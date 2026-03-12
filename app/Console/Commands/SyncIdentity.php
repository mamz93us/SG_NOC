<?php

namespace App\Console\Commands;

use App\Jobs\SyncIdentityData;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use Illuminate\Console\Command;

class SyncIdentity extends Command
{
    protected $signature   = 'identity:sync';
    protected $description = 'Synchronise users, licenses, and groups from Microsoft Entra ID (Graph API).';

    public function handle(): int
    {
        // No PHP time limit — this runs as a CLI process, not an HTTP request
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        $settings = Setting::get();

        if (!$settings->identity_sync_enabled) {
            $this->warn('Identity sync is disabled in Settings — skipping.');
            return self::SUCCESS;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            $this->error('Microsoft Graph credentials are not configured in Settings.');
            return self::FAILURE;
        }

        $this->info('Starting identity sync…');

        $log = ServiceSyncLog::start('identity');

        try {
            (new SyncIdentityData())->handle();
            $lastSync = \App\Models\IdentitySyncLog::where('status','completed')->latest()->first();
            $log->update([
                'status'         => 'completed',
                'records_synced' => $lastSync?->users_synced ?? 0,
                'completed_at'   => now(),
            ]);
            $this->info('Identity sync completed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            $this->error('Identity sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
