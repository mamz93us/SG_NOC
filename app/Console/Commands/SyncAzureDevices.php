<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use App\Services\AzureDeviceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Inline (no-queue) version of the Azure / Intune device sync.
 *
 * The web route dispatches a closure to the queue, which fails when
 * no queue worker is running.  This command runs the same
 * AzureDeviceService::syncDevices() call directly in the process,
 * making it safe to call from cron or the CLI.
 *
 * After this runs, azure_devices.intune_managed_device_id is populated
 * so that `intune:sync-net-data` can match script results correctly.
 */
class SyncAzureDevices extends Command
{
    protected $signature   = 'itam:sync-devices {--force : Release stale lock before syncing}';
    protected $description = 'Sync Azure AD / Intune managed devices into the azure_devices table (runs inline, no queue).';

    public function handle(): int
    {
        set_time_limit(0);
        ini_set('memory_limit', '512M');

        $settings = Setting::get();

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            $this->error('Microsoft Graph credentials are not configured in Settings.');
            return self::FAILURE;
        }

        if ($this->option('force')) {
            Cache::lock('itam_sync_devices_running')->forceRelease();
        }

        $lock = Cache::lock('itam_sync_devices_running', 1800);

        if (! $lock->get()) {
            $this->warn('A device sync is already running. Use --force to override.');
            return self::FAILURE;
        }

        $this->info('Starting Azure / Intune device sync…');
        $log = ServiceSyncLog::start('itam_devices');

        try {
            $service = new AzureDeviceService();
            $result  = $service->syncDevices();

            $log->update([
                'status'         => 'completed',
                'records_synced' => $result['synced'],
                'completed_at'   => now(),
            ]);

            ActivityLog::log(
                "Azure device sync (CLI): {$result['synced']} synced, " .
                "{$result['new']} new, {$result['auto_linked']} auto-linked"
            );

            $this->info(
                "✅ Device sync completed. " .
                "Synced: {$result['synced']} | New: {$result['new']} | Auto-linked: {$result['auto_linked']}"
            );

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('itam:sync-devices failed: ' . $e->getMessage());
            $this->error('Sync failed: ' . $e->getMessage());
            return self::FAILURE;

        } finally {
            $lock->release();
        }
    }
}
