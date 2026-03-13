<?php

namespace App\Jobs;

use App\Models\IdentityGroup;
use App\Models\IdentityLicense;
use App\Models\IdentitySyncLog;
use App\Models\IdentityUser;
use App\Models\Setting;
use App\Services\Identity\GraphService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SyncIdentityData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // Allow it more time since Graph Batching 800+ groups can be slow
    public int $tries   = 1;

    public function handle(): void
    {
        $settings = \App\Models\Setting::get();

        if (!$settings->identity_sync_enabled) {
            Log::info('SyncIdentityData: disabled — skipping.');
            return;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            Log::warning('SyncIdentityData: Graph credentials not configured — skipping.');
            return;
        }

        Log::info("SyncIdentityData: Job started. Memory: " . ini_get('memory_limit'));

        // Prevent parallel runs
        $lock = Cache::lock('sync_identity_running', 3600);
        if (!$lock->get()) {
            Log::warning('SyncIdentityData: Another sync process is already running — stopping.');
            return;
        }

        try {
            // Memory optimization for large tenants
            ignore_user_abort(true);
            set_time_limit(3600);
            ini_set('memory_limit', '1024M');

            // Use the Premium Service
            $service = new \App\Services\Identity\IdentitySyncService();
            $service->syncAll();

            Log::info('SyncIdentityData: Job completed successfully.');
        } catch (\Throwable $e) {
            Log::error('SyncIdentityData: Job failed: ' . $e->getMessage());
        } finally {
            $lock->release();
        }
    }
}
