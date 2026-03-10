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

            $parsed = $this->parseXml($response->body());

            // Check authentication status — Sophos returns 200 even on auth failure
            $loginStatus = $parsed['Login']['status'] ?? null;
            if ($loginStatus && stripos($loginStatus, 'fail') !== false) {
                Log::error("SophosApiService: Authentication failed for {$this->firewall->name}", [
                    'status' => $loginStatus,
                ]);
                throw new \RuntimeException("Sophos authentication failed: {$loginStatus}");
            }

            Log::debug("SophosApiService: Response received from {$this->firewall->name}", [
                'login_status' => $loginStatus,
                'keys'         => array_keys($parsed),
            ]);

            return $parsed;
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

        return "<Login><Username>{$user}</Username><Password>{$pass}</Password></Login>";
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
     *
     * Sophos XML wraps entities in a container with the SAME name:
     *   <Response>
     *     <Interface transactionid="">        ← wrapper
     *       <Status>...</Status>
     *       <Interface>...</Interface>         ← actual entity
     *       <Interface>...</Interface>         ← actual entity
     *     </Interface>
     *   </Response>
     *
     * After json_decode: ['Interface' => ['Status' => ..., 'Interface' => [entities]]]
     * We need $result[$entityName][$entityName] to get the actual entities.
     */
    protected function extractEntities(array $result, string $entityName): array
    {
        $wrapper = $result[$entityName] ?? [];

        if (empty($wrapper)) {
            return [];
        }

        // Check if this is the wrapper level (has nested entities with same name)
        $entities = $wrapper[$entityName] ?? null;

        if ($entities === null) {
            // No nested entities — could be the wrapper itself IS the entities
            // (happens when response has no Status wrapper)
            $entities = $wrapper;
        }

        // Filter out non-entity keys like 'Status', '@attributes' from wrapper
        if (is_array($entities) && isset($entities['Status'])) {
            // We're still at the wrapper level with no entities returned
            return [];
        }

        // Single entity returned as associative → wrap in array
        if (!empty($entities) && is_array($entities) && !isset($entities[0]) && !is_numeric(array_key_first($entities))) {
            $entities = [$entities];
        }

        // Ensure we have a proper indexed array
        if (!is_array($entities)) {
            return [];
        }

        Log::debug("SophosApiService: Extracted " . count($entities) . " {$entityName} entities");

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
