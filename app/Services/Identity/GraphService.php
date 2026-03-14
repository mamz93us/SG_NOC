<?php

namespace App\Services\Identity;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GraphService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl = 'https://graph.microsoft.com/v1.0';

    private const TIMEOUT_STANDARD = 30;
    private const TIMEOUT_BULK = 120;

    public function __construct(
        ?string $tenantId = null,
        ?string $clientId = null,
        ?string $clientSecret = null
    ) {
        $settings = Setting::get();
        $this->tenantId = $tenantId ?? $settings->graph_tenant_id ?? '';
        $this->clientId = $clientId ?? $settings->graph_client_id ?? '';
        $this->clientSecret = $clientSecret ?? $settings->graph_client_secret ?? '';
    }

    private function getAccessToken(): string
    {
        $cacheKey = "graph_token_{$this->clientId}";

        return Cache::remember($cacheKey, 3500, function () {
            $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

            $response = Http::asForm()->post($url, [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
            ]);

            if (!$response->successful()) {
                $err = $response->json('error_description') ?? $response->body();
                throw new \RuntimeException("Graph auth failed: {$err}");
            }

            return $response->json('access_token');
        });
    }

    private function refreshToken(): string
    {
        Cache::forget("graph_token_{$this->clientId}");
        return $this->getAccessToken();
    }

    private function get(string $endpoint, array $query = [], int $timeout = self::TIMEOUT_STANDARD, array $headers = []): array
    {
        $token = $this->getAccessToken();
        $url = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;

        $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);

        if ($response->status() === 401 || $response->status() === 403) {
            $token = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph GET {$endpoint} failed: " . $response->body());
        }

        return $response->json();
    }

    public function post(string $endpoint, array $data, int $timeout = self::TIMEOUT_STANDARD): array
    {
        $token = $this->getAccessToken();
        $response = Http::timeout($timeout)->withToken($token)
            ->post($this->baseUrl . $endpoint, $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)
                ->post($this->baseUrl . $endpoint, $data);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph POST {$endpoint} failed: " . $response->body());
        }

        return $response->json() ?? [];
    }

    private function paginateWithCallback(string $endpoint, callable $callback, array $query = [], array $headers = []): void
    {
        $query = array_merge(['$top' => 999], $query); // Using 999 for performance
        $baseUrl = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;
        $url = $baseUrl;

        do {
            $body = $this->get($url, $url === $baseUrl ? $query : [], self::TIMEOUT_BULK, $headers);
            $values = $body['value'] ?? [];

            Log::debug("Graph: Processed " . count($values) . " results for " . parse_url($url, PHP_URL_PATH));

            $callback($values);

            $url = $body['@odata.nextLink'] ?? null;
            unset($body);
            gc_collect_cycles();
        } while ($url);
    }

    public function listUsers(callable $callback): void
    {
        $this->paginateWithCallback('/users', $callback, [
            '$select' => 'id,displayName,userPrincipalName,mail,jobTitle,department,companyName,accountEnabled,usageLocation,assignedLicenses,businessPhones,mobilePhone,officeLocation,streetAddress,city,postalCode,country',
        ]);
    }

    public function listGroups(callable $callback = null): array
    {
        if ($callback) {
            $this->paginateWithCallback('/groups', $callback, [
                '$select' => 'id,displayName,description,groupTypes,mailEnabled,securityEnabled',
            ]);
            return [];
        }

        $results = [];
        $this->paginateWithCallback('/groups', function($chunk) use (&$results) {
            $results = array_merge($results, $chunk);
        }, [
            '$select' => 'id,displayName,description,groupTypes,mailEnabled,securityEnabled',
        ]);
        return $results;
    }

    public function listSubscribedSkus(): array
    {
        $result = $this->get('/subscribedSkus');
        return $result['value'] ?? [];
    }

    /**
     * Fetch the user members of multiple groups in one Graph Batch request (Group-centric).
     */
    public function batchGroupMembers(array $groupIds): array
    {
        if (empty($groupIds)) return [];

        $token = $this->getAccessToken();
        $result = [];

        foreach (array_chunk($groupIds, 20) as $chunk) {
            $requests = [];
            foreach (array_values($chunk) as $i => $gid) {
                // microsoft.graph.user cast filters to user-type members only
                $requests[] = [
                    'id' => (string) ($i + 1),
                    'method' => 'GET',
                    'url' => "/groups/{$gid}/members/microsoft.graph.user?\$select=id&\$top=999",
                ];
            }

            try {
                $resp = $this->post('/$batch', ['requests' => $requests], self::TIMEOUT_BULK);
                foreach ($resp['responses'] ?? [] as $r) {
                    $idx = (int) $r['id'] - 1;
                    $groupId = $chunk[$idx] ?? null;
                    if ($groupId && (int) $r['status'] === 200) {
                        $result[$groupId] = collect($r['body']['value'] ?? [])->pluck('id')->all();
                    }
                }
                gc_collect_cycles();
            } catch (\Throwable $e) {
                Log::error("Graph batch error for chunk: " . $e->getMessage());
                continue;
            }
        }

        return $result;
    }

    public function updateUser(string $id, array $data): void { 
        $token = $this->getAccessToken();
        $response = Http::timeout(self::TIMEOUT_STANDARD)->withToken($token)
            ->patch($this->baseUrl . "/users/{$id}", $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token = $this->refreshToken();
            $response = Http::timeout(self::TIMEOUT_STANDARD)->withToken($token)
                ->patch($this->baseUrl . "/users/{$id}", $data);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph PATCH /users/{$id} failed: " . $response->body());
        }
    }
}
