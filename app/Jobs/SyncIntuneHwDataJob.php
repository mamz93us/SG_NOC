<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncIntuneHwDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800; // 30 minutes
    public int $tries   = 1;

    /**
     * Check whether a sync is currently queued / running.
     */
    public static function isRunning(): bool
    {
        $lock = Cache::lock('intune_hw_sync_running', 1800);
        if ($lock->get()) {
            $lock->release();
            return false;
        }
        return true;
    }

    public function handle(): void
    {
        $scriptId = Setting::get()->intune_net_data_script_id ?? null;
        if (! $scriptId) {
            Log::warning('SyncIntuneHwDataJob: intune_net_data_script_id not configured — aborting.');
            return;
        }

        $lock = Cache::lock('intune_hw_sync_running', 1800);
        if (! $lock->get()) {
            Log::warning('SyncIntuneHwDataJob: Another HW sync is already running — skipping.');
            return;
        }

        try {
            ignore_user_abort(true);
            set_time_limit(0);

            Log::info('SyncIntuneHwDataJob: started.');

            Artisan::call('intune:sync-net-data', ['--script-id' => $scriptId]);
            $output = Artisan::output();

            preg_match('/Updated:\s*(\d+)/', $output, $m);
            $count = $m[1] ?? '?';

            ActivityLog::log("Intune HW data sync (background): {$count} device(s) updated.");
            Log::info("SyncIntuneHwDataJob: complete — {$count} device(s) updated.");

        } catch (\Throwable $e) {
            Log::error('SyncIntuneHwDataJob failed: ' . $e->getMessage());
            ActivityLog::log('Intune HW data sync (background) FAILED: ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
