<?php

namespace App\Services\Ticketing;

use App\Models\TicketVisit;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Turns the raw facts of a request into a persisted ticket_visits row.
 *
 * Single entry point — record() — used both by the inline path in the
 * controller and by LogTicketVisitJob, so the two can never drift. It owns:
 * bot exclusion, IP anonymisation, branch resolution, UA parsing, optional
 * GeoIP, and the per-session "unique today" flag.
 */
class TicketVisitRecorder
{
    public function __construct(private BranchResolver $branches) {}

    /**
     * @param  array{ip:?string,user_agent:?string,referrer:?string,session_id:?string,visited_at?:?string}  $raw
     */
    public function record(array $raw): ?TicketVisit
    {
        $config = config('ticket_tracking');
        $ua = $raw['user_agent'] ?? null;
        $visitedAt = isset($raw['visited_at'])
            ? CarbonImmutable::parse($raw['visited_at'])
            : CarbonImmutable::now();

        // Bots / uptime monitors are forwarded but never counted.
        if (($config['ignore_bots'] ?? true)
            && UserAgentParser::isBot($ua, (array) ($config['bot_user_agents'] ?? []))) {
            return null;
        }

        $ip = $this->anonymize($raw['ip'] ?? null, (bool) ($config['anonymize_ip'] ?? false));
        $parsed = UserAgentParser::parse($ua);
        $sessionId = $raw['session_id'] ?? null;
        $geo = $this->geoip($raw['ip'] ?? null, $config);

        return TicketVisit::create([
            'visited_at' => $visitedAt,
            'ip_address' => $ip,
            'branch' => $this->branches->resolve($raw['ip'] ?? null),
            'user_agent' => $ua ? mb_substr($ua, 0, 1024) : null,
            'browser' => $parsed['browser'],
            'platform' => $parsed['platform'],
            'device_type' => $parsed['device_type'],
            'referrer' => isset($raw['referrer']) ? mb_substr((string) $raw['referrer'], 0, 1024) : null,
            'session_id' => $sessionId,
            'is_unique_today' => $this->isUniqueToday($sessionId, $raw['ip'] ?? null, $visitedAt),
            'country' => $geo['country'] ?? null,
            'city' => $geo['city'] ?? null,
        ]);
    }

    /** First visit today for this session (or IP, when no session cookie). */
    private function isUniqueToday(?string $sessionId, ?string $ip, CarbonImmutable $at): bool
    {
        $query = TicketVisit::whereBetween('visited_at', [$at->startOfDay(), $at->endOfDay()]);

        if ($sessionId) {
            $query->where('session_id', $sessionId);
        } elseif ($ip) {
            $query->where('ip_address', $ip);
        } else {
            return true; // nothing to dedupe on
        }

        return ! $query->exists();
    }

    /** Mask last octet (IPv4) / last 80 bits (IPv6) when anonymisation is on. */
    private function anonymize(?string $ip, bool $on): ?string
    {
        if (! $on || ! $ip) {
            return $ip;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Keep the first 48 bits (3 hextets), zero the rest.
            $parts = explode(':', $ip);
            $head = array_slice($parts, 0, 3);

            return implode(':', $head).'::';
        }

        return $ip;
    }

    /** Pluggable GeoIP; returns nulls cleanly when disabled or misconfigured. */
    private function geoip(?string $ip, array $config): array
    {
        $geo = $config['geoip'] ?? [];

        if (! ($geo['enabled'] ?? false) || ! $ip || empty($geo['resolver'])) {
            return ['country' => null, 'city' => null];
        }

        try {
            $resolver = app($geo['resolver']);
            $result = $resolver->resolve($ip);

            return [
                'country' => $result['country'] ?? null,
                'city' => $result['city'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('[TicketVisitRecorder] GeoIP resolve failed: '.$e->getMessage());

            return ['country' => null, 'city' => null];
        }
    }
}
