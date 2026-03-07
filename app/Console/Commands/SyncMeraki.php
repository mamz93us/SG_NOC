<?php

namespace App\Console\Commands;

use App\Models\ServiceSyncLog;
use App\Models\Setting;
use App\Services\Network\MerakiService;
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

        if (empty($settings->meraki_api_key)) {
            $this->error('Meraki API key not configured in Settings.');
            return self::FAILURE;
        }

        $log = ServiceSyncLog::start('meraki');

        try {
            $this->info('Starting Meraki sync…');
            $service = new MerakiService();

            // Pull devices from Meraki
            $devices = $service->getDevices();
            $count   = count($devices ?? []);

            $log->update([
                'status'         => 'completed',
                'records_synced' => $count,
                'completed_at'   => now(),
            ]);

            Log::info("SyncMeraki: completed. Devices: {$count}");
            $this->info("Meraki sync completed. Devices: {$count}");
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
