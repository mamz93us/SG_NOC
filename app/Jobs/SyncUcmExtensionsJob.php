<?php

namespace App\Jobs;

use App\Models\UcmExtensionCache;
use App\Models\UcmServer;
use App\Models\UcmTrunkCache;
use App\Services\IppbxApiService;
use App\Support\SecretScrubber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUcmExtensionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function handle(): void
    {
        $servers = UcmServer::where('is_active', true)->get();

        foreach ($servers as $server) {
            try {
                $this->syncServer($server);
                $this->recordHealth($server, true);
            } catch (\Throwable $e) {
                Log::error("SyncUcmExtensionsJob: Failed for {$server->name}: {$e->getMessage()}");
                $this->recordHealth($server, false, $e->getMessage());
            }
        }
    }

    /**
     * Stamp the outcome of this sweep onto the server row.
     *
     * This job already reaches every active UCM every 15 seconds; until now the
     * result was only ever a log line, so anything that wanted to know whether a
     * PBX was alive had to make its own live API call. The branch health score
     * reads these columns instead, which is what keeps the NOC dashboard from
     * dialling every UCM in the estate just to render.
     *
     * saveQuietly: this is telemetry, not an operator edit — it should not fire
     * observers or land in the activity log every 15 seconds.
     */
    private function recordHealth(UcmServer $server, bool $ok, ?string $error = null): void
    {
        try {
            $server->forceFill([
                'last_health_ok' => $ok,
                'last_health_at' => now(),
                'last_health_error' => $ok ? null : $this->sanitizeError($error),
            ])->saveQuietly();
        } catch (\Throwable $e) {
            // Never let a health write break the sync it is reporting on.
            Log::warning("SyncUcmExtensionsJob: could not record health for {$server->name}: {$e->getMessage()}");
        }
    }

    /**
     * Strip anything credential-shaped before the message is stored.
     *
     * This string is surfaced on the NOC dashboard, and UCM API errors have a
     * habit of echoing back the request — including the username and the MD5
     * challenge response. None of that may leave the server row.
     */
    private function sanitizeError(?string $error): ?string
    {
        return SecretScrubber::scrub($error);
    }

    private function syncServer(UcmServer $server): void
    {
        $api = new IppbxApiService($server);
        $api->login();

        // ── Sync Extensions ────────────────────────────────────────────
        $extensions = $api->listExtensions(1, 2000);
        $now = now();
        $seenExtensions = [];

        foreach ($extensions as $ext) {
            $extension = $ext['extension'] ?? null;
            if (! $extension) {
                continue;
            }

            $seenExtensions[] = $extension;

            // Parse the IP from addr field (format: "ip:port" or just "ip")
            $addr = $ext['addr'] ?? '';
            $ip = $addr;
            if (str_contains($addr, ':')) {
                $ip = explode(':', $addr)[0];
            }
            // Filter out empty or local addresses
            if ($ip === '' || $ip === '0.0.0.0') {
                $ip = null;
            }

            UcmExtensionCache::updateOrCreate(
                ['ucm_id' => $server->id, 'extension' => $extension],
                [
                    'name' => $ext['fullname'] ?? null,
                    'email' => $ext['email'] ?? null,
                    'ip_address' => $ip,
                    'status' => strtolower($ext['status'] ?? 'unavailable'),
                    'last_seen_at' => $now,
                ]
            );
        }

        // Remove stale extensions no longer on this server
        if (! empty($seenExtensions)) {
            UcmExtensionCache::where('ucm_id', $server->id)
                ->whereNotIn('extension', $seenExtensions)
                ->delete();
        }

        // ── Sync Trunks ────────────────────────────────────────────────
        $trunks = $api->listVoIPTrunks();
        $seenTrunks = [];

        foreach ($trunks as $trunk) {
            $index = $trunk['trunk_index'] ?? null;
            if (! $index) {
                continue;
            }

            $seenTrunks[] = $index;

            UcmTrunkCache::updateOrCreate(
                ['ucm_id' => $server->id, 'trunk_index' => $index],
                [
                    'trunk_name' => $trunk['trunk_name'] ?? "Trunk {$index}",
                    'host' => $trunk['host'] ?? null,
                    'status' => strtolower($trunk['status'] ?? 'unreachable'),
                    'last_checked_at' => $now,
                ]
            );
        }

        if (! empty($seenTrunks)) {
            UcmTrunkCache::where('ucm_id', $server->id)
                ->whereNotIn('trunk_index', $seenTrunks)
                ->delete();
        }

        Log::info("SyncUcmExtensionsJob: {$server->name} — ".count($extensions).' extensions, '.count($trunks).' trunks synced.');
    }
}
