<?php

namespace App\Services;

use App\Models\UcmServer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IppbxApiService
{
    protected string $baseUrl;
    protected string $originUrl;   // base URL without /api — used for headers
    protected string $username;
    protected string $password;
    protected ?string $cookie      = null;
    protected ?string $cloudDomain = null;  // GDMS cloud relay override for Wave QR

    public function __construct(UcmServer $server)
    {
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
                $trunks     = $api->listVoIPTrunks();

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
     * Full login flow: challenge → MD5 token → login → return cookie
     */
    public function login(): string
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

        $this->cookie = $loginResp['response']['cookie'];
        return $this->cookie;
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
            $this->cookie = null;
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
        if (!empty($userData)) {
            $userResp = $this->post([
                'action'    => 'getUser',
                'cookie'    => $this->cookie,
                'user_name' => $extension,
            ]);

            if (($userResp['status'] ?? -1) === 0 && !empty($userResp['response']['user_name'])) {
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
                    'action'     => 'updateUser',
                    'cookie'     => $this->cookie,
                    'user_id'    => (string) ($compUser['user_id'] ?? ''),
                    'user_name'  => $extension,
                    'privilege'  => (string) ($compUser['privilege'] ?? '3'), // default privilege
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'email'      => $userData['email'] ?? $compUser['email'] ?? '',
                    'department' => $userData['department'] ?? $compUser['department'] ?? '',
                    'phone_number' => $userData['phone_number'] ?? $compUser['phone_number'] ?? '',
                ];

                $uResp = $this->post($updateUserPayload);
                if (($uResp['status'] ?? -1) !== 0) {
                    \Illuminate\Support\Facades\Log::warning('updateUser failed', ['payload' => $updateUserPayload, 'response' => $uResp]);
                }
            }
        }

        // Removed $this->applyChanges() to prevent -45 "Operating too frequently" 
        // when updateExtension is called immediately after createExtension.
        return $resp;
    }

    /**
     * Delete an extension
     */
    public function deleteExtension(string $extension): array
    {
        $this->ensureCookie();

        $resp = $this->post([
            'action'    => 'deleteUser',
            'cookie'    => $this->cookie,
            'user_name' => $extension,
        ]);

        if (($resp['status'] ?? -1) !== 0) {
            throw new \RuntimeException('deleteUser failed: ' . json_encode($resp));
        }

        $this->applyChanges();
        return $resp;
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

        // Some UCM firmware may not support this action — gracefully return empty
        if (($resp['status'] ?? -1) !== 0) {
            Log::debug('IppbxApiService: listActiveCalls returned status ' . ($resp['status'] ?? 'null'));
            return [];
        }

        return $resp['response']['active_calls'] ?? $resp['response']['activecall'] ?? [];
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
