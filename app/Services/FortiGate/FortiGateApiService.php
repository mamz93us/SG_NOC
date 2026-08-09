<?php

namespace App\Services\FortiGate;

use App\Models\FortigateFirewall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for the FortiGate REST API (FortiOS 6.4+ / 7.x).
 *
 * Auth is a REST API admin token passed as a Bearer header:
 *   curl -k -H "Authorization: Bearer <token>" https://<fw>/api/v2/monitor/system/dhcp
 *
 * Create the token on the firewall under
 *   System → Administrators → Create New → REST API Admin
 * with a read-only profile and a Trusted Host covering the NOC IP.
 */
class FortiGateApiService
{
    protected string $token;

    protected string $vdom;

    protected string $baseUrl;

    public function __construct(protected FortigateFirewall $firewall)
    {
        $this->token = $firewall->api_token ?? '';
        $this->vdom = $firewall->vdom ?: 'root';
        $this->baseUrl = $firewall->apiBaseUrl();
    }

    // ─── Core Request ─────────────────────────────────────────────

    /**
     * GET a FortiGate API path (relative to /api/v2), e.g. 'monitor/system/dhcp'.
     *
     * @throws \RuntimeException on transport, auth or HTTP failure.
     */
    public function get(string $path, array $query = []): array
    {
        if ($this->token === '') {
            throw new \RuntimeException("No API token stored for FortiGate '{$this->firewall->name}'.");
        }

        $url = $this->baseUrl.'/'.ltrim($path, '/');
        $query = array_merge(['vdom' => $this->vdom], $query);

        try {
            $response = Http::withOptions(['verify' => false])
                ->withToken($this->token)
                ->acceptJson()
                ->timeout(30)
                ->get($url, $query);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("FortiGateApiService: connection failed to {$this->firewall->name}", [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Cannot connect to FortiGate {$this->firewall->ip}: {$e->getMessage()}");
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new \RuntimeException(
                "FortiGate rejected the API token (HTTP {$response->status()}). "
                .'Check the token and that the NOC IP is in the API admin Trusted Hosts.'
            );
        }

        if ($response->failed()) {
            Log::error("FortiGateApiService: HTTP {$response->status()} from {$this->firewall->name}", [
                'url' => $url,
                'body' => substr($response->body(), 0, 500),
            ]);
            throw new \RuntimeException("FortiGate API returned HTTP {$response->status()}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('FortiGate API returned a non-JSON response.');
        }

        return $json;
    }

    // ─── Endpoints ────────────────────────────────────────────────

    /**
     * Raw DHCP lease list: GET /api/v2/monitor/system/dhcp
     *
     * @return array{results: array<int, array<string, mixed>>, serial: ?string, version: ?string, build: ?int}
     */
    public function dhcpLeases(): array
    {
        $json = $this->get('monitor/system/dhcp');

        return [
            'results' => is_array($json['results'] ?? null) ? $json['results'] : [],
            'serial' => $json['serial'] ?? null,
            'version' => $json['version'] ?? null,
            'build' => $json['build'] ?? null,
        ];
    }

    /**
     * System status — used for connection tests and to stamp model/firmware.
     */
    public function systemStatus(): array
    {
        return $this->get('monitor/system/status');
    }

    /**
     * Returns [success, message, meta] rather than throwing, for the "Test" button.
     */
    public function testConnection(): array
    {
        try {
            $json = $this->systemStatus();
            $results = is_array($json['results'] ?? null) ? $json['results'] : [];

            return [
                'success' => true,
                'message' => 'Connected',
                'meta' => [
                    'hostname' => $results['hostname'] ?? null,
                    'model' => $results['model_name'] ?? $results['model'] ?? null,
                    'serial' => $json['serial'] ?? null,
                    'version' => $json['version'] ?? null,
                ],
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'meta' => [],
            ];
        }
    }
}
