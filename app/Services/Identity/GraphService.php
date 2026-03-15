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
    private const TIMEOUT_BULK     = 120;

    // ─────────────────────────────────────────────────────────────
    // Construction & Authentication
    // ─────────────────────────────────────────────────────────────

    public function __construct(
        ?string $tenantId = null,
        ?string $clientId = null,
        ?string $clientSecret = null
    ) {
        $settings           = Setting::get();
        $this->tenantId     = $tenantId     ?? $settings->graph_tenant_id     ?? '';
        $this->clientId     = $clientId     ?? $settings->graph_client_id     ?? '';
        $this->clientSecret = $clientSecret ?? $settings->graph_client_secret ?? '';
    }

    /**
     * OAuth2 client_credentials token — cached for ~58 minutes.
     */
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

            if (! $response->successful()) {
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

    // ─────────────────────────────────────────────────────────────
    // Low-level HTTP verbs with 401/403 auto-retry
    // ─────────────────────────────────────────────────────────────

    private function get(string $endpoint, array $query = [], int $timeout = self::TIMEOUT_STANDARD, array $headers = []): array
    {
        $token = $this->getAccessToken();
        $url   = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;

        $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Graph GET {$endpoint} failed ({$response->status()}): " . $response->body());
        }

        return $response->json() ?? [];
    }

    public function post(string $endpoint, array $data, int $timeout = self::TIMEOUT_STANDARD): array
    {
        $token    = $this->getAccessToken();
        $url      = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;
        $response = Http::timeout($timeout)->withToken($token)->post($url, $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->post($url, $data);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Graph POST {$endpoint} failed ({$response->status()}): " . $response->body());
        }

        return $response->json() ?? [];
    }

    private function patch(string $endpoint, array $data, int $timeout = self::TIMEOUT_STANDARD): void
    {
        $token    = $this->getAccessToken();
        $url      = $this->baseUrl . $endpoint;
        $response = Http::timeout($timeout)->withToken($token)->patch($url, $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->patch($url, $data);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Graph PATCH {$endpoint} failed ({$response->status()}): " . $response->body());
        }
    }

    private function delete(string $endpoint, int $timeout = self::TIMEOUT_STANDARD): void
    {
        $token    = $this->getAccessToken();
        $url      = $this->baseUrl . $endpoint;
        $response = Http::timeout($timeout)->withToken($token)->delete($url);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout($timeout)->withToken($token)->delete($url);
        }

        if (! $response->successful()) {
            throw new \RuntimeException("Graph DELETE {$endpoint} failed ({$response->status()}): " . $response->body());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Pagination helper
    // ─────────────────────────────────────────────────────────────

    private function paginateWithCallback(string $endpoint, callable $callback, array $query = [], array $headers = []): void
    {
        $query   = array_merge(['$top' => 999], $query); // caller's $top overrides default
        $baseUrl = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;
        $url     = $baseUrl;

        do {
            $body   = $this->get($url, $url === $baseUrl ? $query : [], self::TIMEOUT_BULK, $headers);
            $values = $body['value'] ?? [];

            if (! empty($values)) {
                $callback($values);
            }

            $url = $body['@odata.nextLink'] ?? null;
            unset($body);
            gc_collect_cycles();
        } while ($url);
    }

    // ─────────────────────────────────────────────────────────────
    // Test Connection
    // ─────────────────────────────────────────────────────────────

    /**
     * Verify credentials by fetching the organisation name.
     * Returns the org display name on success.
     */
    public function testConnection(): string
    {
        $result = $this->get('/organization', ['$select' => 'displayName']);
        $orgs   = $result['value'] ?? [];

        return $orgs[0]['displayName'] ?? 'Unknown Organisation';
    }

    // ─────────────────────────────────────────────────────────────
    // Users — Read
    // ─────────────────────────────────────────────────────────────

    /**
     * Paginate all users, calling $callback with each page (array of user objects).
     */
    public function listUsers(callable $callback): void
    {
        $this->paginateWithCallback('/users', $callback, [
            '$select' => implode(',', [
                'id', 'displayName', 'userPrincipalName', 'mail',
                'jobTitle', 'department', 'companyName',
                'accountEnabled', 'usageLocation', 'assignedLicenses',
                'businessPhones', 'mobilePhone', 'officeLocation',
                'streetAddress', 'city', 'postalCode', 'country',
            ]),
        ]);
    }

    /**
     * Paginate users with $expand=manager to get manager IDs.
     * Calls $callback with each page of users that include manager data.
     */
    public function listUsersWithManager(callable $callback): void
    {
        // $expand=manager is slow on large tenants — use smaller pages
        $this->paginateWithCallback('/users', $callback, [
            '$top'    => 100,
            '$select' => 'id',
            '$expand' => 'manager($select=id)',
        ]);
    }

    /**
     * Get a single user by Azure ID or UPN.
     */
    public function getUser(string $idOrUpn, ?string $select = null): array
    {
        $query = [];
        if ($select) {
            $query['$select'] = $select;
        }

        return $this->get("/users/{$idOrUpn}", $query);
    }

    // ─────────────────────────────────────────────────────────────
    // Users — Create / Update / Delete
    // ─────────────────────────────────────────────────────────────

    /**
     * Create a new Azure AD user.
     *
     * @param array $data Must include: displayName, userPrincipalName, mailNickname, password.
     *                     Optional: jobTitle, department, usageLocation, accountEnabled, etc.
     * @return array The created user object (includes 'id').
     */
    public function createUser(array $data): array
    {
        $password       = $data['password'] ?? null;
        $forceChange    = $data['forceChangePasswordNextSignIn'] ?? true;
        $accountEnabled = $data['accountEnabled'] ?? true;

        // Remove our convenience keys before sending to Graph
        unset($data['password'], $data['forceChangePasswordNextSignIn']);

        $payload = array_merge($data, [
            'accountEnabled' => $accountEnabled,
            'passwordProfile' => [
                'password'                      => $password,
                'forceChangePasswordNextSignIn' => $forceChange,
            ],
        ]);

        // Ensure mailNickname is set
        if (empty($payload['mailNickname']) && ! empty($payload['userPrincipalName'])) {
            $payload['mailNickname'] = explode('@', $payload['userPrincipalName'])[0];
        }

        return $this->post('/users', $payload);
    }

    /**
     * Update a user's profile fields in Azure AD.
     */
    public function updateUser(string $id, array $data): void
    {
        $this->patch("/users/{$id}", $data);
    }

    /**
     * Disable a user account (set accountEnabled = false).
     */
    public function disableUser(string $id): void
    {
        $this->patch("/users/{$id}", ['accountEnabled' => false]);
    }

    /**
     * Enable a user account (set accountEnabled = true).
     */
    public function enableUser(string $id): void
    {
        $this->patch("/users/{$id}", ['accountEnabled' => true]);
    }

    /**
     * Reset a user's password.
     */
    public function resetPassword(string $id, string $password, bool $forceChange = true): void
    {
        $this->patch("/users/{$id}", [
            'passwordProfile' => [
                'password'                      => $password,
                'forceChangePasswordNextSignIn' => $forceChange,
            ],
        ]);
    }

    /**
     * Delete a user from Azure AD.
     */
    public function deleteUser(string $id): void
    {
        $this->delete("/users/{$id}");
    }

    // ─────────────────────────────────────────────────────────────
    // Licenses
    // ─────────────────────────────────────────────────────────────

    /**
     * List all subscribed SKUs (licenses) for the tenant.
     */
    public function listSubscribedSkus(): array
    {
        $result = $this->get('/subscribedSkus');
        return $result['value'] ?? [];
    }

    /**
     * Build a map of skuId → friendly display name from subscribed SKUs.
     */
    public function getSkuNameMap(): array
    {
        $skus = $this->listSubscribedSkus();
        $map  = [];

        foreach ($skus as $sku) {
            $map[$sku['skuId']] = $sku['skuPartNumber'] ?? $sku['skuId'];
        }

        return $map;
    }

    /**
     * Assign a license (SKU) to a user.
     *
     * @see https://learn.microsoft.com/en-us/graph/api/user-assignlicense
     */
    public function assignLicense(string $userId, string $skuId): array
    {
        return $this->post("/users/{$userId}/assignLicense", [
            'addLicenses' => [
                ['skuId' => $skuId, 'disabledPlans' => []],
            ],
            'removeLicenses' => [],
        ]);
    }

    /**
     * Remove a license (SKU) from a user.
     */
    public function removeLicense(string $userId, string $skuId): array
    {
        return $this->post("/users/{$userId}/assignLicense", [
            'addLicenses'    => [],
            'removeLicenses' => [$skuId],
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Groups
    // ─────────────────────────────────────────────────────────────

    /**
     * List all groups (paginated). If $callback is null, returns collected array.
     */
    public function listGroups(?callable $callback = null): array
    {
        if ($callback) {
            $this->paginateWithCallback('/groups', $callback, [
                '$select' => 'id,displayName,description,groupTypes,mailEnabled,securityEnabled',
            ]);
            return [];
        }

        $results = [];
        $this->paginateWithCallback('/groups', function ($chunk) use (&$results) {
            $results = array_merge($results, $chunk);
        }, [
            '$select' => 'id,displayName,description,groupTypes,mailEnabled,securityEnabled',
        ]);

        return $results;
    }

    /**
     * Batch-fetch group members (user IDs only) using the $batch endpoint.
     * Returns [groupId => [userId, ...], ...]
     */
    public function batchGroupMembers(array $groupIds): array
    {
        if (empty($groupIds)) return [];

        $result = [];

        foreach (array_chunk($groupIds, 20) as $chunk) {
            $requests = [];
            foreach (array_values($chunk) as $i => $gid) {
                $requests[] = [
                    'id'     => (string) ($i + 1),
                    'method' => 'GET',
                    'url'    => "/groups/{$gid}/members/microsoft.graph.user?\$select=id&\$top=999",
                ];
            }

            try {
                $resp = $this->post('/$batch', ['requests' => $requests], self::TIMEOUT_BULK);

                foreach ($resp['responses'] ?? [] as $r) {
                    $idx     = (int) $r['id'] - 1;
                    $groupId = $chunk[$idx] ?? null;
                    if ($groupId && (int) $r['status'] === 200) {
                        $result[$groupId] = collect($r['body']['value'] ?? [])->pluck('id')->all();
                    }
                }
                gc_collect_cycles();
            } catch (\Throwable $e) {
                Log::error("Graph batch error for group members chunk: " . $e->getMessage());
                continue;
            }
        }

        return $result;
    }

    /**
     * Add a user to a group.
     *
     * @see https://learn.microsoft.com/en-us/graph/api/group-post-members
     */
    public function addUserToGroup(string $userId, string $groupId): void
    {
        $this->post("/groups/{$groupId}/members/\$ref", [
            '@odata.id' => "{$this->baseUrl}/directoryObjects/{$userId}",
        ]);
    }

    /**
     * Remove a user from a group.
     *
     * @see https://learn.microsoft.com/en-us/graph/api/group-delete-members
     */
    public function removeUserFromGroup(string $userId, string $groupId): void
    {
        $this->delete("/groups/{$groupId}/members/{$userId}/\$ref");
    }

    // ─────────────────────────────────────────────────────────────
    // Manager
    // ─────────────────────────────────────────────────────────────

    /**
     * Get a single user's manager. Returns the manager user object or null.
     */
    public function getUserManager(string $userId): ?array
    {
        try {
            return $this->get("/users/{$userId}/manager", ['$select' => 'id,displayName,userPrincipalName']);
        } catch (\RuntimeException $e) {
            // 404 = no manager assigned, not an error
            if (str_contains($e->getMessage(), '404')) {
                return null;
            }
            throw $e;
        }
    }
}
