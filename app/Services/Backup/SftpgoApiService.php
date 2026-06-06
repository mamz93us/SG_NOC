<?php

namespace App\Services\Backup;

use App\Models\BackupAccount;
use App\Models\Setting;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * SFTPGo REST API client (https://github.com/drakkan/sftpgo) — the NOC's control
 * plane for the backup-ingestion server. Manages one virtual SFTP/FTP user per
 * device ("user & password manager"); the upload webhook + the sftp-backups
 * sweeper handle the data plane.
 *
 * Auth: GET /api/v2/token with HTTP Basic admin creds → a short-lived JWT used as
 * a Bearer token (cached until just before expiry). A static API key, if set,
 * is used directly as the Bearer instead. On a 401 we bust the cached JWT and
 * retry once (covers token expiry between cache and call).
 *
 * NOTE: re-confirm the exact user/permission JSON against the deployed build's
 * /openapi Swagger — SFTPGo can rename fields across minor versions.
 */
class SftpgoApiService
{
    private const TOKEN_CACHE_KEY = 'sftpgo_jwt_token';

    private string $baseUrl;

    private string $adminUser;

    private string $adminPass;

    private string $apiKey;

    public function __construct(
        ?string $baseUrl = null,
        ?string $adminUser = null,
        ?string $adminPass = null,
        ?string $apiKey = null,
    ) {
        $s = Setting::get();
        $this->baseUrl = rtrim($baseUrl ?? ($s->sftpgo_base_url ?: 'http://127.0.0.1:8090'), '/');
        $this->adminUser = $adminUser ?? ($s->sftpgo_admin_username ?? '') ?? '';
        $this->adminPass = $adminPass ?? ($s->sftpgo_admin_password ?? '') ?? '';
        $this->apiKey = $apiKey ?? ($s->sftpgo_api_key ?? '') ?? '';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== ''
            && ($this->apiKey !== '' || ($this->adminUser !== '' && $this->adminPass !== ''));
    }

    // ─── User management ──────────────────────────────────────────

    /** Create the device's SFTPGo virtual user; if it already exists, update it. */
    public function createUser(BackupAccount $account, string $plainPassword): array
    {
        $resp = $this->request('POST', '/api/v2/users', $this->userPayload($account, $plainPassword));

        if ($resp->status() === 409) {
            return $this->setPassword($account, $plainPassword);
        }
        $this->assertOk($resp, "create user {$account->sftpgo_username}");

        return $resp->json() ?? [];
    }

    /** Push the account's current settings (protocols, quota, status) — keeps the password. */
    public function updateUser(BackupAccount $account): array
    {
        $resp = $this->request('PUT', $this->userPath($account->sftpgo_username), $this->userPayload($account, null));
        $this->assertOk($resp, "update user {$account->sftpgo_username}");

        return $resp->json() ?? [];
    }

    /** Rotate the password (full PUT incl. the new password — partial PUTs reset omitted fields). */
    public function setPassword(BackupAccount $account, string $plainPassword): array
    {
        $resp = $this->request('PUT', $this->userPath($account->sftpgo_username), $this->userPayload($account, $plainPassword));
        $this->assertOk($resp, "set password for {$account->sftpgo_username}");

        return $resp->json() ?? [];
    }

    /** Delete the SFTPGo user. A 404 is treated as success (idempotent). */
    public function deleteUser(string $username): void
    {
        $resp = $this->request('DELETE', $this->userPath($username));
        if ($resp->status() === 404) {
            return;
        }
        $this->assertOk($resp, "delete user {$username}");
    }

    /** Fetch a user (for the show-page cheat-sheet / verification); null on 404. */
    public function getUser(string $username): ?array
    {
        $resp = $this->request('GET', $this->userPath($username));
        if ($resp->status() === 404) {
            return null;
        }
        $this->assertOk($resp, "get user {$username}");

        return $resp->json() ?? [];
    }

    public function testConnection(): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'detail' => 'SFTPGo base URL / admin credentials not set in settings.'];
        }

        try {
            $resp = $this->request('GET', '/api/v2/users?limit=1');
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }

        if ($resp->successful()) {
            return ['ok' => true, 'detail' => "Connected to SFTPGo at {$this->baseUrl}."];
        }
        if ($resp->status() === 401) {
            return ['ok' => false, 'detail' => 'Auth failed (401) — check the admin username/password or API key.'];
        }

        return ['ok' => false, 'detail' => "SFTPGo returned HTTP {$resp->status()} for /api/v2/users — check the base URL ({$this->baseUrl})."];
    }

    // ─── Internals ────────────────────────────────────────────────

    private function userPath(string $username): string
    {
        return '/api/v2/users/'.rawurlencode($username);
    }

    /**
     * SFTPGo user JSON. Upload-only + mkdir on the root; protocols restricted to
     * what the account allows; HTTP/WebDAV always denied. Password is included
     * only when (re)setting it — an absent password on PUT keeps the existing one.
     */
    private function userPayload(BackupAccount $account, ?string $password): array
    {
        $payload = [
            'username' => $account->sftpgo_username,
            'status' => $account->is_active ? 1 : 0,
            'home_dir' => $account->homeDir(),
            'permissions' => ['/' => ['upload', 'create_dirs', 'list']],
            'quota_size' => $account->quotaBytes(),
            'filters' => [
                'allowed_protocols' => $account->allowedProtocols(),
                'denied_protocols' => ['HTTP', 'DAV'],
            ],
        ];

        if ($password !== null && $password !== '') {
            $payload['password'] = $password;
        }

        return $payload;
    }

    private function request(string $method, string $path, ?array $body = null): Response
    {
        $resp = $this->dispatch($method, $path, $body);

        // A short-lived JWT may have expired between cache and call — bust + retry once.
        if ($resp->status() === 401 && $this->apiKey === '') {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $resp = $this->dispatch($method, $path, $body);
        }

        return $resp;
    }

    private function dispatch(string $method, string $path, ?array $body): Response
    {
        $req = Http::withToken($this->bearer())->acceptJson()->timeout(20);

        return $body !== null
            ? $req->send($method, $this->baseUrl.$path, ['json' => $body])
            : $req->send($method, $this->baseUrl.$path);
    }

    /** Bearer = static API key if set, else a cached JWT from /api/v2/token. */
    private function bearer(): string
    {
        if ($this->apiKey !== '') {
            return $this->apiKey;
        }

        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resp = Http::withBasicAuth($this->adminUser, $this->adminPass)
            ->acceptJson()->timeout(15)
            ->get($this->baseUrl.'/api/v2/token');

        if (! $resp->successful()) {
            throw new \RuntimeException('SFTPGo auth failed: HTTP '.$resp->status().' — '.$resp->body());
        }

        $token = (string) $resp->json('access_token');
        if ($token === '') {
            throw new \RuntimeException('SFTPGo /api/v2/token returned no access_token.');
        }

        // Cache until ~60s before the server-stated expiry (fallback 19 min).
        $ttl = 1140;
        $expISO = $resp->json('expires_at');
        if (is_string($expISO) && ($ts = strtotime($expISO)) !== false) {
            $ttl = max(60, $ts - time() - 60);
        }
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    private function assertOk(Response $resp, string $what): void
    {
        if (! $resp->successful()) {
            throw new \RuntimeException("SFTPGo {$what} failed: HTTP {$resp->status()} — {$resp->body()}");
        }
    }
}
