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

    // Default timeout for Graph API HTTP calls (seconds).
    // Longer bulk requests (paginate, batch) use GRAPH_TIMEOUT_BULK.
    private const GRAPH_TIMEOUT      = 60;
    private const GRAPH_TIMEOUT_BULK = 300; // Increased to 5 mins for large batch/paginate ops

    private function get(string $endpoint, array $query = []): array
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
            ->get($this->baseUrl . $endpoint, $query);

        if ($response->status() === 401) {
            $token    = $this->refreshToken();
            $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
                ->get($this->baseUrl . $endpoint, $query);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph GET {$endpoint} failed: " . $response->body());
        }

        return $response->json();
    }

    private function patch(string $endpoint, array $data): void
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
            ->patch($this->baseUrl . $endpoint, $data);

        // On 401 or 403 force a token refresh and retry once.
        // 403 is also retried because a newly granted application permission
        // (e.g. User.ReadWrite.All) is only reflected in a fresh access token.
        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
                ->patch($this->baseUrl . $endpoint, $data);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph PATCH {$endpoint} failed: " . $response->body());
        }
    }

    private function post(string $endpoint, array $data): array
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
            ->post($this->baseUrl . $endpoint, $data);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
                ->post($this->baseUrl . $endpoint, $data);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph POST {$endpoint} failed: " . $response->body());
        }

        return $response->json() ?? [];
    }

    private function delete(string $endpoint): void
    {
        $token    = $this->getAccessToken();
        $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
            ->delete($this->baseUrl . $endpoint);

        if ($response->status() === 401 || $response->status() === 403) {
            $token    = $this->refreshToken();
            $response = Http::timeout(self::GRAPH_TIMEOUT)->withToken($token)
                ->delete($this->baseUrl . $endpoint);
        }

        if (!$response->successful()) {
            throw new \RuntimeException("Graph DELETE {$endpoint} failed: " . $response->body());
        }
    }

    /**
     * Paginate through @odata.nextLink automatically.
     * Uses a longer timeout (120s) — bulk queries can return thousands of records.
     */
    private function paginate(string $endpoint, array $query = []): array
    {
        $results = [];
        $query   = array_merge(['$top' => 999], $query);
        $url     = $this->baseUrl . $endpoint;

        do {
            $token    = $this->getAccessToken();
            $response = Http::timeout(self::GRAPH_TIMEOUT_BULK)->withToken($token)
                ->get($url, $url === $this->baseUrl . $endpoint ? $query : []);

            if ($response->status() === 401) {
                $token    = $this->refreshToken();
                $response = Http::timeout(self::GRAPH_TIMEOUT_BULK)->withToken($token)
                    ->get($url, $url === $this->baseUrl . $endpoint ? $query : []);
            }

            if (!$response->successful()) {
                throw new \RuntimeException("Graph paginate {$endpoint} failed: " . $response->body());
            }

            $body = $response->json();
            // array_push with spread avoids the full array copy that array_merge
            // creates on every iteration — cuts memory from O(n²) to O(n).
            array_push($results, ...($body['value'] ?? []));
            $url  = $body['@odata.nextLink'] ?? null;
            unset($body); // free decoded JSON immediately
        } while ($url);

        return $results;
    }

    // ─────────────────────────────────────────────────────────────
    // Test Connection
    // ─────────────────────────────────────────────────────────────

    public function testConnection(): string
    {
        $org = $this->get('/organization');
        return $org['value'][0]['displayName'] ?? 'Connected';
    }

    // ─────────────────────────────────────────────────────────────
    // User Operations
    // ─────────────────────────────────────────────────────────────

    public function listUsers(): array
    {
        // Fetch core user data without $expand — adding $expand=manager to a
        // large tenant (hundreds of users) inflates the response significantly
        // and causes timeouts. Manager IDs are fetched in a separate lightweight
        // pass via listUserManagers() during sync.
        $users = $this->paginate('/users', [
            '$select' => implode(',', [
                'id', 'displayName', 'userPrincipalName', 'mail',
                'jobTitle', 'department', 'companyName',
                'accountEnabled', 'usageLocation',
                'assignedLicenses',
                'businessPhones', 'mobilePhone',
                'officeLocation', 'streetAddress', 'city', 'postalCode', 'country',
            ]),
        ]);

        return array_map(function (array $user) {
            $user['manager_id'] = null; // populated separately by listUserManagers()
            return $user;
        }, $users);
    }

    /**
     * Fetch manager relationships for all users in one lightweight paginated call.
     * Returns [userId => managerId] map. Called separately from listUsers() so
     * the heavy core-user query stays fast even on large tenants.
     */
    public function listUserManagers(): array
    {
        try {
            $users = $this->paginate('/users', [
                '$select' => 'id',
                '$expand' => 'manager($select=id)',
            ]);

            $map = [];
            foreach ($users as $u) {
                if (!empty($u['id']) && !empty($u['manager']['id'])) {
                    $map[$u['id']] = $u['manager']['id'];
                }
            }
            return $map;
        } catch (\Throwable) {
            return []; // non-fatal — manager data is supplementary
        }
    }

    /**
     * Fetch the user members of multiple groups in one Graph Batch request.
     *
     * Graph Batch API allows up to 20 sub-requests per call and processes
     * them in parallel on Microsoft's side — far faster than iterating over
     * users with $expand=memberOf (which limits pages to ~100 and blocks on
     * each HTTP roundtrip).
     *
     * @param  array  $groupIds  Azure AD group IDs
     * @return array             [groupId => [userId, ...]]
     */
    public function batchGroupMembers(array $groupIds): array
    {
        if (empty($groupIds)) {
            return [];
        }

        $token  = $this->getAccessToken();
        $result = [];

        // Graph Batch API accepts max 20 requests per call
        foreach (array_chunk($groupIds, 20) as $chunk) {
            $requests = [];
            foreach (array_values($chunk) as $i => $gid) {
                // /microsoft.graph.user cast filters to user-type members only
                $requests[] = [
                    'id'     => (string)($i + 1),
                    'method' => 'GET',
                    'url'    => "/groups/{$gid}/members/microsoft.graph.user?\$select=id&\$top=999",
                ];
            }

            try {
                $resp = Http::timeout(self::GRAPH_TIMEOUT_BULK)->withToken($token)
                    ->post($this->baseUrl . '/$batch', ['requests' => $requests]);

                // Retry once on 401 (stale token)
                if ($resp->status() === 401) {
                    $token = $this->refreshToken();
                    $resp  = Http::timeout(self::GRAPH_TIMEOUT_BULK)->withToken($token)
                        ->post($this->baseUrl . '/$batch', ['requests' => $requests]);
                }

                if (!$resp->successful()) {
                    Log::warning('Graph batch group-members chunk failed with status ' . $resp->status());
                    continue;
                }

                foreach ($resp->json('responses', []) as $r) {
                    $idx     = (int)$r['id'] - 1;
                    $groupId = $chunk[$idx] ?? null;
                    if (!$groupId) {
                        continue;
                    }

                    if ((int)$r['status'] === 200) {
                        $result[$groupId] = collect($r['body']['value'] ?? [])->pluck('id')->all();
                    }
                    // 403 = app lacks permission to list this group's members — skip silently
                }
                unset($resp);
                gc_collect_cycles();
            } catch (\Throwable $e) {
                Log::warning('Graph batch group-members chunk exception: ' . $e->getMessage());
                continue;
            }
        }

        return $result; // [groupId => [userId, ...]]
    }

    public function getUser(string $id): array
    {
        return $this->get("/users/{$id}", [
            '$select' => 'id,displayName,userPrincipalName,mail,jobTitle,department,accountEnabled,assignedLicenses,memberOf,usageLocation',
            '$expand' => 'memberOf',
        ]);
    }

    public function updateUser(string $id, array $data): void
    {
        $this->patch("/users/{$id}", $data);
    }

    public function disableUser(string $id): void
    {
        $this->patch("/users/{$id}", ['accountEnabled' => false]);
    }

    public function deleteUser(string $id): void
    {
        $this->delete("/users/{$id}");
    }

    public function enableUser(string $id): void
    {
        $this->patch("/users/{$id}", ['accountEnabled' => true]);
    }

    public function resetPassword(string $id, string $newPassword, bool $forceChange = true): void
    {
        $this->patch("/users/{$id}", [
            'passwordProfile' => [
                'password'                      => $newPassword,
                'forceChangePasswordNextSignIn' => $forceChange,
            ],
        ]);
    }

    /**
     * Create a new Azure AD user and return the full user object (including 'id').
     *
     * Required fields in $data:
     *   displayName, userPrincipalName, mailNickname, password
     * Optional:
     *   accountEnabled (default true), usageLocation (default 'EG'),
     *   jobTitle, department
     */
    public function createUser(array $data): array
    {
        $body = [
            'accountEnabled'    => $data['accountEnabled'] ?? true,
            'displayName'       => $data['displayName'],
            'mailNickname'      => $data['mailNickname'],
            'userPrincipalName' => $data['userPrincipalName'],
            'passwordProfile'   => [
                'forceChangePasswordNextSignIn' => true,
                'password'                      => $data['password'],
            ],
            'usageLocation'     => $data['usageLocation'] ?? 'EG',
        ];

        // Only include optional fields when they have a value — the Graph API
        // rejects null for these properties.
        if (!empty($data['jobTitle']))  {
            $body['jobTitle']   = $data['jobTitle'];
        }
        if (!empty($data['department'])) {
            $body['department'] = $data['department'];
        }

        return $this->post('/users', $body);
    }

    // ─────────────────────────────────────────────────────────────
    // License Operations
    // ─────────────────────────────────────────────────────────────

    public function listSubscribedSkus(): array
    {
        $result = $this->get('/subscribedSkus');
        return $result['value'] ?? [];
    }

    public function assignLicense(string $userId, string $skuId): void
    {
        $this->post("/users/{$userId}/assignLicense", [
            'addLicenses'    => [['skuId' => $skuId, 'disabledPlans' => []]],
            'removeLicenses' => [],
        ]);
    }

    public function removeLicense(string $userId, string $skuId): void
    {
        $this->post("/users/{$userId}/assignLicense", [
            'addLicenses'    => [],
            'removeLicenses' => [$skuId],
        ]);
    }

    public function getUserLicenses(string $userId): array
    {
        $user = $this->get("/users/{$userId}", ['$select' => 'assignedLicenses']);
        return $user['assignedLicenses'] ?? [];
    }

    /**
     * Return a cached map of [ skuId => skuPartNumber ] for quick name lookups.
     * Cached for 5 minutes so provisioning-preview AJAX calls are fast.
     */
    public function getSkuNameMap(): array
    {
        $cacheKey = "graph_sku_name_map_{$this->clientId}";
        return Cache::remember($cacheKey, 300, function () {
            $map = [];
            foreach ($this->listSubscribedSkus() as $sku) {
                if (!empty($sku['skuId'])) {
                    $map[$sku['skuId']] = $sku['skuPartNumber'] ?? $sku['skuId'];
                }
            }
            return $map;
        });
    }

    // ─────────────────────────────────────────────────────────────
    // Group Operations
    // ─────────────────────────────────────────────────────────────

    public function listGroups(): array
    {
        return $this->paginate('/groups', [
            '$select' => 'id,displayName,description,groupTypes,mailEnabled,securityEnabled',
        ]);
    }

    public function listUserGroups(string $userId): array
    {
        $result = $this->get("/users/{$userId}/memberOf", ['$select' => 'id,displayName']);
        return $result['value'] ?? [];
    }

    public function addUserToGroup(string $userId, string $groupId): void
    {
        $this->post("/groups/{$groupId}/members/\$ref", [
            '@odata.id' => "https://graph.microsoft.com/v1.0/directoryObjects/{$userId}",
        ]);
    }

    public function removeUserFromGroup(string $userId, string $groupId): void
    {
        $this->delete("/groups/{$groupId}/members/{$userId}/\$ref");
    }
}
