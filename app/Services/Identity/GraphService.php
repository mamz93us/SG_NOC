<?php

namespace App\Services\Identity;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GraphService
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl = 'https://graph.microsoft.com/v1.0';
    /** Intune / DeviceManagement APIs are only available on the beta endpoint */
    private string $betaUrl = 'https://graph.microsoft.com/beta';

    private const TIMEOUT_STANDARD = 30;
    private const TIMEOUT_BULK     = 120;

    /**
     * Maximum $top allowed by the Intune proxy (deviceManagement endpoints).
     * The v1.0 / beta Graph API generally supports $top=999, but Intune's
     * internal proxy (proxy.amsub*.manage.microsoft.com) rejects values > 100
     * with HTTP 400.  We keep a separate constant so it is easy to adjust.
     */
    private const TOP_INTUNE = 100;

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

        // Retry on 429 Too Many Requests — honour Retry-After header (max 3 attempts)
        $attempts = 0;
        while ($response->status() === 429 && $attempts < 3) {
            $retryAfter = (int) ($response->header('Retry-After') ?: 20);
            $retryAfter = max(5, min($retryAfter, 60)); // clamp 5-60 s
            sleep($retryAfter);
            $response = Http::timeout($timeout)->withToken($token)->withHeaders($headers)->get($url, $query);
            $attempts++;
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

    /**
     * @param int $defaultTop  Page size sent in the first request.
     *                         Pass TOP_INTUNE (100) for Intune/deviceManagement
     *                         endpoints; the default 999 is fine for AAD endpoints.
     */
    private function paginateWithCallback(
        string $endpoint,
        callable $callback,
        array $query = [],
        array $headers = [],
        int $defaultTop = 999
    ): void {
        // Caller's explicit $top wins; otherwise use $defaultTop.
        if (! isset($query['$top'])) {
            $query['$top'] = $defaultTop;
        }

        $baseUrl = str_starts_with($endpoint, 'http') ? $endpoint : $this->baseUrl . $endpoint;
        $url     = $baseUrl;
        $page    = 0;

        do {
            // Small courtesy delay between pages to avoid 429s on large tenants
            if ($page > 0) {
                usleep(500_000); // 0.5 s between pages
            }

            $body   = $this->get($url, $url === $baseUrl ? $query : [], self::TIMEOUT_BULK, $headers);
            $values = $body['value'] ?? [];

            if (! empty($values)) {
                $callback($values);
            }

            $url = $body['@odata.nextLink'] ?? null;
            unset($body);
            gc_collect_cycles();
            $page++;
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

    // ─────────────────────────────────────────────────────────────
    // Groups — Extended
    // ─────────────────────────────────────────────────────────────

    /**
     * Find a group by display name. Returns the group object or null.
     */
    public function findGroupByName(string $name): ?array
    {
        $encoded = rawurlencode("displayName eq '{$name}'");
        $result  = $this->get('/groups', [
            '$filter' => "displayName eq '{$name}'",
            '$select' => 'id,displayName,description',
            '$top'    => 1,
        ]);
        $groups = $result['value'] ?? [];
        return !empty($groups) ? $groups[0] : null;
    }

    /**
     * Search Azure AD security groups by display name (partial match).
     * Uses ConsistencyLevel: eventual for $search support.
     * Returns array of ['id','displayName','description'].
     */
    public function searchGroups(string $query, int $top = 20): array
    {
        if (blank($query)) {
            return [];
        }
        $escaped = addslashes($query);
        $result  = $this->get('/groups', [
            '$search'  => "\"displayName:{$escaped}\"",
            '$select'  => 'id,displayName,description',
            '$filter'  => 'securityEnabled eq true',
            '$top'     => $top,
            '$orderby' => 'displayName',
        ], self::TIMEOUT_STANDARD, ['ConsistencyLevel' => 'eventual']);
        return $result['value'] ?? [];
    }

    /**
     * Fetch a single Azure AD group by object ID.
     * Returns ['id','displayName','description'] or throws on error.
     */
    public function getGroup(string $groupId): array
    {
        return $this->get("/groups/{$groupId}", [
            '$select' => 'id,displayName,description',
        ]);
    }

    /**
     * Get assigned licenses for a user.
     */
    public function getUserLicenses(string $userId): array
    {
        $result = $this->get("/users/{$userId}/licenseDetails");
        return $result['value'] ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    // Mailbox / Exchange Online
    // ─────────────────────────────────────────────────────────────

    /**
     * Set mailbox forwarding to another address (auto-forward).
     * Uses forwardingSmtpAddress via mailboxSettings.
     */
    public function forwardMailbox(string $userId, string $forwardToAddress): void
    {
        // Graph API: PATCH /users/{id}/mailboxSettings
        $this->patch("/users/{$userId}/mailboxSettings", [
            'automaticRepliesSetting' => [
                'status' => 'disabled',
            ],
        ]);
        // Set forwarding via user profile (deliverAndRedirect)
        $this->patch("/users/{$userId}", [
            'otherMails'             => [],
            'proxyAddresses'         => [],
        ]);
        // Note: Full mailbox forwarding via Graph requires Exchange Online PowerShell or
        // the Set-Mailbox cmdlet. We log the intent here — actual forwarding should be
        // handled by an Exchange admin or a companion automation.
        Log::info("GraphService::forwardMailbox — intent logged for user {$userId} → {$forwardToAddress}. Complete via Exchange Online PowerShell: Set-Mailbox -Identity '{$userId}' -ForwardingSmtpAddress '{$forwardToAddress}' -DeliverToMailboxAndForward \$true");
    }

    /**
     * Downgrade user to Exchange-only (remove all M365 licenses except Exchange).
     * Keeps one Exchange plan and removes everything else.
     */
    public function downgradeToExchangeOnly(string $userId): void
    {
        $licenses = $this->getUserLicenses($userId);
        $toRemove = [];

        foreach ($licenses as $lic) {
            // Keep only Exchange Online (plan name contains EXCHANGE)
            $hasExchangeOnly = collect($lic['servicePlans'] ?? [])
                ->filter(fn($p) => stripos($p['servicePlanName'], 'EXCHANGE') !== false)
                ->isNotEmpty();

            $isExchangeOnlyPlan = count($lic['servicePlans'] ?? []) === 1 && $hasExchangeOnly;

            if (! $isExchangeOnlyPlan) {
                $toRemove[] = $lic['skuId'];
            }
        }

        if (! empty($toRemove)) {
            $this->post("/users/{$userId}/assignLicense", [
                'addLicenses'    => [],
                'removeLicenses' => $toRemove,
            ]);
        }
    }

    /**
     * Archive a mailbox by converting it to a shared mailbox (Exchange Online).
     * Shared mailboxes don't consume a paid license.
     */
    public function archiveMailbox(string $userId): void
    {
        // Graph does not directly support shared mailbox conversion.
        // We log the intent; actual execution needs Exchange Online PS or dedicated REST.
        Log::info("GraphService::archiveMailbox — intent logged for user {$userId}. Execute via Exchange Online PowerShell: Set-Mailbox -Identity '{$userId}' -Type Shared");

        // Disable sign-in as a proxy until Exchange archival completes
        $this->disableUser($userId);
    }

    // ─────────────────────────────────────────────────────────────
    // Intune — Device Management Scripts
    // Required Azure App Permission: DeviceManagementConfiguration.ReadWrite.All
    // ─────────────────────────────────────────────────────────────

    /**
     * Upload a PowerShell script to Intune Device Management.
     * Returns the Intune script ID.
     */
    public function uploadIntuneScript(
        string $displayName,
        string $ps1Content,
        string $description = ''
    ): string {
        $data = $this->post($this->betaUrl . '/deviceManagement/deviceManagementScripts', [
            'displayName'           => $displayName,
            'description'           => $description,
            'scriptContent'         => base64_encode($ps1Content),
            'runAs32Bit'            => false,
            'runAsAccount'          => 'system',
            'enforceSignatureCheck' => false,
            'fileName'              => \Illuminate\Support\Str::slug($displayName) . '.ps1',
        ]);

        return $data['id'] ?? throw new \RuntimeException('Intune script upload returned no ID.');
    }

    /**
     * Assign an Intune script to an Azure AD group.
     */
    public function assignIntuneScriptToGroup(
        string $intuneScriptId,
        string $azureGroupId
    ): void {
        $this->post(
            $this->betaUrl . "/deviceManagement/deviceManagementScripts/{$intuneScriptId}/assign",
            [
                'deviceManagementScriptAssignments' => [[
                    'target' => [
                        '@odata.type' => '#microsoft.graph.groupAssignmentTarget',
                        'groupId'     => $azureGroupId,
                    ],
                ]],
            ]
        );
    }

    /**
     * List all Intune device management scripts.
     */
    public function listIntuneScripts(): array
    {
        $result = $this->get(
            $this->betaUrl . '/deviceManagement/deviceManagementScripts',
            ['$select' => 'id,displayName,lastModifiedDateTime']
        );
        return $result['value'] ?? [];
    }

    /**
     * Paginate all device run states for a given Intune script.
     *
     * Each item in the callback contains:
     *   id                      — composite key: "{scriptId}:{managedDeviceId}"
     *                             Split on ':' and take index [1] to get the Intune device GUID.
     *   runState                — success | fail | pending | notApplicable | unknown
     *   resultMessage           — stdout written by the script (JSON string in our case)
     *   errorCode               — integer, 0 on success
     *   lastStateUpdateDateTime — ISO 8601 timestamp of last run
     *
     * NOTE: 'managedDeviceId' is NOT a selectable field on deviceManagementScriptDeviceState —
     *       the device ID must always be extracted by splitting the composite 'id' on ':'.
     *
     * NOTE on $top: Intune's internal proxy rejects $top > 100 with HTTP 400.
     *       We pass TOP_INTUNE (100) as the defaultTop for this endpoint.
     *
     * @param string   $scriptId  Intune deviceManagementScript GUID
     * @param callable $callback  Receives array of run-state objects per page
     */
    public function listScriptRunStates(string $scriptId, callable $callback): void
    {
        $url = $this->betaUrl
            . "/deviceManagement/deviceManagementScripts/{$scriptId}/deviceRunStates";

        $this->paginateWithCallback(
            $url,
            $callback,
            ['$select' => 'id,runState,resultMessage,errorCode,lastStateUpdateDateTime'],
            [],
            self::TOP_INTUNE   // ← 100 instead of 999
        );
    }

    /**
     * Delete an Intune device management script.
     */
    public function deleteIntuneScript(string $intuneScriptId): void
    {
        $this->delete($this->betaUrl . "/deviceManagement/deviceManagementScripts/{$intuneScriptId}");
    }

    // ─────────────────────────────────────────────────────────────
    // Group Management (create, delete, list members, search users)
    // ─────────────────────────────────────────────────────────────

    /**
     * Create a new Azure AD security group.
     * Returns the created group object including 'id'.
     */
    public function createGroup(string $name, string $description = ''): array
    {
        return $this->post('/groups', [
            'displayName'     => $name,
            'description'     => $description,
            'mailEnabled'     => false,
            'mailNickname'    => Str::slug($name, '_') ?: 'group_' . time(),
            'securityEnabled' => true,
            'groupTypes'      => [],
        ]);
    }

    /**
     * Delete an Azure AD group by its object ID.
     */
    public function deleteGroup(string $azureGroupId): bool
    {
        try {
            $this->delete("/groups/{$azureGroupId}");
            return true;
        } catch (\Throwable $e) {
            Log::warning("GraphService::deleteGroup failed for {$azureGroupId}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get members of a group. Returns array of ['id', 'displayName', 'userPrincipalName'].
     */
    public function getGroupMembers(string $azureGroupId): array
    {
        $result = $this->get("/groups/{$azureGroupId}/members", [
            '$select' => 'id,displayName,userPrincipalName',
        ]);
        return $result['value'] ?? [];
    }

    /**
     * Search Azure AD users by display name or UPN.
     * Uses ConsistencyLevel: eventual for advanced query support.
     * Returns array of user objects with id, displayName, userPrincipalName, jobTitle, department.
     */
    public function searchUsers(string $query): array
    {
        if (blank($query)) {
            return [];
        }

        $escaped = addslashes($query);
        $result  = $this->get('/users', [
            '$search'  => "\"displayName:{$escaped}\" OR \"userPrincipalName:{$escaped}\"",
            '$select'  => 'id,displayName,userPrincipalName,jobTitle,department',
            '$top'     => 20,
            '$orderby' => 'displayName',
        ], self::TIMEOUT_STANDARD, [
            'ConsistencyLevel' => 'eventual',
        ]);

        return $result['value'] ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    // Intune script assignment helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Get all group assignments for a given Intune script.
     * Each element has a 'target' key with 'groupId'.
     */
    public function getIntuneScriptAssignments(string $scriptId): array
    {
        $result = $this->get($this->betaUrl . "/deviceManagement/deviceManagementScripts/{$scriptId}/assignments");

        return $result['value'] ?? [];
    }

    /**
     * Unassign a script from a specific Azure AD group.
     * Works by fetching current assignments, filtering out the target group,
     * then re-posting the remaining list (Graph replaces all assignments in one call).
     */
    public function unassignIntuneScriptFromGroup(string $scriptId, string $groupId): void
    {
        $current  = $this->getIntuneScriptAssignments($scriptId);
        $filtered = array_values(array_filter(
            $current,
            fn($a) => ($a['target']['groupId'] ?? '') !== $groupId
        ));

        $this->post($this->betaUrl . "/deviceManagement/deviceManagementScripts/{$scriptId}/assign", [
            'deviceManagementScriptAssignments' => array_map(fn($a) => [
                'target' => [
                    '@odata.type' => '#microsoft.graph.groupAssignmentTarget',
                    'groupId'     => $a['target']['groupId'],
                ],
            ], $filtered),
        ]);
    }
}
