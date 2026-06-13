<?php

namespace App\Services\Sophos;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sophos Central public API client (cloud — NOT the per-firewall XML API,
 * which is SophosApiService). Credentials live in Settings, not .env.
 *
 * Auth flow: client-credentials token from id.sophos.com → /whoami to
 * discover tenant ID + regional data host → per-product endpoints
 * (wifi/v1 access points, firewall/v1 firewalls, common/v1 alerts).
 */
class SophosCentralService
{
    protected const AUTH_URL = 'https://id.sophos.com/api/v2/oauth2/token';

    protected const WHOAMI_URL = 'https://api.central.sophos.com/whoami/v1';

    protected const TOKEN_CACHE_KEY = 'sophos_central_access_token';

    protected Setting $settings;

    public function __construct(?Setting $settings = null)
    {
        $this->settings = $settings ?? Setting::get();
    }

    public function isConfigured(): bool
    {
        return ! empty($this->settings->sophos_central_client_id)
            && ! empty($this->settings->sophos_central_client_secret);
    }

    // ─── Auth ─────────────────────────────────────────────────────

    /**
     * Get a bearer token (cached until shortly before expiry).
     */
    public function getToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $response = Http::asForm()->timeout(20)->post(self::AUTH_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->settings->sophos_central_client_id,
            'client_secret' => $this->settings->sophos_central_client_secret,
            'scope' => 'token',
        ]);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Sophos Central auth failed (HTTP '.$response->status().'): '
                .substr($response->body(), 0, 300)
            );
        }

        $token = $response->json('access_token');
        if (! $token) {
            throw new \RuntimeException('Sophos Central auth succeeded but no access_token in response.');
        }

        $ttl = max(60, (int) $response->json('expires_in', 3600) - 120);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    /**
     * Resolve tenant ID + regional data host via /whoami.
     * Cached in the settings row so subsequent syncs skip the call.
     */
    public function whoami(bool $force = false): array
    {
        if (! $force
            && $this->settings->sophos_central_tenant_id
            && $this->settings->sophos_central_data_region) {
            return [
                'tenant_id' => $this->settings->sophos_central_tenant_id,
                'data_region' => $this->settings->sophos_central_data_region,
            ];
        }

        $response = Http::withToken($this->getToken())->timeout(20)->get(self::WHOAMI_URL);

        if ($response->failed()) {
            throw new \RuntimeException(
                'Sophos Central whoami failed (HTTP '.$response->status().'): '
                .substr($response->body(), 0, 300)
            );
        }

        $tenantId = $response->json('id');
        $dataRegion = $response->json('apiHosts.dataRegion');

        if (! $tenantId || ! $dataRegion) {
            throw new \RuntimeException(
                'Sophos Central whoami returned no tenant/data region. '
                .'idType='.$response->json('idType', '?')
                .' — credentials must be tenant-scoped (not partner/organization).'
            );
        }

        $this->settings->sophos_central_tenant_id = $tenantId;
        $this->settings->sophos_central_data_region = $dataRegion;
        $this->settings->save();

        return ['tenant_id' => $tenantId, 'data_region' => $dataRegion];
    }

    // ─── Generic GET with pagination ──────────────────────────────

    /**
     * GET a Central API path (e.g. '/wifi/v1/access-points') and follow
     * pagination, returning all items. Handles both key-based
     * (pages.nextKey → pageFromKey) and number-based (pages.current/total)
     * pagination used across Central product APIs.
     */
    public function getAllItems(string $path, array $query = []): array
    {
        $who = $this->whoami();
        $base = rtrim($who['data_region'], '/');
        $items = [];
        $guard = 0;

        $query['pageSize'] = $query['pageSize'] ?? 100;

        while ($guard++ < 100) {
            $response = Http::withToken($this->getToken())
                ->withHeaders(['X-Tenant-ID' => $who['tenant_id']])
                ->timeout(30)
                ->get($base.$path, $query);

            if ($response->failed()) {
                throw new \RuntimeException(
                    "Sophos Central GET {$path} failed (HTTP {$response->status()}): "
                    .substr($response->body(), 0, 300)
                );
            }

            $json = $response->json();
            $batch = $json['items'] ?? [];
            $items = array_merge($items, $batch);

            $pages = $json['pages'] ?? [];

            if (! empty($pages['nextKey'])) {
                $query['pageFromKey'] = $pages['nextKey'];

                continue;
            }

            if (isset($pages['current'], $pages['total']) && $pages['current'] < $pages['total']) {
                $query['page'] = $pages['current'] + 1;

                continue;
            }

            break;
        }

        return $items;
    }

    // ─── Product endpoints ────────────────────────────────────────

    /**
     * All access points known to Sophos Central (Wi-Fi Management API).
     */
    public function accessPoints(): array
    {
        return $this->getAllItems('/wifi/v1/access-points');
    }

    /**
     * All firewalls registered in Sophos Central (Firewall Management API).
     */
    public function firewalls(): array
    {
        return $this->getAllItems('/firewall/v1/firewalls');
    }

    /**
     * Open Central alerts (Common API).
     */
    public function alerts(): array
    {
        return $this->getAllItems('/common/v1/alerts');
    }

    // ─── Test connection ──────────────────────────────────────────

    /**
     * Token + whoami round-trip. Returns ['ok' => bool, 'detail' => string]
     * (shape expected by the generic settings-page test button).
     */
    public function testConnection(): array
    {
        try {
            // Bust caches so the test always exercises the saved credentials
            Cache::forget(self::TOKEN_CACHE_KEY);
            $who = $this->whoami(force: true);

            return [
                'ok' => true,
                'detail' => "Connected — tenant {$who['tenant_id']}, data region {$who['data_region']}",
            ];
        } catch (\Throwable $e) {
            Log::warning('Sophos Central test connection failed: '.$e->getMessage());

            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }
}
