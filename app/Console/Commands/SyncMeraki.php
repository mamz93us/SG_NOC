<?php

namespace App\Console\Commands;

use App\Jobs\SyncMerakiData;
use App\Models\ServiceSyncLog;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncMeraki extends Command
{
    protected $signature   = 'meraki:sync';
    protected $description = 'Synchronise network devices and data from Meraki API.';

    public function handle(): int
    {
        $settings = Setting::get();

        if (!$settings->meraki_enabled) {
            $this->warn('Meraki integration is disabled — skipping.');
            return self::SUCCESS;
        }

        if (empty($settings->meraki_api_key) || empty($settings->meraki_org_id)) {
            $this->error('Meraki API key or Org ID not configured in Settings.');
            return self::FAILURE;
        }

        $log = ServiceSyncLog::start('meraki');

        // Match the inline-dispatch budget used by the manual-sync controller
        // path so a large org sync isn't killed by PHP's default time limit.
        @set_time_limit(300);

        try {
            $this->info('Starting Meraki sync…');

            // Run the full sync inline (no queue worker on this host).
            // SyncMerakiData upserts network_switches / ports / clients / events
            // and refreshes network_switches.updated_at — which is what the
            // NocAlertEngine stale-switch check reads.
            (new SyncMerakiData())->handle();

            $log->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('SyncMeraki: completed.');
            $this->info('Meraki sync completed.');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);

            Log::error('SyncMeraki: failed — ' . $e->getMessage());
            $this->error('Meraki sync failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
