<?php

namespace App\Services\EmailMarketing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an IPv4/IPv6 address to a country (code + name).
 *
 * Tries providers in order:
 *   1. ipapi.co        (free tier ~1000/day, no auth)
 *   2. ipinfo.io       (free tier ~50k/month, no auth required for light use)
 *   3. ip-api.com      (free tier, HTTP only — used as last resort)
 *
 * Success results are cached 24 h. Failures are cached only 5 minutes so a
 * transient provider error / rate-limit doesn't persist for a full day.
 * Never throws — failures fall back to nulls so callers (SNS event handler)
 * never lose an event because GeoIP was down.
 */
class GeoIpLookup
{
    private const CACHE_TTL_OK   = 86400; // 24 h on success
    private const CACHE_TTL_FAIL = 300;   // 5 min on failure (so we retry soon)
    private const UA             = 'SG-NOC-EmailMarketing/1.0 (+https://noc.samirgroup.net)';

    public function lookup(?string $ip): array
    {
        $empty = ['country_code' => null, 'country_name' => null];

        $ip = trim((string) $ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $empty;
        }

        // Skip private / reserved ranges — they have no public location
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return ['country_code' => null, 'country_name' => 'Private/Local'];
        }

        $cacheKey = "geoip:{$ip}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && ! empty($cached['country_code'])) {
            return $cached;
        }

        // Try each provider in order until one returns a country.
        // ipinfo.io is first because ipapi.co's free tier rate-limits the
        // VPS quickly (HTTP 429 for hours); ipinfo.io has a 50k/mo allowance
        // without auth which comfortably covers normal campaign volume.
        foreach ([
            fn ($ip) => $this->ipinfoIo($ip),
            fn ($ip) => $this->ipapiCo($ip),
            fn ($ip) => $this->ipApiCom($ip),
        ] as $provider) {
            $result = $provider($ip);
            if (! empty($result['country_code'])) {
                Cache::put($cacheKey, $result, self::CACHE_TTL_OK);

                return $result;
            }
        }

        // All providers failed — cache the failure briefly so we retry soon
        Cache::put($cacheKey, $empty, self::CACHE_TTL_FAIL);

        return $empty;
    }

    private function ipapiCo(string $ip): array
    {
        try {
            $r = Http::timeout(5)
                ->withUserAgent(self::UA)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("https://ipapi.co/{$ip}/json/");

            $payload = $r->json();
            if (! $r->successful() || ! is_array($payload)) {
                Log::info("GeoIpLookup ipapi.co failed for {$ip}: HTTP {$r->status()}");

                return [];
            }
            if (! empty($payload['error'])) {
                Log::info("GeoIpLookup ipapi.co error for {$ip}: ".($payload['reason'] ?? 'unknown'));

                return [];
            }

            $code = strtoupper((string) ($payload['country_code'] ?? $payload['country'] ?? ''));

            return [
                'country_code' => strlen($code) === 2 ? $code : null,
                'country_name' => (string) ($payload['country_name'] ?? '') ?: null,
            ];
        } catch (\Throwable $e) {
            Log::warning("GeoIpLookup ipapi.co threw for {$ip}: ".$e->getMessage());

            return [];
        }
    }

    private function ipinfoIo(string $ip): array
    {
        try {
            $r = Http::timeout(5)
                ->withUserAgent(self::UA)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("https://ipinfo.io/{$ip}/json");

            $payload = $r->json();
            if (! $r->successful() || ! is_array($payload) || ! empty($payload['error'])) {
                Log::info("GeoIpLookup ipinfo.io failed for {$ip}: HTTP {$r->status()}");

                return [];
            }
            $code = strtoupper((string) ($payload['country'] ?? ''));

            return [
                'country_code' => strlen($code) === 2 ? $code : null,
                'country_name' => $code ? \Locale::getDisplayRegion('-'.$code, 'en') : null,
            ];
        } catch (\Throwable $e) {
            Log::warning("GeoIpLookup ipinfo.io threw for {$ip}: ".$e->getMessage());

            return [];
        }
    }

    private function ipApiCom(string $ip): array
    {
        try {
            // ip-api.com free tier is HTTP only
            $r = Http::timeout(5)
                ->withUserAgent(self::UA)
                ->withHeaders(['Accept' => 'application/json'])
                ->get("http://ip-api.com/json/{$ip}?fields=status,country,countryCode");

            $payload = $r->json();
            if (! $r->successful() || ! is_array($payload) || ($payload['status'] ?? '') !== 'success') {
                Log::info("GeoIpLookup ip-api.com failed for {$ip}: HTTP {$r->status()}");

                return [];
            }
            $code = strtoupper((string) ($payload['countryCode'] ?? ''));

            return [
                'country_code' => strlen($code) === 2 ? $code : null,
                'country_name' => (string) ($payload['country'] ?? '') ?: null,
            ];
        } catch (\Throwable $e) {
            Log::warning("GeoIpLookup ip-api.com threw for {$ip}: ".$e->getMessage());

            return [];
        }
    }

    /**
     * Country-code → flag emoji using Unicode regional indicator symbols.
     * 'SA' → 🇸🇦
     */
    public static function flagEmoji(?string $code): string
    {
        $code = strtoupper((string) $code);
        if (strlen($code) !== 2 || ! ctype_alpha($code)) {
            return '';
        }

        return mb_chr(0x1F1E6 + ord($code[0]) - ord('A'), 'UTF-8')
             . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'), 'UTF-8');
    }
}
