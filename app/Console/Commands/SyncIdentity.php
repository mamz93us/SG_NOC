<?php

namespace App\Console\Commands;

use App\Jobs\SyncIdentityData;
use App\Models\IdentitySyncLog;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SyncIdentity extends Command
{
    protected $signature   = 'identity:sync {--force : Force-release any stale lock before syncing}';
    protected $description = 'Synchronise users, licenses, and groups from Microsoft Entra ID (Graph API).';

    public function handle(): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '2048M');

        $settings = Setting::get();

        if (! $settings->identity_sync_enabled) {
            $this->warn('Identity sync is disabled in Settings — skipping.');
            return self::SUCCESS;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            $this->error('Microsoft Graph credentials are not configured in Settings.');
            return self::FAILURE;
        }

        // ── Force-release stale lock if --force flag given ─────────────────
        if ($this->option('force')) {
            Cache::lock('sync_identity_running')->forceRelease();
            // Mark any stuck 'started' DB logs as failed
            IdentitySyncLog::where('status', 'started')
                ->update(['status' => 'failed', 'error_message' => 'Manually force-reset via --force flag.', 'completed_at' => now()]);
            $this->info('Stale lock cleared. Starting fresh sync...');
        }

        // ── Guard: don't start if already running ──────────────────────────
        if (SyncIdentityData::isRunning()) {
            $this->warn('A sync is already running. Use --force to override.');
            return self::FAILURE;
        }

        $this->info('Starting identity sync…');

        $log = ServiceSyncLog::start('identity');

        try {
            (new SyncIdentityData())->handle();

            $lastSync = IdentitySyncLog::where('status', 'completed')->latest()->first();
            $log->update([
                'status'         => 'completed',
                'records_synced' => $lastSync?->users_synced ?? 0,
                'completed_at'   => now(),
            ]);

            $users    = $lastSync?->users_synced    ?? 0;
            $groups   = $lastSync?->groups_synced   ?? 0;
            $licenses = $lastSync?->licenses_synced ?? 0;

            $this->info("Identity sync completed. Users: {$users} | Groups: {$groups} | Licenses: {$licenses}");
            return self::SUCCESS;

        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'sync_already_running') {
                $log->update([
                    'status'        => 'failed',
                    'error_message' => 'Another sync process was already running.',
                    'completed_at'  => now(),
                ]);
                $this->warn('Sync skipped — another process is already running.');
                return self::FAILURE;
            }

            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            $this->error('Identity sync failed: ' . $e->getMessage());
            return self::FAILURE;

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
