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

    public function __construct(
        ?string $tenantId     = null,
        ?string $clientId     = null,
        ?string $clientSecret = null
    ) {
        $settings           = Setting::get();
        $this->tenantId     = $tenantId     ?? $settings->graph_tenant_id     ?? '';
        $this->clientId     = $clientId     ?? $settings->graph_client_id     ?? '';
        $this->clientSecret = $clientSecret ?? $settings->graph_client_secret ?? '';
    }

    // ─────────────────────────────────────────────────────────────
    // Authentication
    // ─────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        $cacheKey = "graph_token_{$this->clientId}";

        return Cache::remember($cacheKey, 3500, function () {
            $url = "https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token";

            $response = Http::asForm()->post($url, [
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
            ]);

            if (!$response->successful()) {
                $err = $response->json('error_description') ?? $response->body();
                throw new \RuntimeException("Graph auth failed: {$err}");
            }

            return $response->json('access_token');
        });
    }

    /**
     * Force a fresh token (clears cache and re-requests).
     * Called automatically on 401/403 responses so newly-granted
     * permissions are picked up without waiting for cache expiry.
     */
    public function refreshToken(): string
    {
        Cache::forget("graph_token_{$this->clientId}");
        return $this->getAccessToken();
    }

    // Default timeouts in seconds
    private const TIMEOUT_STANDARD = 60;
    private const TIMEOUT_BULK     = 300;

    /**
     * Authenticated GET request.
     */
    private function get(string $endpoint, array $query = [], int $timeout = self::TIMEOUT_STANDARD, array $headers = []): array
    {
        $token = $this->getAccessToken();
        $url   = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;

        $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);

        // Auto-refresh token once on 401/403
        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph GET {$endpoint} failed [{$response->status()}]: " . $response->body());
        }

        return $response->json();
    }

    /**
     * Authenticated POST request.
     */
    private function post(string $endpoint, array $data, int $timeout = self::TIMEOUT_STANDARD): array
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout($timeout)->withToken($token)->post($this->baseUrl . $endpoint, $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->post($this->baseUrl . $endpoint, $data);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph POST {$endpoint} failed [{$response->status()}]: " . $response->body());
        }

        return $response->json() ?? [];
    }

    /**
     * Authenticated PATCH request.
     */
    private function patch(string $endpoint, array $data): void
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout(self::TIMEOUT_STANDARD)->withToken($token)->patch($this->baseUrl . $endpoint, $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout(self::TIMEOUT_STANDARD)->withToken($token)->patch($this->baseUrl . $endpoint, $data);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph PATCH {$endpoint} failed [{$response->status()}]: " . $response->body());
        }
    }

    /**
     * Authenticated DELETE request.
     */
    private function delete(string $endpoint): void
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout(self::TIMEOUT_STANDARD)->withToken($token)->delete($this->baseUrl . $endpoint);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout(self::TIMEOUT_STANDARD)->withToken($token)->delete($this->baseUrl . $endpoint);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph DELETE {$endpoint} failed [{$response->status()}]: " . $response->body());
        }
    }

    /**
     * Paginate through results using a callback, maintaining low memory usage.
     */
    private function paginateWithCallback(string $endpoint, callable $callback, array $query = [], array $headers = []): void
    {
        $query   = array_merge(['$top' => 500], $query);
        $baseUrl = $this->baseUrl . $endpoint;
        $url     = $baseUrl;

        do {
            // On subsequent pages the nextLink already encodes all query params, so pass empty query
            $body   = $this->get($url, $url === $baseUrl ? $query : [], self::TIMEOUT_BULK, $headers);
            $values = $body['value'] ?? [];

            Log::debug("Graph: Processed " . count($values) . " results for " . parse_url($url, PHP_URL_PATH));

            $callback($values);

            $url = $body['@odata.nextLink'] ?? null;
            unset($body);
            gc_collect_cycles();
        } while ($url);
    }

    // ─────────────────────────────────────────────────────────────
    // Domain Operations
    // ─────────────────────────────────────────────────────────────

    public function testConnection(): string
    {
        $res = $this->get('/organization');
        return $res['value'][0]['displayName'] ?? 'Connected';
    }

    public function listUsers(callable $callback): void
    {
        // Only sync internal Member accounts — skips B2B guests (#EXT#), shared mailboxes,
        // room/equipment accounts, and service principals that inflate the tenant user count.
        // NOTE: userType is an advanced-query property — requires ConsistencyLevel + $count=true.
        $this->paginateWithCallback('/users', $callback, [
            '$select' => 'id,displayName,userPrincipalName,mail,jobTitle,department,companyName,accountEnabled,usageLocation,assignedLicenses,businessPhones,mobilePhone,officeLocation,streetAddress,city,postalCode,country',
            '$filter' => "userType eq 'Member'",
            '$count'  => 'true',
        ], ['ConsistencyLevel' => 'eventual']);
    }

    public function listGroups(callable $callback): void
    {
        // Only sync security-enabled groups (skips M365/Teams/SharePoint/distribution groups).
        // Tenants can have tens of thousands of non-security groups that are irrelevant for
        // user-assignment tracking and would make sync take hours.
        $this->paginateWithCallback('/groups', $callback, [
            '$select' => 'id,displayName,description,groupTypes,mailEnabled,securityEnabled',
            '$filter' => 'securityEnabled eq true',
        ]);
    }

    public function listSubscribedSkus(): array
    {
        $res = $this->get('/subscribedSkus');
        return $res['value'] ?? [];
    }

    public function listUserManagers(callable $callback): void
    {
        try {
            $this->paginateWithCallback('/users', function($users) use ($callback) {
                $map = [];
                foreach ($users as $u) {
                    if (!empty($u['id']) && !empty($u['manager']['id'])) {
                        $map[$u['id']] = $u['manager']['id'];
                    }
                }
                if (!empty($map)) $callback($map);
            }, [
                '$select' => 'id',
                '$expand' => 'manager($select=id)',
                '$filter' => "userType eq 'Member'",
                '$count'  => 'true',
            ], ['ConsistencyLevel' => 'eventual']);
        } catch (\Throwable) {
            // Managers are supplementary
        }
    }

    /**
     * Batch-fetch each user's group memberships (user-centric direction).
     *
     * WHY user-centric instead of group-centric:
     * A tenant with 800 users needs only ~40 batch API calls (20 users/call).
     * The old group-centric approach needed N_groups/5 calls — tens of thousands
     * when the tenant has many security groups.
     *
     * $callback receives: [ userId => [groupId, groupId, ...], ... ]
     * (includes ALL directory objects, not only security groups — the caller filters)
     */
    public function batchUserMemberships(array $userIds, callable $callback): void
    {
        foreach (array_chunk($userIds, 20) as $chunk) {
            $requests = [];
            foreach (array_values($chunk) as $i => $id) {
                $requests[] = [
                    'id'     => (string) ($i + 1),
                    'method' => 'GET',
                    'url'    => "/users/{$id}/memberOf?\$select=id&\$top=500",
                ];
            }

            try {
                $resp    = $this->post('/$batch', ['requests' => $requests], self::TIMEOUT_BULK);
                $results = [];
                foreach ($resp['responses'] ?? [] as $r) {
                    $idx = (int) $r['id'] - 1;
                    $uid = $chunk[$idx] ?? null;
                    if ($uid && (int) $r['status'] === 200) {
                        $results[$uid] = collect($r['body']['value'] ?? [])->pluck('id')->all();
                    }
                }
                $callback($results);
                gc_collect_cycles();
            } catch (\Throwable) { continue; }
        }
    }

    /**
     * @deprecated Use batchUserMemberships() instead — far fewer API calls.
     */
    public function batchGroupMembers(array $groupIds, callable $callback): void
    {
        foreach (array_chunk($groupIds, 5) as $chunk) {
            $requests = [];
            foreach (array_values($chunk) as $i => $id) {
                $requests[] = [
                    'id'     => (string) ($i + 1),
                    'method' => 'GET',
                    'url'    => "/groups/{$id}/members/microsoft.graph.user?\$select=id&\$top=500",
                ];
            }

            try {
                $resp = $this->post('/$batch', ['requests' => $requests], self::TIMEOUT_BULK);
                $results = [];
                foreach ($resp['responses'] ?? [] as $r) {
                    $idx = (int) $r['id'] - 1;
                    $gid = $chunk[$idx] ?? null;
                    if ($gid && (int) $r['status'] === 200) {
                        $results[$gid] = collect($r['body']['value'] ?? [])->pluck('id')->all();
                    }
                }
                $callback($results);
                gc_collect_cycles();
            } catch (\Throwable) { continue; }
        }
    }

    // Provisioning Methods
    public function getUser(string $id): array { return $this->get("/users/{$id}", ['$expand' => 'memberOf']); }
    public function updateUser(string $id, array $data): void { $this->patch("/users/{$id}", $data); }
    public function createUser(array $data): array { return $this->post('/users', $data); }
    public function deleteUser(string $id): void { $this->delete("/users/{$id}"); }
    public function assignLicense(string $userId, string $skuId): void { $this->post("/users/{$userId}/assignLicense", ['addLicenses' => [['skuId' => $skuId]], 'removeLicenses' => []]); }
    public function removeLicense(string $userId, string $skuId): void { $this->post("/users/{$userId}/assignLicense", ['addLicenses' => [], 'removeLicenses' => [$skuId]]); }
    public function addUserToGroup(string $userId, string $groupId): void { $this->post("/groups/{$groupId}/members/\$ref", ['@odata.id' => "{$this->baseUrl}/directoryObjects/{$userId}"]); }
    public function removeUserFromGroup(string $userId, string $groupId): void { $this->delete("/groups/{$groupId}/members/{$userId}/\$ref"); }
}
