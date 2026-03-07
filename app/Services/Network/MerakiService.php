<?php

namespace App\Services\Network;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MerakiService
{
    protected string $apiKey;
    protected string $orgId;
    protected string $baseUrl = 'https://api.meraki.com/api/v1';

    // ─────────────────────────────────────────────────────────────
    // Construction
    // ─────────────────────────────────────────────────────────────

    public function __construct(?string $apiKey = null, ?string $orgId = null)
    {
        $settings      = Setting::get();
        $this->apiKey  = $apiKey  ?? $settings->meraki_api_key  ?? '';
        $this->orgId   = $orgId   ?? $settings->meraki_org_id   ?? '';
    }

    // ─────────────────────────────────────────────────────────────
    // Connection Test
    // ─────────────────────────────────────────────────────────────

    /**
     * Verify the API key and org ID are valid.
     * Returns the organisation name on success, throws on failure.
     */
    public function testConnection(): string
    {
        $org = $this->get('/organizations/' . $this->orgId);
        return $org['name'] ?? 'Connected';
    }

    // ─────────────────────────────────────────────────────────────
    // Organisation
    // ─────────────────────────────────────────────────────────────

    public function getOrganization(): array
    {
        return $this->get('/organizations/' . $this->orgId);
    }

    public function getNetworks(): array
    {
        return $this->get('/organizations/' . $this->orgId . '/networks');
    }

    // ─────────────────────────────────────────────────────────────
    // Devices
    // ─────────────────────────────────────────────────────────────

    /**
     * All devices in the organisation (any product type).
     */
    public function getDevices(): array
    {
        return $this->get('/organizations/' . $this->orgId . '/devices');
    }

    /**
     * Online/offline status for every device in the organisation.
     */
    public function getDeviceStatuses(): array
    {
        return $this->get('/organizations/' . $this->orgId . '/devices/statuses');
    }

    // ─────────────────────────────────────────────────────────────
    // Switch Ports
    // ─────────────────────────────────────────────────────────────

    /**
     * Port configuration for a specific switch serial.
     * Returns [] if the device doesn't support switch port queries.
     */
    public function getSwitchPorts(string $serial): array
    {
        try {
            return $this->get("/devices/{$serial}/switch/ports");
        } catch (\Exception $e) {
            Log::debug("MerakiService::getSwitchPorts({$serial}) skipped: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Live port status (link state, speed, duplex, client MAC).
     */
    public function getSwitchPortStatuses(string $serial): array
    {
        try {
            return $this->get("/devices/{$serial}/switch/ports/statuses");
        } catch (\Exception $e) {
            Log::debug("MerakiService::getSwitchPortStatuses({$serial}) skipped: " . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Clients
    // ─────────────────────────────────────────────────────────────

    /**
     * Clients seen on a device within the given timespan (seconds, default = 24 h).
     */
    public function getDeviceClients(string $serial, int $timespan = 86400): array
    {
        try {
            return $this->get("/devices/{$serial}/clients", ['timespan' => $timespan]);
        } catch (\Exception $e) {
            Log::debug("MerakiService::getDeviceClients({$serial}) skipped: " . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // LLDP / Topology
    // ─────────────────────────────────────────────────────────────

    /**
     * LLDP and CDP neighbor data for a device.
     */
    public function getLldpNeighbors(string $serial): array
    {
        try {
            return $this->get("/devices/{$serial}/lldpCdp");
        } catch (\Exception $e) {
            Log::debug("MerakiService::getLldpNeighbors({$serial}) skipped: " . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Events
    // ─────────────────────────────────────────────────────────────

    /**
     * Network-level switch events.
     */
    public function getNetworkEvents(string $networkId, int $perPage = 100): array
    {
        try {
            return $this->get("/networks/{$networkId}/events", [
                'productType' => 'switch',
                'perPage'     => $perPage,
            ]);
        } catch (\Exception $e) {
            Log::debug("MerakiService::getNetworkEvents({$networkId}) skipped: " . $e->getMessage());
            return [];
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Internal HTTP helper
    // ─────────────────────────────────────────────────────────────

    protected function get(string $path, array $query = []): array
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Meraki API key is not configured.');
        }

        $url = $this->baseUrl . $path;

        Log::debug("MerakiService GET {$url}", ['query' => $query]);

        $response = Http::withHeaders([
            'X-Cisco-Meraki-API-Key' => $this->apiKey,
            'Content-Type'           => 'application/json',
            'Accept'                 => 'application/json',
        ])->timeout(30)->get($url, $query);

        // Handle Meraki rate limiting (429)
        if ($response->status() === 429) {
            $retryAfter = (int) ($response->header('Retry-After') ?? 1);
            Log::warning("MerakiService: rate limited, retry after {$retryAfter}s");
            sleep(min($retryAfter, 5));

            $response = Http::withHeaders([
                'X-Cisco-Meraki-API-Key' => $this->apiKey,
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
            ])->timeout(30)->get($url, $query);
        }

        if ($response->failed()) {
            $body    = substr($response->body(), 0, 400);
            $status  = $response->status();
            Log::error("MerakiService: HTTP {$status} for {$url}", ['body' => $body]);
            throw new \RuntimeException("Meraki API returned HTTP {$status}: {$body}");
        }

        return $response->json() ?? [];
    }
}
