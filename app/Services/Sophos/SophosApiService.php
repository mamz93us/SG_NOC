<?php

namespace App\Services\Sophos;

use App\Models\SophosFirewall;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SophosApiService
{
    protected string $ip;
    protected int $port;
    protected string $username;
    protected string $password;
    protected string $baseUrl;

    public function __construct(protected SophosFirewall $firewall)
    {
        $this->ip       = $firewall->ip;
        $this->port     = $firewall->port ?? 4444;
        $this->username = $firewall->api_username ?? '';
        $this->password = $firewall->api_password ?? '';
        $this->baseUrl  = "https://{$this->ip}:{$this->port}/webconsole/APIController";
    }

    // ─── Core Request ─────────────────────────────────────────────

    /**
     * Send an XML API request to the Sophos firewall.
     * Returns parsed XML as associative array.
     */
    public function request(string $entityXml): array
    {
        $xml = $this->buildRequestXml($entityXml);

        Log::debug("SophosApiService: POST {$this->baseUrl}", ['firewall' => $this->firewall->name]);

        try {
            $response = Http::withOptions(['verify' => false])
                ->timeout(30)
                ->asForm()
                ->post($this->baseUrl, ['reqxml' => $xml]);

            if ($response->failed()) {
                Log::error("SophosApiService: HTTP {$response->status()} from {$this->firewall->name}", [
                    'body' => substr($response->body(), 0, 500),
                ]);
                throw new \RuntimeException("Sophos API returned HTTP {$response->status()}");
            }

            return $this->parseXml($response->body());
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error("SophosApiService: Connection failed to {$this->firewall->name}", [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException("Cannot connect to Sophos firewall: {$e->getMessage()}");
        }
    }

    // ─── XML Builders ─────────────────────────────────────────────

    protected function buildLoginXml(): string
    {
        $user = htmlspecialchars($this->username, ENT_XML1);
        $pass = htmlspecialchars($this->password, ENT_XML1);

        return "<Login><Username>{$user}</Username><Password passwordform=\"encrypt\">{$pass}</Password></Login>";
    }

    protected function buildRequestXml(string $entityXml): string
    {
        return "<Request>{$this->buildLoginXml()}{$entityXml}</Request>";
    }

    // ─── XML Parser ───────────────────────────────────────────────

    protected function parseXml(string $body): array
    {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);

        if ($xml === false) {
            Log::error('SophosApiService: Failed to parse XML response', [
                'body'   => substr($body, 0, 500),
                'errors' => array_map(fn($e) => $e->message, libxml_get_errors()),
            ]);
            libxml_clear_errors();
            return [];
        }

        return json_decode(json_encode($xml), true) ?? [];
    }

    // ─── Entity Getters ───────────────────────────────────────────

    public function getInterfaces(): array
    {
        $result = $this->request('<Get><Interface/></Get>');
        return $this->extractEntities($result, 'Interface');
    }

    public function getIPHosts(): array
    {
        $result = $this->request('<Get><IPHost/></Get>');
        return $this->extractEntities($result, 'IPHost');
    }

    public function getIPSecConnections(): array
    {
        $result = $this->request('<Get><IPSecConnection/></Get>');
        return $this->extractEntities($result, 'IPSecConnection');
    }

    public function getFirewallRules(): array
    {
        $result = $this->request('<Get><FirewallRule/></Get>');
        return $this->extractEntities($result, 'FirewallRule');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Extract an array of entities from the parsed XML response.
     * Sophos returns either a single entity (assoc array) or list of entities.
     */
    protected function extractEntities(array $result, string $entityName): array
    {
        $entities = $result[$entityName] ?? [];

        // Single entity returned as associative → wrap in array
        if (!empty($entities) && !isset($entities[0]) && !is_numeric(array_key_first($entities))) {
            $entities = [$entities];
        }

        return $entities;
    }

    /**
     * Test connectivity to the firewall.
     */
    public function testConnection(): bool
    {
        try {
            $interfaces = $this->getInterfaces();
            return true;
        } catch (\Throwable $e) {
            Log::warning("SophosApiService: testConnection failed for {$this->firewall->name}", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get the Sophos firewall system info (if available).
     */
    public function getSystemInfo(): array
    {
        try {
            return $this->request('<Get><Interface/></Get>');
        } catch (\Throwable) {
            return [];
        }
    }
}
