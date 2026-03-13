<?php

namespace App\Jobs;

use App\Models\IdentitySyncLog;
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

    public int $timeout = 7200; // 2 hours max
    public int $tries   = 1;

    /**
     * Check whether a sync is currently holding the cache lock,
     * WITHOUT actually acquiring it.
     */
    public static function isRunning(): bool
    {
        // Lock TTL is 7200s; if it can't be instantly acquired, something is running.
        $probe = Cache::lock('sync_identity_running', 1);
        if ($probe->get()) {
            $probe->release();
            return false; // lock was free
        }
        return true; // lock is held
    }

    public function handle(): void
    {
        $settings = Setting::get();

        if (! $settings->identity_sync_enabled) {
            Log::info('SyncIdentityData: disabled — skipping.');
            return;
        }

        if (empty($settings->graph_tenant_id) || empty($settings->graph_client_id) || empty($settings->graph_client_secret)) {
            Log::warning('SyncIdentityData: Graph credentials not configured — skipping.');
            return;
        }

        Log::info('SyncIdentityData: Job started. Memory: ' . ini_get('memory_limit'));

        // ── Prevent parallel runs ──────────────────────────────────────────
        // TTL = 7200s (2 h). If the process is killed, the lock auto-expires
        // after 2 hours so the next scheduled run can proceed.
        $lock = Cache::lock('sync_identity_running', 7200);
        if (! $lock->get()) {
            Log::warning('SyncIdentityData: Another sync process is already running — stopping this one.');
            // Throw so that callers (e.g. SyncIdentity command) know this was skipped,
            // not "completed", and can report the correct status.
            throw new \RuntimeException('sync_already_running');
        }

        try {
            ignore_user_abort(true);
            set_time_limit(0);         // CLI — no PHP time limit
            ini_set('memory_limit', '2048M');

            $service = new \App\Services\Identity\IdentitySyncService();
            $service->syncAll();

            Log::info('SyncIdentityData: Job completed successfully.');
        } catch (\Throwable $e) {
            Log::error('SyncIdentityData: Job failed: ' . $e->getMessage());
            throw $e; // re-throw so the command can log it properly
        } finally {
            $lock->release();
        }
    }
}
