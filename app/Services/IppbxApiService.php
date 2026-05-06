<?php

namespace App\Services;

use App\Models\MonitoredHost;
use App\Models\UcmServer;
use App\Services\Snmp\SnmpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IppbxApiService
{
    protected UcmServer $server;
    protected string $baseUrl;
    protected string $originUrl;   // base URL without /api — used for headers
    protected string $username;
    protected string $password;
    protected ?string $cookie      = null;
    protected ?string $cloudDomain = null;  // GDMS cloud relay override for Wave QR

    // Grandstream UCM idle cookie timeout is ~5 min. Cache slightly under that
    // so reused cookies don't time out mid-call.
    private const COOKIE_TTL_SECONDS = 240;

    public function __construct(UcmServer $server)
    {
        $this->server      = $server;
        $this->originUrl   = rtrim($server->url, '/');
        $this->baseUrl     = $this->originUrl . '/api';
        $this->username    = $server->api_username;
        $this->password    = $server->api_password;
        $this->cloudDomain = $server->cloud_domain ?: null;
    }

    /**
     * Retrieve and cache full UCM stats for 60 seconds.
     */
    public static function getCachedStats(UcmServer $server): array
    {
        if (!$server->is_active) {
            return [
                'online' => false,
                'error'  => 'Server is disabled',
            ];
        }

        $cacheKey = "ucm_stats_{$server->id}_" . md5($server->url . $server->api_username);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function () use ($server) {
            try {
                $api = new self($server);
                $api->login();

                $system     = $api->getSystemStatus();
                $general    = $api->getSystemGeneralStatus();
                $network    = $api->getNetworkStatus();
                $extensions = $api->listExtensions(1, 2000);

                // Trunk listing can fail on some firmware — don't let it break the whole status
                $trunks = [];
                $trunkError = null;
                try {
                    $trunks = $api->listVoIPTrunks();
                } catch (\Exception $te) {
                    $trunkError = $te->getMessage();
                    \Log::warning("UCM {$server->name}: listVoIPTrunks failed — {$trunkError}");
                }

                // Disk / memory / CPU via SNMP using the matching MonitoredHost.
                // The Grandstream HTTPS API gates these behind a "System Status"
                // permission that's typically not granted to API users; SNMP is
                // already in use for extension/trunk sensors so it's free.
                $resources = self::collectSnmpResources($server);

                // Format the uptime with days
                if (!empty($system['up-time'])) {
                    $system['up-time-formatted'] = self::formatUptime($system['up-time']);
                }

                // Extension counts
                $extCounts = [
                    'total'       => count($extensions),
                    'idle'        => 0,
                    'inuse'       => 0,
                    'unavailable' => 0,
                    'other'       => 0,
                ];
                foreach ($extensions as $ext) {
                    $s = strtolower($ext['status'] ?? '');
                    if ($s === 'idle')                                  $extCounts['idle']++;
                    elseif (in_array($s, ['inuse', 'busy', 'ringing'])) $extCounts['inuse']++;
                    elseif ($s === 'unavailable')                       $extCounts['unavailable']++;
                    else                                                $extCounts['other']++;
                }

                // Trunk counts
                $trunkCounts = [
                    'total'       => count($trunks),
                    'reachable'   => 0,
                    'unreachable' => 0,
                ];
                foreach ($trunks as $trunk) {
                    $ts = strtolower($trunk['status'] ?? '');
                    if (str_contains($ts, 'unreachable')) {
                        $trunkCounts['unreachable']++;
                    } else {
                        $trunkCounts['reachable']++;
                    }
                }

                return [
                    'online'      => true,
                    'model'       => $general['product-model'] ?? 'UCM',
                    'firmware'    => $general['prog-version']  ?? '-',
                    'serial'      => $system['serial-number']  ?? '-',
                    'uptime_raw'  => $system['up-time']        ?? '',
                    'uptime'      => $system['up-time-formatted'] ?? '',
                    'mac'         => self::extractMac($network),
                    'system'      => $system,
                    'general'     => $general,
                    'extensions'  => $extCounts,
                    'trunk_counts'=> $trunkCounts,
                    'extensions_list' => $extensions,
                    'trunks_list' => $trunks,
                    'resources'   => $resources,
                ];
            } catch (\Exception $e) {
                return [
                    'online' => false,
                    'error'  => $e->getMessage(),
                ];
            }
        });
    }

    // ─────────────────────────────────────────────
    // Authentication
    // ─────────────────────────────────────────────

    /**
     * Get a valid cookie, reusing a cached one if available, otherwise
     * performing a fresh challenge→login under a per-UCM lock.
     *
     * Why the cache + lock: Grandstream's challenge action is session-bound.
     * If two processes call `challenge` for the same user concurrently, the
     * second one invalidates the first, and the first's `login` then fails
     * with status=-37 ("Wrong account or password"). Existing cookies on
     * other processes can also get invalidated, returning -6 mid-call.
     * Sharing one cookie across all callers eliminates both races.
     */
    public function login(): string
    {
        $cacheKey = $this->cookieCacheKey();

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $this->cookie = $cached;
            return $this->cookie;
        }

        // Serialize logins per UCM. Other processes block here until the
        // first one populates the cache, then they read the shared cookie.
        $lock = Cache::lock("ucm_login_lock_{$this->server->id}", 10);

        try {
            $lock->block(8); // wait up to 8s for an in-flight login to finish

            // Re-check cache: another process may have logged in while we waited.
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $this->cookie = $cached;
                return $this->cookie;
            }

            $cookie = $this->doLogin();
            Cache::put($cacheKey, $cookie, self::COOKIE_TTL_SECONDS);

            $this->cookie = $cookie;
            return $this->cookie;
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Raw challenge → MD5 token → login. Use login() instead — it adds
     * caching and per-UCM locking on top of this.
     */
    protected function doLogin(): string
    {
        // Step 1: get challenge
        $challengeResp = $this->post([
            'action'  => 'challenge',
            'user'    => $this->username,
            'version' => '1.0',
        ]);

        if (!isset($challengeResp['response']['challenge'])) {
            throw new \RuntimeException('UCM challenge failed: ' . json_encode($challengeResp));
        }

        $challenge = $challengeResp['response']['challenge'];

        // Step 2: MD5(challenge + password)
        $token = md5($challenge . $this->password);

        // Step 3: login
        $loginResp = $this->post([
            'action' => 'login',
            'user'   => $this->username,
            'token'  => $token,
        ]);

        if (!isset($loginResp['response']['cookie'])) {
            throw new \RuntimeException('UCM login failed: ' . json_encode($loginResp));
        }

        return $loginResp['response']['cookie'];
    }

    protected function cookieCacheKey(): string
    {
        return "ucm_cookie_{$this->server->id}";
    }

    /**
     * Drop the shared cookie cache so the next login() does a fresh handshake.
     * Call this on -6 (cookie invalid) responses.
     */
    protected function invalidateCachedCookie(): void
    {
        Cache::forget($this->cookieCacheKey());
        $this->cookie = null;
    }

    /**
     * Apply pending changes to the UCM.
     * Retries once with a fresh login if the cookie expired (status -6).
     */
    public function applyChanges(): array
    {
        // applyChanges can take longer — use 30s timeout
        $resp = $this->post([
            'action' => 'applyChanges',
            'cookie' => $this->cookie,
        ], 30);

        // -6 = invalid/expired cookie → re-login and retry once
        if (($resp['status'] ?? null) === -6) {
            Log::warning('IppbxApiService: applyChanges got -6 (expired cookie), re-logging in.');
            $this->invalidateCachedCookie();
            $this->login();

            $resp = $this->post([
                'action' => 'applyChanges',
                'cookie' => $this->cookie,
            ], 30);
        }

        if (($resp['status'] ?? -1) !== 0) {
            Log::error('IppbxApiService: applyChanges failed', ['response' => $resp]);
            throw new \RuntimeException('applyChanges failed: ' . json_encode($resp));
        }

        Log::info('IppbxApiService: applyChanges OK');
        return $resp;
    }

    // ─────────────────────────────────────────────
    // Extensions
    // ─────────────────────────────────────────────

    /**
     * List all extensions
     */
    public function listExtensions(int $page = 1, int $itemNum = 500): array
    {
        $this->ensureCookie();

        // 1. Fetch SIP accounts
        $resp = $this->post([
            'action'   => 'listAccount',
            'cookie'   => $this->cookie,
            'options'  => 'extension,account_type,fullname,status,addr',
            'page'     => (string) $page,
            'item_num' => (string) $itemNum,
            'sidx'     => 'extension',
            'sord'     => 'asc',
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            throw new \RuntimeException('listAccount failed: ' . json_encode($resp));
        }

        $accounts = $resp['response']['account'] ?? [];

        // 2. Fetch Users to get email, first_name, last_name
        $usersResp = $this->post([
            'action'   => 'listUser',
            'cookie'   => $this->cookie,
            'options'  => 'user_name,first_name,last_name,email',
            'page'     => (string) $page,
            'item_num' => (string) $itemNum,
            'sidx'     => 'user_name',
            'sord'     => 'asc',
        ]);

        $users = [];
        if (($usersResp['status'] ?? -1) === 0) {
            foreach ($usersResp['response']['user_id'] ?? [] as $u) {
                if (!empty($u['user_name'])) {
                    $users[$u['user_name']] = $u;
                }
            }
        }

        // 3. Merge User data into SIP accounts
        foreach ($accounts as &$acc) {
            $ext = $acc['extension'] ?? '';
            $acc['email'] = '';
            
            if (isset($users[$ext])) {
                $u = $users[$ext];
                $acc['email']      = $u['email'] ?? '';
                $acc['first_name'] = $u['first_name'] ?? '';
                $acc['last_name']  = $u['last_name'] ?? '';
                
                // If fullname is missing, construct from user profile
                if (empty($acc['fullname'])) {
                    $acc['fullname'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
                }
            }
        }

        return $accounts;
    }

    /**
     * Get a single extension details
     */
    public function getExtension(string $extension): array
    {
        $this->ensureCookie();

        // 1. Get SIP Account
        $resp = $this->post([
            'action'    => 'getSIPAccount',
            'cookie'    => $this->cookie,
            'extension' => $extension,
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            throw new \RuntimeException('getSIPAccount failed: ' . json_encode($resp));
        }

        $sipData = $resp['response']['extension'] ?? [];

        // 2. Get User
        $userResp = $this->post([
            'action'    => 'getUser',
            'cookie'    => $this->cookie,
            'user_name' => $extension,
        ]);

        if (($userResp['status'] ?? -1) === 0 && !empty($userResp['response']['user_name'])) {
            $u = $userResp['response']['user_name'];
            $sipData['email']      = $u['email'] ?? '';
            $sipData['first_name'] = $u['first_name'] ?? '';
            $sipData['last_name']  = $u['last_name'] ?? '';
            
            if (empty($sipData['fullname'])) {
                $sipData['fullname'] = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''));
            }
        } else {
            $sipData['email'] = '';
        }

        return $sipData;
    }

    /**
     * Get extension details for Wave / SIP client provisioning.
     * Returns extension, fullname, secret, email, and the UCM SIP domain.
     */
    public function getExtensionWave(string $extension): array
    {
        $details = $this->getExtension($extension);

        // Use the GDMS cloud domain if configured, otherwise fall back to UCM URL host
        $host = $this->cloudDomain
            ?? (parse_url($this->originUrl, PHP_URL_HOST) ?? $this->originUrl);

        return [
            'extension'    => $details['extension'] ?? $extension,
            'fullname'     => $details['fullname']  ?? '',
            'secret'       => $details['secret']    ?? '',
            'email'        => $details['email']     ?? '',
            'server'       => $host,
            'sip_uri'      => 'sip:' . ($details['extension'] ?? $extension) . '@' . $host,
            'cloud_domain' => $this->cloudDomain !== null,
        ];
    }

    /**
     * Create a new SIP extension
     */
    public function createExtension(array $data): array
    {
        $this->ensureCookie();

        $resp = $this->post(array_merge([
            'action' => 'addSIPAccountAndUser',
            'cookie' => $this->cookie,
        ], $data));

        if (($resp['status'] ?? -1) !== 0) {
            $status = $resp['status'] ?? 'unknown';
            $hint   = match($status) {
                -25    => ' — a field value was rejected by the UCM (check permission format: must be internal / internal-local / internal-local-national / internal-local-national-international)',
                -8     => ' — Extension number already exists on this UCM',
                default => '',
            };

            // Log the full payload (masking passwords) so we can debug -25 errors
            $debugPayload = $data;
            if (isset($debugPayload['secret']))        $debugPayload['secret']        = str_repeat('*', strlen($debugPayload['secret']))        . ' [len=' . strlen($data['secret']) . ', alnum=' . (ctype_alnum($data['secret']) ? 'yes' : 'NO') . ']';
            if (isset($debugPayload['user_password'])) $debugPayload['user_password'] = str_repeat('*', strlen($debugPayload['user_password'])) . ' [len=' . strlen($data['user_password']) . ', alnum=' . (ctype_alnum($data['user_password']) ? 'yes' : 'NO') . ']';
            Log::error('IppbxApiService: createExtension failed', ['status' => $status, 'payload' => $debugPayload, 'response' => $resp]);

            // ALSO log the FULL unmasked request JSON for deep debugging (TEMPORARY)
            Log::error('IppbxApiService: createExtension RAW DEBUG', [
                'raw_json_sent' => json_encode(['request' => array_merge([
                    'action' => 'addSIPAccountAndUser',
                    'cookie' => '[REDACTED]',
                ], $data)]),
            ]);

            throw new \RuntimeException('addSIPAccountAndUser failed: ' . json_encode($resp) . $hint);
        }

        $this->applyChanges();
        return $resp;
    }

    /**
     * Update an existing SIP extension
     */
    public function updateExtension(string $extension, array $data): array
    {
        $this->ensureCookie();

        // 1. Separate user profile fields from SIP fields
        $userFields = ['email', 'fullname', 'first_name', 'last_name', 'department', 'phone_number'];
        $userData = array_intersect_key($data, array_flip($userFields));
        
        $sipData = array_diff_key($data, array_flip($userFields));
        if (isset($data['fullname'])) {
            $sipData['fullname'] = $data['fullname']; // UCM SIP caller ID accepts fullname too
        }

        $resp = ['status' => 0];

        // 2. Update SIP Account
        if (!empty($sipData)) {
            $resp = $this->post(array_merge([
                'action'    => 'updateSIPAccount',
                'cookie'    => $this->cookie,
                'extension' => $extension,
            ], $sipData));

            if (($resp['status'] ?? -1) !== 0) {
                throw new \RuntimeException('updateSIPAccount failed: ' . json_encode($resp));
            }
        }

        // 3. Update User Profile (requires getUser to fetch user_id & privilege first)
        // SIP fields are already applied above — failures here only affect the
        // user record (name/email/department/phone), not the dialable extension.
        if (!empty($userData)) {
            $userResp = $this->post([
                'action'    => 'getUser',
                'cookie'    => $this->cookie,
                'user_name' => $extension,
            ]);

            if (($userResp['status'] ?? -1) !== 0 || empty($userResp['response']['user_name'])) {
                \Illuminate\Support\Facades\Log::error('updateExtension: getUser returned no user record', [
                    'extension' => $extension,
                    'response'  => $userResp,
                ]);
                throw new \RuntimeException(
                    "Cannot update user profile for extension {$extension} — no user record found in UCM. " .
                    "getUser response: " . json_encode($userResp)
                );
            }

            $compUser = $userResp['response']['user_name'];

            $firstName = $userData['first_name'] ?? $compUser['first_name'] ?? '';
            $lastName  = $userData['last_name']  ?? $compUser['last_name']  ?? '';

            // Fallback: split fullname into first_name and last_name if necessary
            if (isset($userData['fullname']) && !isset($userData['first_name'])) {
                $parts = explode(' ', trim($userData['fullname']), 2);
                $firstName = $parts[0] ?? '';
                $lastName  = $parts[1] ?? '';
            }

            $updateUserPayload = [
                'action'       => 'updateUser',
                'cookie'       => $this->cookie,
                'user_id'      => (string) ($compUser['user_id'] ?? ''),
                'user_name'    => $extension,
                'privilege'    => (string) ($compUser['privilege'] ?? '3'), // default privilege
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'email'        => $userData['email']        ?? $compUser['email']        ?? '',
                'department'   => $userData['department']   ?? $compUser['department']   ?? '',
                'phone_number' => $userData['phone_number'] ?? $compUser['phone_number'] ?? '',
            ];

            $uResp = $this->post($updateUserPayload);
            if (($uResp['status'] ?? -1) !== 0) {
                \Illuminate\Support\Facades\Log::error('updateExtension: updateUser failed', [
                    'extension' => $extension,
                    'payload'   => $updateUserPayload,
                    'response'  => $uResp,
                ]);
                throw new \RuntimeException(
                    "Failed to update user profile for extension {$extension}. " .
                    "updateUser response: " . json_encode($uResp)
                );
            }
        }

        // Removed $this->applyChanges() to prevent -45 "Operating too frequently"
        // when updateExtension is called immediately after createExtension.
        // Caller is responsible for invoking applyChanges() after the cooldown.
        return $resp;
    }

    /**
     * Delete an extension.
     *
     * Symmetric with createExtension (which uses addSIPAccountAndUser to create
     * BOTH the SIP account and the user record): we tear down both. Calling only
     * one would leave the other orphaned in UCM (e.g. an extension that still
     * registers but has no user record, or vice versa).
     *
     * Either side can succeed independently — UCM may have already cascade-removed
     * the other when the first call ran. We only fail if BOTH calls fail.
     */
    public function deleteExtension(string $extension): array
    {
        $this->ensureCookie();

        // 1. Delete the SIP account (the dialable extension number).
        $sipResp = $this->post([
            'action'    => 'deleteSIPAccount',
            'cookie'    => $this->cookie,
            'extension' => $extension,
        ]);
        $sipDeleted = ($sipResp['status'] ?? -1) === 0;

        // 2. Delete the user record (web account / softphone login).
        $userResp = $this->post([
            'action'    => 'deleteUser',
            'cookie'    => $this->cookie,
            'user_name' => $extension,
        ]);
        $userDeleted = ($userResp['status'] ?? -1) === 0;

        if (!$sipDeleted && !$userDeleted) {
            throw new \RuntimeException(
                "Failed to delete extension {$extension}. " .
                "deleteSIPAccount: " . json_encode($sipResp) . ' | ' .
                "deleteUser: "       . json_encode($userResp)
            );
        }

        if (!$sipDeleted) {
            \Illuminate\Support\Facades\Log::warning('deleteExtension: SIP delete failed but user delete succeeded', [
                'extension' => $extension,
                'response'  => $sipResp,
            ]);
        }
        if (!$userDeleted) {
            \Illuminate\Support\Facades\Log::warning('deleteExtension: user delete failed but SIP delete succeeded', [
                'extension' => $extension,
                'response'  => $userResp,
            ]);
        }

        $this->applyChanges();

        return [
            'status'       => 0,
            'sip_deleted'  => $sipDeleted,
            'user_deleted' => $userDeleted,
        ];
    }

    // ─────────────────────────────────────────────
    // System Status
    // ─────────────────────────────────────────────

    /**
     * Get system status (uptime, system time, serial number, part number)
     */
    public function getSystemStatus(): array
    {
        $this->ensureCookie();

        $resp = $this->post([
            'action' => 'getSystemStatus',
            'cookie' => $this->cookie,
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            throw new \RuntimeException('getSystemStatus failed: ' . json_encode($resp));
        }

        return $resp['response'] ?? [];
    }

    /**
     * Get system general status (firmware versions, product model)
     */
    public function getSystemGeneralStatus(): array
    {
        $this->ensureCookie();

        $resp = $this->post([
            'action' => 'getSystemGeneralStatus',
            'cookie' => $this->cookie,
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            throw new \RuntimeException('getSystemGeneralStatus failed: ' . json_encode($resp));
        }

        return $resp['response'] ?? [];
    }

    /**
     * Get storage device status (internal disk, SD, USB) with usage info.
     *
     * Grandstream renamed this action across firmware revisions, so we try
     * the known candidates in order and return the first one that succeeds.
     * Returns an empty array if no candidate is supported — caller should
     * treat storage as optional.
     *
     * Each device in the returned list is normalized to:
     *   ['name' => ..., 'media' => ..., 'total' => ..., 'used' => ...,
     *    'available' => ..., 'percent' => 0..100, 'path' => ...]
     */
    public function getStorageStatus(): array
    {
        $this->ensureCookie();

        $candidates = ['getStorageStatus', 'getStorageDeviceList', 'getStorageInfo'];

        foreach ($candidates as $action) {
            try {
                $resp = $this->post([
                    'action' => $action,
                    'cookie' => $this->cookie,
                ]);
            } catch (\Throwable $e) {
                continue;
            }

            if (($resp['status'] ?? -1) !== 0) {
                continue;
            }

            $devices = $this->extractStorageDevices($resp['response'] ?? []);
            if (!empty($devices)) {
                return $devices;
            }
        }

        return [];
    }

    /**
     * Normalize a Grandstream storage response into a flat list of devices.
     * Grandstream firmware uses different keys/shapes (storage_device,
     * storage_list, devices, …) so we probe a few and fall back to picking
     * the first array of associative rows.
     */
    protected function extractStorageDevices(array $response): array
    {
        $list = $response['storage_device']
             ?? $response['storage_list']
             ?? $response['devices']
             ?? $response['storage']
             ?? null;

        if ($list === null) {
            // Fallback: first array-of-objects we find.
            foreach ($response as $value) {
                if (is_array($value) && !empty($value) && is_array(reset($value))) {
                    $list = $value;
                    break;
                }
            }
        }

        if (!is_array($list)) {
            return [];
        }

        // Single device returned as an associative array — wrap it.
        if (!empty($list) && (isset($list['total']) || isset($list['used']) || isset($list['media']))) {
            $list = [$list];
        }

        $out = [];
        foreach ($list as $dev) {
            if (!is_array($dev)) {
                continue;
            }

            $total     = $dev['total']     ?? $dev['size']      ?? $dev['capacity']  ?? null;
            $used      = $dev['used']      ?? $dev['used_size'] ?? null;
            $available = $dev['available'] ?? $dev['free']      ?? $dev['free_size'] ?? null;
            $percent   = $dev['percent']   ?? $dev['usage']     ?? null;

            if ($percent === null) {
                $percent = self::computeStoragePercent($total, $used, $available);
            } else {
                // Some firmware returns "45%" or "45" — strip and clamp.
                $percent = (int) preg_replace('/[^0-9]/', '', (string) $percent);
                $percent = max(0, min(100, $percent));
            }

            $out[] = [
                'name'      => $dev['name']  ?? $dev['label']  ?? $dev['media'] ?? 'Storage',
                'media'     => $dev['media'] ?? $dev['type']   ?? '',
                'path'      => $dev['path']  ?? $dev['mount']  ?? '',
                'total'     => $total,
                'used'      => $used,
                'available' => $available,
                'percent'   => $percent,
            ];
        }

        return $out;
    }

    /**
     * Best-effort percent-used given any combination of total/used/available.
     * Handles unit-suffixed strings like "16G", "200M", "1.2T".
     */
    protected static function computeStoragePercent($total, $used, $available): ?int
    {
        $totalB = self::parseSize($total);
        $usedB  = self::parseSize($used);
        $availB = self::parseSize($available);

        if ($totalB > 0 && $usedB !== null) {
            return max(0, min(100, (int) round($usedB / $totalB * 100)));
        }
        if ($totalB > 0 && $availB !== null) {
            return max(0, min(100, (int) round(($totalB - $availB) / $totalB * 100)));
        }
        return null;
    }

    /**
     * Parse a Grandstream size string ("16G", "200M", "1.2T", "1234567")
     * into bytes. Returns null if unparseable.
     */
    protected static function parseSize($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (!preg_match('/^\s*([\d.]+)\s*([KMGTP]?)B?\s*$/i', (string) $value, $m)) {
            return null;
        }

        $num    = (float) $m[1];
        $unit   = strtoupper($m[2] ?? '');
        $factor = match ($unit) {
            'K'     => 1024,
            'M'     => 1024 ** 2,
            'G'     => 1024 ** 3,
            'T'     => 1024 ** 4,
            'P'     => 1024 ** 5,
            default => 1,
        };

        return $num * $factor;
    }

    // ─────────────────────────────────────────────
    // Network Status
    // ─────────────────────────────────────────────

    /**
     * Get network interface status — returns MAC address, IP, etc.
     * UCM returns an object keyed by interface name (eth0, eth1 …).
     */
    public function getNetworkStatus(): array
    {
        $this->ensureCookie();

        $resp = $this->post([
            'action' => 'getNetworkStatus',
            'cookie' => $this->cookie,
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            // Non-fatal — some UCM firmware versions don't expose this action
            return [];
        }

        return $resp['response'] ?? [];
    }

    // ─────────────────────────────────────────────
    // SNMP system resources (disk / memory / CPU)
    // ─────────────────────────────────────────────

    // Grandstream UCM63xx SNMP MIB — gsObject.IppbxMib.sSysinfo.*
    // See GS-UCM63XX-SNMP-MIB published by Grandstream.
    private const SNMP_OID_DISK_USAGE   = '1.3.6.1.4.1.12581.2.2.6';
    private const SNMP_OID_MEMORY_USAGE = '1.3.6.1.4.1.12581.2.2.7';
    private const SNMP_OID_CPU_USAGE    = '1.3.6.1.4.1.12581.2.2.8';

    /**
     * Collect disk / memory / CPU usage via SNMP for a given UCM.
     *
     * The HTTPS API actions (getStorageStatus etc.) require the "System Status"
     * permission which is typically not granted on the API user. SNMP exposes
     * the same data via the GS-UCM63XX-SNMP-MIB sSysinfo OIDs and is already
     * used elsewhere in the app, so we leverage that.
     *
     * Returns an array with disk/memory/cpu sections, or an empty array if the
     * UCM has no matching MonitoredHost or SNMP is disabled/unreachable.
     */
    public static function collectSnmpResources(UcmServer $server): array
    {
        $host = self::matchSnmpHost($server);
        if (!$host) {
            return [];
        }

        try {
            $client = (new SnmpClient($host))->connect();
            $raw = $client->getMultiple([
                self::SNMP_OID_DISK_USAGE,
                self::SNMP_OID_MEMORY_USAGE,
                self::SNMP_OID_CPU_USAGE,
            ]);
            $client->close();
        } catch (\Throwable $e) {
            Log::debug("UCM {$server->name}: SNMP resources query failed — " . $e->getMessage());
            return [];
        }

        if (empty($raw)) {
            return [];
        }

        $disk   = self::pickSnmpValue($raw, self::SNMP_OID_DISK_USAGE);
        $memory = self::pickSnmpValue($raw, self::SNMP_OID_MEMORY_USAGE);
        $cpu    = self::pickSnmpValue($raw, self::SNMP_OID_CPU_USAGE);

        $out = [];
        if ($disk   !== null) $out['disk']   = self::parseSnmpUsage($disk);
        if ($memory !== null) $out['memory'] = self::parseSnmpUsage($memory);
        if ($cpu    !== null) $out['cpu']    = self::parseSnmpUsage($cpu);

        return $out;
    }

    /**
     * Find the MonitoredHost that matches a UCM by its IP/host portion of url.
     * Returns null if not found or SNMP isn't enabled for that host.
     */
    protected static function matchSnmpHost(UcmServer $server): ?MonitoredHost
    {
        $host = parse_url($server->url, PHP_URL_HOST);
        if (!$host) {
            return null;
        }

        $mh = MonitoredHost::where('ip', $host)->where('snmp_enabled', true)->first();
        return $mh ?: null;
    }

    /**
     * SnmpClient::getMultiple() may key results by either the dotted-numeric
     * OID (the format we asked for) or a stringified textual OID; tolerate
     * both, and also any single-value response shape.
     */
    protected static function pickSnmpValue(array $raw, string $oid): ?string
    {
        if (array_key_exists($oid, $raw))         return is_string($raw[$oid]) ? $raw[$oid] : (string) $raw[$oid];
        if (array_key_exists(".{$oid}", $raw))    return (string) $raw[".{$oid}"];

        // Fallback: a key that ends with the OID's last segments.
        $tail = implode('.', array_slice(explode('.', $oid), -3));
        foreach ($raw as $k => $v) {
            if (str_ends_with((string) $k, $tail)) {
                return is_scalar($v) ? (string) $v : null;
            }
        }
        return null;
    }

    /**
     * Parse a Grandstream sysinfo SNMP value like "4%", "used 4% of 250M",
     * "12.3%" into a percent-used integer plus the original raw label.
     */
    protected static function parseSnmpUsage(string $raw): array
    {
        // Strip SNMP type prefixes that snmpget CLI mode sometimes prepends
        // (e.g. 'STRING: "4%"' or 'INTEGER: 4').
        $clean = preg_replace('/^[A-Z][A-Z0-9-]*:\s*/i', '', trim($raw));
        $clean = trim($clean, " \t\n\r\0\x0B\"'");

        $percent = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $clean, $m)) {
            $percent = (int) round((float) $m[1]);
            $percent = max(0, min(100, $percent));
        }

        return [
            'percent' => $percent,
            'raw'     => $clean,
        ];
    }

    /**
     * Extract the primary MAC address from a getNetworkStatus() response.
     * Checks eth0 first, then any other interface.
     */
    public static function extractMac(array $networkStatus): ?string
    {
        if (empty($networkStatus)) {
            return null;
        }

        // Try common interface names in priority order
        foreach (['eth0', 'eth1', 'br0', 'lan'] as $iface) {
            if (!empty($networkStatus[$iface]['mac'])) {
                return strtoupper($networkStatus[$iface]['mac']);
            }
        }

        // Fall back: first key that has a 'mac' field
        foreach ($networkStatus as $iface => $data) {
            if (!empty($data['mac'])) {
                return strtoupper($data['mac']);
            }
        }

        return null;
    }

    // ─────────────────────────────────────────────
    // VoIP Trunks
    // ─────────────────────────────────────────────

    /**
     * List all VoIP trunks
     */
    public function listVoIPTrunks(int $page = 1, int $itemNum = 500): array
    {
        $this->ensureCookie();

        $resp = $this->post([
            'action'   => 'listVoIPTrunk',
            'cookie'   => $this->cookie,
            'options'  => 'trunk_index,trunk_name,host,trunk_type,username,trunks.out_of_service,status',
            'page'     => (string) $page,
            'item_num' => (string) $itemNum,
            'sidx'     => 'trunk_index',
            'sord'     => 'asc',
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            throw new \RuntimeException('listVoIPTrunk failed: ' . json_encode($resp));
        }

        return $resp['response']['voip_trunk'] ?? [];
    }

    // ─────────────────────────────────────────────
    // Active Calls
    // ─────────────────────────────────────────────

    /**
     * List active (in-progress) calls from the UCM.
     * Uses the Grandstream listActiveCalls API action.
     */
    public function listActiveCalls(): array
    {
        $this->ensureCookie();

        $resp = $this->post([
            'action'   => 'listActiveCalls',
            'cookie'   => $this->cookie,
            'item_num' => '200',
            'page'     => '1',
            'sidx'     => 'caller_id',
            'sord'     => 'asc',
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            $code = $resp['status'] ?? 'null';
            // -47 = "No privilege" — the UCM API user is missing the privilege
            // to query active calls. Fix in UCM admin: Maintenance → User
            // Management → edit the API user → grant "Real-Time Status / CDR"
            // privilege (or use the super-admin account).
            if ((int) $code === -47) {
                Log::warning("IppbxApiService: listActiveCalls denied by UCM ({$this->server->name}) — API user '{$this->server->api_username}' lacks privilege. Grant 'Real-Time Status / Active Calls / CDR' on the UCM web admin.");
            } else {
                Log::debug("IppbxApiService: listActiveCalls returned status {$code} on {$this->server->name}");
            }
            return [];
        }

        $response = $resp['response'] ?? [];

        // Grandstream firmware uses different keys depending on version:
        //   UCM62xx / UCM63xx newer:  response.active_call   (singular, underscore)
        //   Some firmware:            response.active_calls  (plural)
        //   Older firmware:           response.activecall    (no underscore)
        $calls = $response['active_call']
              ?? $response['active_calls']
              ?? $response['activecall']
              ?? null;

        // Defensive fallback — pick the first array of associative rows we find.
        if ($calls === null) {
            foreach ($response as $key => $value) {
                if (is_array($value) && !empty($value) && is_array(reset($value))) {
                    Log::debug("IppbxApiService: listActiveCalls used fallback key '{$key}'");
                    return $value;
                }
            }
            Log::debug('IppbxApiService: listActiveCalls returned no recognizable calls array', [
                'keys' => array_keys($response),
            ]);
            return [];
        }

        if (!is_array($calls)) {
            return [];
        }

        // UCM may return a single call as an associative array instead of a list of calls.
        // Wrap it so the caller can always foreach over a list.
        if (!empty($calls) && (isset($calls['caller_id']) || isset($calls['caller']) || isset($calls['src']))) {
            return [$calls];
        }

        return $calls;
    }

    // ─────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────

    /**
     * Format a UCM uptime string into a human-readable form with "d" for days.
     * Handles formats: "HH:MM:SS" (HH may exceed 24), "X days HH:MM:SS".
     * Returns e.g. "3d 5h 22m" or "5h 22m" or "22m".
     */
    public static function formatUptime(string $uptime): string
    {
        $uptime = trim($uptime);

        // Format: "X day(s) HH:MM:SS"  or  "X days, HH:MM:SS"
        if (preg_match('/(\d+)\s*days?\s*,?\s*(\d+):(\d+):(\d+)/i', $uptime, $m)) {
            $days  = (int)$m[1];
            $hours = (int)$m[2];
            $mins  = (int)$m[3];
            $parts = [];
            if ($days  > 0) $parts[] = "{$days}d";
            if ($hours > 0) $parts[] = "{$hours}h";
            $parts[] = "{$mins}m";
            return implode(' ', $parts) ?: '0m';
        }

        // Format: "HH:MM:SS" where HH can be > 24
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $uptime, $m)) {
            $totalHours = (int)$m[1];
            $days  = intdiv($totalHours, 24);
            $hours = $totalHours % 24;
            $mins  = (int)$m[2];
            $parts = [];
            if ($days  > 0) $parts[] = "{$days}d";
            if ($hours > 0) $parts[] = "{$hours}h";
            $parts[] = "{$mins}m";
            return implode(' ', $parts) ?: '0m';
        }

        // Return as-is if unparseable
        return $uptime;
    }

    protected function ensureCookie(): void
    {
        if (!$this->cookie) {
            $this->login();
        }
    }

    protected function post(array $payload, int $timeout = 15): array
    {
        $resp = $this->doPost($payload, $timeout);

        // Auto-recover from a stale shared cookie. If this was a cookie-bearing
        // call and the UCM rejected the cookie (-6), drop the cache, re-login,
        // and retry once. applyChanges() has its own retry logic, so skip it.
        $action = $payload['action'] ?? '';
        if (
            isset($payload['cookie'])
            && ($resp['status'] ?? null) === -6
            && $action !== 'applyChanges'
            && $action !== ''
        ) {
            Log::info("IppbxApiService: cookie -6 on '{$action}' for {$this->server->name} — re-login + retry");
            $this->invalidateCachedCookie();
            $this->login();
            $payload['cookie'] = $this->cookie;
            $resp = $this->doPost($payload, $timeout);
        }

        return $resp;
    }

    protected function doPost(array $payload, int $timeout): array
    {
        try {
            $body = json_encode(['request' => $payload]);

            $response = Http::withoutVerifying()
                ->timeout($timeout)
                ->withHeaders([
                    'Content-Type'     => 'application/json;charset=UTF-8',
                    'Accept'           => 'application/json',
                    'Connection'       => 'close',
                    'Origin'           => $this->originUrl,
                    'Referer'          => $this->originUrl . '/',
                    'X-Requested-With' => 'XMLHttpRequest',
                ])
                ->withBody($body, 'application/json')
                ->post($this->baseUrl);

            $json = $response->json();

            if ($json === null) {
                $httpStatus = $response->status();
                $bodyPreview = substr($response->body(), 0, 400);
                Log::error('IppbxApiService: non-JSON response', [
                    'url'    => $this->baseUrl,
                    'status' => $httpStatus,
                    'body'   => $bodyPreview,
                ]);
                throw new \RuntimeException(
                    "UCM returned HTTP {$httpStatus} (non-JSON). " .
                    "Response: " . ($bodyPreview ?: '(empty)')
                );
            }

            return $json;

        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('IppbxApiService error: ' . $e->getMessage(), ['url' => $this->baseUrl]);
            throw new \RuntimeException(
                'UCM connection failed: ' . $e->getMessage()
            );
        }
    }
}
