<?php

namespace App\Jobs;

use App\Services\Identity\IdentitySyncService;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncIdentityData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200; // 2 hours
    public int $tries   = 1;

    /**
     * Check if a sync is currently running (non-destructive — does NOT acquire the lock).
     */
    public static function isRunning(): bool
    {
        // Try to acquire, then immediately release if we got it
        $lock = Cache::lock('sync_identity_running', 7200);
        if ($lock->get()) {
            $lock->release();
            return false; // We got the lock → nothing else is running
        }
        return true; // Couldn't get the lock → something is running
    }

    public function handle(): void
    {
        $settings = Setting::get();

        if (!$settings->identity_sync_enabled) {
            Log::info('SyncIdentityData: disabled — skipping.');
            return;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            Log::warning('SyncIdentityData: Graph credentials not configured — skipping.');
            return;
        }

        $lock = Cache::lock('sync_identity_running', 7200);

        if (!$lock->get()) {
            Log::warning('SyncIdentityData: Another sync process is already running; stopping this one.');
            return;
        }

        try {
            ignore_user_abort(true);
            set_time_limit(0);
            ini_set('memory_limit', '2048M');

            Log::info('SyncIdentityData: Job started.');

            $service = new IdentitySyncService();
            $service->syncAll();

            Log::info('SyncIdentityData: Job completed.');

        } catch (\Throwable $e) {
            Log::error('SyncIdentityData failed: ' . $e->getMessage());
            throw $e;
        } finally {
            $lock->release();
        }
    }
}
