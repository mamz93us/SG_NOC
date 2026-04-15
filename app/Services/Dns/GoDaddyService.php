<?php

namespace App\Services\Dns;

use App\Exceptions\GoDaddyApiException;
use App\Models\DnsAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoDaddyService
{
    protected string $baseUrl;

    public function __construct(protected DnsAccount $account)
    {
        $this->baseUrl = $account->baseUrl();
    }

    // ─── Domains ──────────────────────────────────────────────────

    public function testConnection(): array
    {
        try {
            $response = $this->request('GET', '/v1/domains', ['limit' => 1]);
            return ['success' => true, 'message' => 'Connection successful'];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getDomains(array $params = []): array
    {
        return $this->request('GET', '/v1/domains', $params);
    }

    public function getDomain(string $domain): array
    {
        return $this->request('GET', "/v1/domains/{$domain}");
    }

    public function updateDomain(string $domain, array $data): array
    {
        return $this->request('PATCH', "/v1/domains/{$domain}", [], $data);
    }

    // ─── DNS Records ──────────────────────────────────────────────

    public function getRecords(string $domain, ?string $type = null, ?string $name = null): array
    {
        $path = "/v1/domains/{$domain}/records";
        if ($type) {
            $path .= "/{$type}";
            if ($name) {
                $path .= "/{$name}";
            }
        }
        return $this->request('GET', $path);
    }

    public function addRecords(string $domain, array $records): void
    {
        $this->request('PATCH', "/v1/domains/{$domain}/records", [], $records);
    }

    public function replaceRecordsByTypeAndName(string $domain, string $type, string $name, array $records): void
    {
        $this->request('PUT', "/v1/domains/{$domain}/records/{$type}/{$name}", [], $records);
    }

    public function deleteRecordsByTypeAndName(string $domain, string $type, string $name): void
    {
        $this->request('DELETE', "/v1/domains/{$domain}/records/{$type}/{$name}");
    }

    // ─── Nameservers ──────────────────────────────────────────────

    public function updateNameservers(string $domain, array $nameservers): void
    {
        $this->request('PUT', "/v1/domains/{$domain}/nameServers", [], ['nameServers' => $nameservers]);
    }

    // ─── Availability ─────────────────────────────────────────────

    public function checkAvailability(string $domain): array
    {
        return $this->request('GET', '/v1/domains/available', ['domain' => $domain]);
    }

    // ─── HTTP Client ──────────────────────────────────────────────

    protected function request(string $method, string $path, array $query = [], ?array $body = null): mixed
    {
        $url = $this->baseUrl . $path;

        Log::debug("GoDaddyService: {$method} {$path}", ['account' => $this->account->label]);

        try {
            $http = Http::withHeaders([
                'Authorization' => $this->account->authHeader(),
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ])->timeout(30);

            $response = match (strtoupper($method)) {
                'GET'    => $http->get($url, $query),
                'POST'   => $http->post($url, $body ?? []),
                'PUT'    => $http->put($url, $body ?? []),
                'PATCH'  => $http->patch($url, $body ?? []),
                'DELETE' => $http->delete($url),
                default  => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };

            if ($response->status() === 429) {
                $retryAfter = $response->header('Retry-After', '60');
                throw new GoDaddyApiException("Rate limit reached. Try again in {$retryAfter} seconds.", 429);
            }

            if ($response->failed()) {
                Log::error("GoDaddyService: HTTP {$response->status()} from {$this->account->label}", [
                    'body' => substr($response->body(), 0, 500),
                ]);
                throw GoDaddyApiException::fromResponse($response->status(), $response->body());
            }

            // DELETE and some PUT/PATCH return no body
            if (empty($response->body())) {
                return [];
            }

            return $response->json() ?? [];
        } catch (GoDaddyApiException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error("GoDaddyService: Request failed for {$this->account->label}", [
                'error' => $e->getMessage(),
            ]);
            throw new GoDaddyApiException("Connection failed: {$e->getMessage()}", 0, [], $e);
        }
    }
}
