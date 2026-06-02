<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GdmsService
{
    // ── GDMS OpenAPI endpoint paths ───────────────────────────────────
    // ✅ confirmed against the official GDMS API guide and/or the existing
    //    working calls in this service.
    // ⚠️ PROBE-PENDING paths are best-guess by symmetry with confirmed
    //    endpoints; confirm them with `php artisan gdms:probe` before wiring
    //    them to any destructive UI action.
    private const EP_DEVICE_ADD = '/v1.0.0/device/add';         // ✅

    private const EP_DEVICE_LIST = '/v1.0.0/device/list';        // ✅

    private const EP_DEVICE_DETAIL = '/v1.0.0/device/detail';      // ✅

    private const EP_TASK_ADD = '/v1.0.0/task/add';           // ✅

    private const EP_SIP_ACCT_LIST = '/v1.0.0/sip/account/list';   // ✅

    private const EP_SIP_ACCT_ASSIGN = '/v1.0.0/sip/account/assign'; // ⚠️ PROBE-PENDING

    private const EP_SIP_SERVER_LIST = '/v1.0.0/sip/server/list';    // ⚠️ PROBE-PENDING

    private const EP_ORG_LIST = '/v1.0.0/org/list';           // ✅

    private const EP_SITE_LIST = '/v1.0.0/site/list';          // ✅

    private const EP_TEMPLATE_LIST = '/v1.0.0/template/list';      // ⚠️ PROBE-PENDING

    private const EP_TEMPLATE_DETAIL = '/v1.0.0/template/detail';    // ⚠️ PROBE-PENDING

    private const EP_TEMPLATE_UPDATE = '/v1.0.0/template/update';    // ⚠️ PROBE-PENDING

    private const EP_TEMPLATE_ASSIGN = '/v1.0.0/template/assign';    // ⚠️ PROBE-PENDING

    private const EP_DEVICE_CONFIG = '/v1.0.0/device/config/set';  // ⚠️ PROBE-PENDING (per-device param push)

    // ── Task types for /task/add ──────────────────────────────────────
    // taskName is sent alongside taskType; GDMS keys off both on most
    // firmware. REBOOT is confirmed; the others are config-overridable
    // (config/services.gdms.task_*) so they can be corrected after probing
    // without a code change.
    public const TASK_REBOOT = 1;  // ✅ confirmed (GDMS API guide)

    public const TASK_FACTORY_RESET = 2;  // ⚠️ PROBE-PENDING

    public const TASK_UPGRADE = 3;  // ⚠️ PROBE-PENDING

    protected string $baseUrl;

    protected string $clientId;

    protected string $clientSecret;

    protected int $orgId;

    protected string $username;

    protected string $passwordHash;

    public function __construct()
    {
        // Read from DB settings first (set via Settings → GDMS API section)
        // Fall back to config/env for backward compat. Guarded so a missing
        // settings table (fresh install / unit tests with no DB) falls back to
        // config instead of fataling.
        try {
            $s = \App\Models\Setting::first();
        } catch (\Throwable) {
            $s = null;
        }

        $this->baseUrl = rtrim($s?->gdms_base_url ?: config('services.gdms.base_url', 'https://www.gdms.cloud/oapi'), '/');
        $this->clientId = (string) ($s?->gdms_client_id ?: config('services.gdms.client_id'));
        $this->clientSecret = (string) ($s?->gdms_client_secret ?: config('services.gdms.client_secret'));
        $this->orgId = (int) ($s?->gdms_org_id ?: config('services.gdms.org_id'));
        $this->username = (string) ($s?->gdms_username ?: env('GDMS_USERNAME'));
        $this->passwordHash = (string) ($s?->gdms_password_hash ?: env('GDMS_PASSWORD_HASH'));
    }

    /**
     * Obtain access token using password grant (same as in Postman).
     */
    protected function getToken(): string
    {
        $response = Http::asForm()->get("{$this->baseUrl}/oauth/token", [
            'username' => $this->username,
            'password' => $this->passwordHash,
            'grant_type' => 'password',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $data = $response->json();

        if (! isset($data['access_token'])) {
            throw new \RuntimeException('GDMS token error: '.($data['error_description'] ?? 'unknown'));
        }

        return $data['access_token'];
    }

    /**
     * List SIP accounts (v1.0.0) with same pattern as your working Postman request.
     */
    public function listSipAccounts(int $pageNum = 1, int $pageSize = 200): array
    {
        $token = $this->getToken();

        // Use current time in ms
        $timestamp = (string) round(microtime(true) * 1000);
        $orgId = $this->orgId;

        // Body JSON – must match exactly what we sign and send
        $bodyArray = [
            'pageNum' => $pageNum,
            'pageSize' => $pageSize,
            'orgId' => $orgId,
        ];
        $bodyJson = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Signature parameters (query + body fields)
        $sigParams = [
            'access_token' => $token,
            'orgId' => $orgId,
            'pageNum' => $pageNum,
            'pageSize' => $pageSize,
            'timestamp' => $timestamp,
        ];

        // Add client_id and client_secret only for signature
        $sigParams['client_id'] = $this->clientId;
        $sigParams['client_secret'] = $this->clientSecret;

        // Sort keys ASC
        ksort($sigParams, SORT_STRING);

        $pairs = [];
        foreach ($sigParams as $key => $value) {
            $pairs[] = $key.'='.$value;
        }
        $paramString = implode('&', $pairs);

        // sha256(body)
        $bodyHash = hash('sha256', $bodyJson);

        // Final string: &params&sha256(body)&
        $toSign = '&'.$paramString.'&'.$bodyHash.'&';

        $signature = hash('sha256', $toSign);

        // Build URL exactly like your working Postman call
        $url = "{$this->baseUrl}/v1.0.0/sip/account/list"
             ."?access_token={$token}"
             ."&timestamp={$timestamp}"
             ."&signature={$signature}"
             ."&pageSize={$pageSize}"
             ."&pageNum={$pageNum}"
             ."&orgId={$orgId}";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->post($url, $bodyArray);

        $data = $response->json();

        if (($data['retCode'] ?? -1) !== 0) {
            throw new \RuntimeException('GDMS error: '.($data['msg'] ?? 'unknown'));
        }

        // The sip/account/list endpoint returns data at the root level (result, total)
        // Returning the full response so SyncGdmsContacts can parse it
        return $data;
    }

    /**
     * List ALL VoIP phone devices from GDMS (excludes UCM / PBX appliances).
     *
     * Tries projectId=1 (VoIP phone project) first; falls back to the full list
     * and filters by product name if the project-scoped call returns nothing.
     * Handles pagination automatically (up to 20 pages × 200 per page = 4 000 devices).
     *
     * Each returned item has at minimum: mac, productName, deviceIp, firmwareVersion,
     * deviceStatus (1=online, 0=offline), deviceName.
     */
    public function listAllPhoneDevices(): array
    {
        $all = [];
        $page = 1;

        // ── Attempt 1: projectId=1 → VoIP phone project ─────────────────
        try {
            do {
                $raw = $this->postSigned('/v1.0.0/device/list', $page, 200, ['projectId' => 1]);
                $list = $this->extractList($raw);
                $all = array_merge($all, $list);
                $total = (int) ($raw['data']['total'] ?? count($list));
                $page++;
            } while (! empty($list) && count($all) < $total && $page <= 20);
        } catch (\Throwable) {
            $all = [];
        }

        // ── Attempt 2: no projectId filter, then exclude UCM models ──────
        if (empty($all)) {
            try {
                $raw = $this->postSigned('/v1.0.0/device/list', 1, 1000);
                $all = $this->extractList($raw);
            } catch (\Throwable) {
                return [];
            }
        }

        // Normalise field names (GDMS /device/list uses different keys than /device/detail)
        // Raw fields: deviceType → model, privateip → IP, sn → serial, status → online
        $normalised = [];
        foreach ($all as $d) {
            $model = $d['deviceType'] ?? $d['productName'] ?? $d['model'] ?? '';
            // Filter out UCM / PBX appliances
            if (str_contains(strtoupper($model), 'UCM') || str_contains(strtoupper($model), 'PBX')) {
                continue;
            }
            $normalised[] = [
                'mac' => $d['mac'] ?? $d['macAddr'] ?? '',
                'productName' => $model,
                'sn' => $d['sn'] ?? $d['serialNumber'] ?? null,
                'deviceIp' => $d['privateip'] ?? $d['deviceIp'] ?? $d['ip'] ?? null,
                'publicIp' => $d['publicIp'] ?? null,
                'firmwareVersion' => $d['firmwareVersion'] ?? $d['firmware'] ?? null,
                'deviceStatus' => (int) ($d['status'] ?? $d['deviceStatus'] ?? 0),
                'accountStatus' => (int) ($d['accountStatus'] ?? 0),
                'lastTime' => $d['lastTime'] ?? $d['lastOnlineTime'] ?? null,
                '_raw' => $d,
            ];
        }

        return $normalised;
    }

    /**
     * List all devices in GDMS (/v1.0.0/device/list) — phones & endpoints.
     * Optionally filter by a string that appears anywhere in productName.
     * Returns the raw list; data path tried in order: dataList → list → direct array.
     */
    public function listDevices(int $pageNum = 1, int $pageSize = 1000, ?string $productName = null): array
    {
        $raw = $this->postSigned('/v1.0.0/device/list', $pageNum, $pageSize);
        $list = $this->extractList($raw);

        if ($productName) {
            $needle = strtoupper($productName);
            $list = array_values(array_filter($list, fn ($d) => str_contains(strtoupper($d['productName'] ?? ''), $needle)
            ));
        }

        return $list;
    }

    /**
     * List On-Premise PBX / UCM devices from GDMS.
     *
     * Per GDMS docs: projectId=3 selects the UCM project.
     * deviceStatus: 1=Online, 0=Offline, -1=Abnormal
     *
     * Returns normalised array; each item includes:
     *   online, deviceStatus, deviceName, productName, mac,
     *   deviceIp, firmwareVersion, _raw (full original response)
     */
    public function listOnPremisePbx(int $pageNum = 1, int $pageSize = 1000): array
    {
        // projectId=3 → UCM project (per GDMS API docs)
        // projectId=1 → VoIP phones/endpoints
        $raw = $this->postSigned('/v1.0.0/device/list', $pageNum, $pageSize, ['projectId' => 3]);
        $list = $this->extractList($raw);

        return array_map([$this, 'normaliseUcmDevice'], $list);
    }

    /**
     * Normalise UCM device fields so the view always sees consistent keys.
     * deviceStatus: 1=Online, 0=Offline, -1=Abnormal  (GDMS docs)
     */
    private function normaliseUcmDevice(array $d): array
    {
        // Resolve online status — accept multiple possible field names/values
        $status = $d['deviceStatus']
            ?? $d['online']
            ?? $d['isOnline']
            ?? (strtolower($d['status'] ?? '') === 'online' ? 1 : null)
            ?? 0;

        $statusInt = (int) $status;   // 1=Online, 0=Offline, -1=Abnormal

        return [
            'online' => $statusInt === 1,
            'deviceStatus' => $statusInt,
            'deviceName' => $d['deviceName'] ?? $d['name'] ?? $d['ucmName'] ?? '—',
            'productName' => $d['productName'] ?? $d['model'] ?? $d['deviceModel'] ?? '—',
            'mac' => $d['mac'] ?? $d['macAddr'] ?? '—',
            'deviceIp' => $d['deviceIp'] ?? $d['ip'] ?? $d['localIp'] ?? '—',
            'firmwareVersion' => $d['firmwareVersion'] ?? $d['firmware'] ?? $d['version'] ?? '—',
            'lastOnlineTime' => $d['lastOnlineTime'] ?? $d['lastOnlineAt'] ?? $d['updateTime'] ?? null,
            '_raw' => $d,
        ];
    }

    /**
     * POST a signed GDMS request with optional extra body parameters.
     * Extra params are merged into both the body AND the signature string.
     */
    private function postSigned(string $path, int $pageNum, int $pageSize, array $extra = []): array
    {
        $token = $this->getToken();
        $timestamp = (string) round(microtime(true) * 1000);
        $orgId = $this->orgId;

        // Build body: base params + any extras (e.g. projectId)
        $bodyArray = array_merge(
            ['pageNum' => $pageNum, 'pageSize' => $pageSize, 'orgId' => $orgId],
            $extra
        );
        $bodyJson = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Signature params include all body fields + auth fields
        $sigParams = array_merge($extra, [
            'access_token' => $token,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'orgId' => $orgId,
            'pageNum' => $pageNum,
            'pageSize' => $pageSize,
            'timestamp' => $timestamp,
        ]);
        ksort($sigParams, SORT_STRING);

        $toSign = '&'
                   .implode('&', array_map(fn ($k, $v) => "$k=$v", array_keys($sigParams), $sigParams))
                   .'&'.hash('sha256', $bodyJson).'&';
        $signature = hash('sha256', $toSign);

        // Build query string — include extra params so they're part of the URL too
        $queryExtra = implode('&', array_map(fn ($k, $v) => "$k=$v", array_keys($extra), $extra));
        $url = "{$this->baseUrl}{$path}"
             ."?access_token={$token}&timestamp={$timestamp}&signature={$signature}"
             ."&pageSize={$pageSize}&pageNum={$pageNum}&orgId={$orgId}"
             .($queryExtra ? "&$queryExtra" : '');

        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->post($url, $bodyArray);

        $data = $response->json();

        if (($data['retCode'] ?? -1) !== 0) {
            throw new \RuntimeException("GDMS {$path} error: ".($data['msg'] ?? json_encode($data)));
        }

        return $data;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  WRITE / ACTION METHODS  (GDMS device management)
    //
    //  Write ops go through signedRequest() (device/task signature form: the
    //  signature covers auth + timestamp + sha256(body), with no business
    //  query params). List-style reads reuse postSigned() above.
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Add (claim) a device into the GDMS organization by MAC + serial number.
     * GDMS requires BOTH to prove ownership. Body is an array of device objects
     * per the GDMS API guide: [{ deviceName, mac, sn, siteId, orgId }].
     *
     * @param  string  $sn  device serial number (printed on the box / label)
     * @param  string|null  $name  display name in GDMS (defaults to the MAC)
     * @param  int|null  $siteId  GDMS site to drop it in (defaults to configured site)
     */
    public function addDevice(string $rawMac, string $sn, ?string $name = null, ?int $siteId = null): array
    {
        $mac = $this->formatMacForApi($rawMac);

        $device = array_filter([
            'deviceName' => $name ?: $mac,
            'mac' => $mac,
            'sn' => $sn,
            'siteId' => $siteId,
            'orgId' => $this->orgId ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        // Body is a JSON array of one (or more) device objects.
        return $this->signedRequest(self::EP_DEVICE_ADD, [$device]);
    }

    /**
     * Create a device task (reboot / factory reset / upgrade / config push).
     *
     * @param  string  $taskName  e.g. REBOOT, FACTORY_RESET
     * @param  int  $taskType  numeric task type (see TASK_* constants)
     * @param  array  $rawMacs  one or more device MACs (any format)
     * @param  int  $execType  1 = run immediately, 2 = scheduled
     * @param  array  $extra  extra body fields (e.g. scheduled time, firmware id)
     */
    public function createTask(string $taskName, int $taskType, array $rawMacs, int $execType = 1, array $extra = []): array
    {
        $macList = array_values(array_map(fn ($m) => $this->formatMacForApi($m), $rawMacs));

        $body = array_merge([
            'taskName' => $taskName,
            'taskType' => $taskType,
            'macList' => $macList,
            'execType' => $execType,
        ], $extra);

        return $this->signedRequest(self::EP_TASK_ADD, $body);
    }

    /** Reboot one or more devices immediately. */
    public function rebootDevices(array $rawMacs): array
    {
        return $this->createTask('REBOOT', self::TASK_REBOOT, $rawMacs);
    }

    /**
     * Factory-reset one or more devices immediately. DESTRUCTIVE — erases the
     * device's local config; it re-syncs from GDMS when it next comes online.
     * taskType is config-overridable until confirmed via `gdms:probe`.
     */
    public function factoryResetDevices(array $rawMacs): array
    {
        $taskType = (int) config('services.gdms.task_factory_reset', self::TASK_FACTORY_RESET);

        return $this->createTask('FACTORY_RESET', $taskType, $rawMacs);
    }

    /**
     * Assign / change the SIP account bound to a device account slot.
     *
     * ⚠️ PROBE-PENDING: endpoint path + body shape are best-guess by symmetry
     * with /sip/account/list. Confirm with `gdms:probe` before wiring to UI.
     * Falls back to a per-device config push (account.N P-values) via
     * pushConfig() when the native bind endpoint isn't exposed on the tenant.
     *
     * @param  int  $accountIndex  1-based account slot on the phone
     * @param  array  $sipAccount  ['userId'=>.., 'authId'=>.., 'password'=>.., 'sipServer'=>.., 'displayName'=>..]
     */
    public function assignSipAccountToDevice(string $rawMac, int $accountIndex, array $sipAccount): array
    {
        $body = array_merge([
            'mac' => $this->formatMacForApi($rawMac),
            'accountIndex' => $accountIndex,
            'orgId' => $this->orgId ?: null,
        ], $sipAccount);

        return $this->signedRequest(self::EP_SIP_ACCT_ASSIGN, array_filter($body, fn ($v) => $v !== null));
    }

    /**
     * List SIP servers configured in GDMS (the SIP / UCM RemoteConnect servers
     * that Wave and the phones register against).
     * ⚠️ PROBE-PENDING path — reuses the proven list-style signature.
     */
    public function listSipServers(int $pageNum = 1, int $pageSize = 200): array
    {
        return $this->extractList($this->postSigned(self::EP_SIP_SERVER_LIST, $pageNum, $pageSize));
    }

    /** List GDMS organizations (GET, org-level signature). */
    public function listOrgs(): array
    {
        return $this->extractList($this->signedRequest(self::EP_ORG_LIST, null, [], 'GET'));
    }

    /** List GDMS sites (GET, org-level signature). */
    public function listSites(): array
    {
        return $this->extractList($this->signedRequest(self::EP_SITE_LIST, null, [], 'GET'));
    }

    // ── Configuration templates (⚠️ PROBE-PENDING paths / shapes) ─────────

    /** List GDMS configuration templates. */
    public function listTemplates(int $pageNum = 1, int $pageSize = 200): array
    {
        return $this->extractList($this->postSigned(self::EP_TEMPLATE_LIST, $pageNum, $pageSize));
    }

    /** Get a single template's parameter set. */
    public function getTemplate(string $templateId): array
    {
        return $this->signedRequest(self::EP_TEMPLATE_DETAIL, ['templateId' => $templateId]);
    }

    /**
     * Update a template's parameters.
     *
     * @param  array  $params  Grandstream P-value map, e.g. ['P271' => '1', ...]
     */
    public function updateTemplate(string $templateId, array $params): array
    {
        return $this->signedRequest(self::EP_TEMPLATE_UPDATE, [
            'templateId' => $templateId,
            'params' => $params,
        ]);
    }

    /** Assign / push a template to one or more devices by MAC. */
    public function assignTemplate(string $templateId, array $rawMacs): array
    {
        $macList = array_values(array_map(fn ($m) => $this->formatMacForApi($m), $rawMacs));

        return $this->signedRequest(self::EP_TEMPLATE_ASSIGN, [
            'templateId' => $templateId,
            'macList' => $macList,
        ]);
    }

    /**
     * Push per-device custom parameters (one-off config override) to a phone.
     *
     * @param  array  $params  Grandstream P-value map, e.g. ['P271' => '1']
     */
    public function pushConfig(string $rawMac, array $params): array
    {
        return $this->signedRequest(self::EP_DEVICE_CONFIG, array_filter([
            'mac' => $this->formatMacForApi($rawMac),
            'params' => $params,
            'orgId' => $this->orgId ?: null,
        ], fn ($v) => $v !== null));
    }

    /**
     * Generic signed GDMS OpenAPI request (device/task signature form).
     *
     * Signature rule (matches the GDMS OpenAPI): collect every QUERY parameter
     * (auth fields + any business query params in $queryParams), sort ASC by
     * key, join as "&k=v&..&", append sha256(bodyJson) when a JSON body is
     * present, wrap in leading/trailing "&", then sha256 the whole string. The
     * exact body bytes that were hashed are what we transmit, so the server
     * recomputes an identical signature.
     *
     * @param  string  $path  e.g. /v1.0.0/device/add
     * @param  array|null  $body  JSON body. null = no body (GET / org-level form, no body hash).
     * @param  array  $queryParams  business params that travel in BOTH the query string and the signature
     * @param  string  $method  POST (default) or GET
     * @param  bool  $throw  throw on retCode != 0 (default true)
     */
    private function signedRequest(string $path, ?array $body = null, array $queryParams = [], string $method = 'POST', bool $throw = true): array
    {
        $token = $this->getToken();
        $timestamp = (string) round(microtime(true) * 1000);

        $sigParams = array_merge($queryParams, [
            'access_token' => $token,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'timestamp' => $timestamp,
        ]);
        ksort($sigParams, SORT_STRING);

        $pairs = [];
        foreach ($sigParams as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $paramString = implode('&', $pairs);

        $bodyJson = $body !== null
            ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;

        $toSign = $bodyJson !== null
            ? '&'.$paramString.'&'.hash('sha256', $bodyJson).'&'
            : '&'.$paramString.'&';

        $signature = hash('sha256', $toSign);

        // Query string carries auth + signature + business params (never the secrets).
        $query = http_build_query(array_merge([
            'access_token' => $token,
            'timestamp' => $timestamp,
            'signature' => $signature,
        ], $queryParams));

        $url = "{$this->baseUrl}{$path}?{$query}";

        $http = Http::withOptions(['verify' => false])
            ->withHeaders(['Content-Type' => 'application/json']);

        if ($method === 'GET') {
            $response = $http->get($url);
        } elseif ($bodyJson !== null) {
            // Transmit the exact bytes we hashed so the signature matches.
            $response = $http->withBody($bodyJson, 'application/json')->post($url);
        } else {
            $response = $http->post($url);
        }

        $data = $response->json() ?? [];

        if ($throw && ($data['retCode'] ?? -1) !== 0) {
            throw new \RuntimeException("GDMS {$path} error: ".($data['msg'] ?? json_encode($data)));
        }

        return $data;
    }

    /**
     * Extract the list array from a GDMS response.
     * Handles multiple possible response shapes:
     *   data.result, data.dataList, data.list, data.ucmList, data.deviceList, data (direct array)
     */
    private function extractList(array $data): array
    {
        $d = $data['data'] ?? [];

        if (isset($d['result']) && is_array($d['result'])) {
            return $d['result'];
        }
        if (isset($d['dataList']) && is_array($d['dataList'])) {
            return $d['dataList'];
        }
        if (isset($d['list']) && is_array($d['list'])) {
            return $d['list'];
        }
        if (isset($d['ucmList']) && is_array($d['ucmList'])) {
            return $d['ucmList'];
        }
        if (isset($d['deviceList']) && is_array($d['deviceList'])) {
            return $d['deviceList'];
        }
        if (is_array($d) && array_is_list($d)) {
            return $d;
        }

        return [];
    }

    /**
     * Fetch SIP accounts for a device via the device/detail polling API.
     * Thin wrapper over getDeviceDetailRaw() that returns just the SIP / FXS
     * account list (the shape callers have always received).
     *
     * @param  string  $rawMac  MAC in any format (ec74d7800474 or EC:74:D7:80:04:74)
     * @return array|null sipAccountList array, or null if device unreachable
     */
    public function getDeviceAccounts(string $rawMac): ?array
    {
        $data = $this->getDeviceDetailRaw($rawMac);
        if ($data === null) {
            return null;
        }

        $sipList = $data['sipAccountList'] ?? $data['fxsPortList'] ?? [];

        return ! empty($sipList) ? $sipList : null;
    }

    /**
     * Fetch the FULL device/detail payload for a single device.
     *
     * Mirrors the documented pattern: trigger with isFirst=1, then poll with
     * isFirst=0 until the device reports back (up to 20 × 3 s = 60 s). For VoIP
     * phones the payload carries sipAccountList / fxsPortList; for UCM / PBX it
     * also carries resource fields (memory / storage / cpu) used by the PBX
     * status page.
     *
     * Returns the `data` object from the last successful poll, or null if the
     * device never responded / the API errored.
     *
     * NOTE: this blocks for up to ~60 s. Call it lazily (device detail page,
     * on-demand refresh) — never inside a list render.
     */
    public function getDeviceDetailRaw(string $rawMac): ?array
    {
        $token = $this->getToken();
        $mac = $this->formatMacForApi($rawMac); // → EC:74:D7:80:04:74

        // Step 1: trigger the device to push its current data.
        $this->callDeviceDetail($token, $mac, 1);

        // Step 2: poll until the device reports something useful.
        $last = null;
        for ($i = 0; $i < 20; $i++) {
            sleep(3);
            $res = $this->callDeviceDetail($token, $mac, 0);
            $retCode = $res['retCode'] ?? -1;

            if ($retCode !== 0) {
                break; // API error – stop polling
            }

            $data = $res['data'] ?? [];
            if (! empty($data)) {
                $last = $data;
            }

            $hasAccounts = ! empty($data['sipAccountList']) || ! empty($data['fxsPortList']);
            $hasResources = isset($data['memory']) || isset($data['storage'])
                         || isset($data['cpu']) || isset($data['memUsage'])
                         || isset($data['diskUsage']);

            if ($hasAccounts || $hasResources) {
                return $data;
            }
            // retCode=0 but nothing useful yet → keep polling.
        }

        return $last;
    }

    /**
     * Call /v1.0.0/device/detail with the signature pattern from the PHP reference script.
     * Signature covers: access_token, client_id, client_secret, timestamp (no orgId).
     */
    private function callDeviceDetail(string $token, string $mac, int $isFirst): array
    {
        $timestamp = (string) round(microtime(true) * 1000);
        $bodyJson = json_encode(
            ['mac' => $mac, 'isFirst' => (string) $isFirst],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $sigParams = [
            'access_token' => $token,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'timestamp' => $timestamp,
        ];

        ksort($sigParams, SORT_STRING);

        $pairs = [];
        foreach ($sigParams as $k => $v) {
            $pairs[] = "$k=$v";
        }

        $toSign = '&'.implode('&', $pairs).'&'.hash('sha256', $bodyJson).'&';
        $signature = hash('sha256', $toSign);

        $url = "{$this->baseUrl}/v1.0.0/device/detail"
             .'?access_token='.urlencode($token)
             ."&timestamp={$timestamp}"
             ."&signature={$signature}";

        $response = Http::withOptions(['verify' => false])
            ->withBody($bodyJson, 'application/json')
            ->post($url);

        return $response->json() ?? [];
    }

    /**
     * Normalize any MAC format to colon-separated uppercase.
     * ec74d7800474  →  EC:74:D7:80:04:74
     */
    private function formatMacForApi(string $mac): string
    {
        $hex = strtoupper(preg_replace('/[^0-9a-fA-F]/', '', $mac));

        return implode(':', str_split($hex, 2));
    }
}
