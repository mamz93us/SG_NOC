<?php

namespace App\Jobs;

use App\Models\UcmExtensionCache;
use App\Models\UcmServer;
use App\Models\UcmTrunkCache;
use App\Services\IppbxApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncUcmExtensionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function handle(): void
    {
        $servers = UcmServer::where('is_active', true)->get();

        foreach ($servers as $server) {
            try {
                $this->syncServer($server);
            } catch (\Throwable $e) {
                Log::error("SyncUcmExtensionsJob: Failed for {$server->name}: {$e->getMessage()}");
            }
        }
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
            if (!$extension) continue;

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
                    'name'         => $ext['fullname'] ?? null,
                    'email'        => $ext['email'] ?? null,
                    'ip_address'   => $ip,
                    'status'       => strtolower($ext['status'] ?? 'unavailable'),
                    'last_seen_at' => $now,
                ]
            );
        }

        // Remove stale extensions no longer on this server
        if (!empty($seenExtensions)) {
            UcmExtensionCache::where('ucm_id', $server->id)
                ->whereNotIn('extension', $seenExtensions)
                ->delete();
        }

        // ── Sync Trunks ────────────────────────────────────────────────
        $trunks = $api->listVoIPTrunks();
        $seenTrunks = [];

        foreach ($trunks as $trunk) {
            $index = $trunk['trunk_index'] ?? null;
            if (!$index) continue;

            $seenTrunks[] = $index;

            UcmTrunkCache::updateOrCreate(
                ['ucm_id' => $server->id, 'trunk_index' => $index],
                [
                    'trunk_name'     => $trunk['trunk_name'] ?? "Trunk {$index}",
                    'host'           => $trunk['host'] ?? null,
                    'status'         => strtolower($trunk['status'] ?? 'unreachable'),
                    'last_checked_at' => $now,
                ]
            );
        }

        if (!empty($seenTrunks)) {
            UcmTrunkCache::where('ucm_id', $server->id)
                ->whereNotIn('trunk_index', $seenTrunks)
                ->delete();
        }

        Log::info("SyncUcmExtensionsJob: {$server->name} — " . count($extensions) . " extensions, " . count($trunks) . " trunks synced.");
    }
}
