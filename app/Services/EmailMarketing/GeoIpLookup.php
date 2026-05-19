<?php

namespace App\Services\EmailMarketing;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an IPv4/IPv6 address to a country (code + name) via ipapi.co's
 * free tier (1000 req/day, no auth required). Results are cached in Laravel's
 * cache for 24 h so repeat hits on the same IP cost nothing.
 *
 * Returns ['country_code' => 'SA', 'country_name' => 'Saudi Arabia'] on success,
 * ['country_code' => null, 'country_name' => null] on failure / private IPs.
 *
 * Never throws — failures fall back to nulls so callers (SNS event handler)
 * never lose an event because GeoIP was down.
 */
class GeoIpLookup
{
    private const CACHE_TTL = 86400; // 24h

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

        return Cache::remember("geoip:{$ip}", self::CACHE_TTL, function () use ($ip, $empty) {
            try {
                $r = Http::timeout(5)
                    ->withHeaders(['Accept' => 'application/json'])
                    ->get("https://ipapi.co/{$ip}/json/");

                if (! $r->successful()) {
                    return $empty;
                }
                $payload = $r->json();
                if (! is_array($payload)) {
                    return $empty;
                }
                $code = strtoupper((string) ($payload['country_code'] ?? ''));

                return [
                    'country_code' => $code !== '' && strlen($code) === 2 ? $code : null,
                    'country_name' => (string) ($payload['country_name'] ?? '') ?: null,
                ];
            } catch (\Throwable $e) {
                Log::warning("GeoIpLookup failed for {$ip}: ".$e->getMessage());

                return $empty;
            }
        });
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
