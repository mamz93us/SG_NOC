<?php

namespace App\Services\Access;

use App\Models\AccessVisit;
use App\Services\Ticketing\BranchResolver;
use App\Support\Marketing;
use App\Support\UserAgentParser;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Persists access events (logins + presence heartbeats) for the authenticated
 * SamirGroup apps. Reuses BranchResolver + UserAgentParser from the ticket
 * feature. Single entry point — record() — shared by the login hook and the
 * heartbeat middleware.
 */
class AccessVisitRecorder
{
    public function __construct(private BranchResolver $branches) {}

    /**
     * Which app a request belongs to, by host + path:
     *   em.<marketing host>      → em
     *   /portal or /portal/*     → portal
     *   everything else (admin)  → noc
     */
    public static function appFor(Request $request): string
    {
        if ($request->getHost() === Marketing::domain()) {
            return 'em';
        }

        if ($request->is('portal') || $request->is('portal/*')) {
            return 'portal';
        }

        return 'noc';
    }

    /**
     * @param array{
     *   user_id:?int, user_name:?string, user_email:?string, app:string,
     *   event:string, path:?string, ip:?string, user_agent:?string,
     *   session_id:?string, occurred_at?:?string
     * } $raw
     */
    public function record(array $raw): AccessVisit
    {
        $ua = $raw['user_agent'] ?? null;
        $parsed = UserAgentParser::parse($ua);

        return AccessVisit::create([
            'occurred_at' => isset($raw['occurred_at']) ? CarbonImmutable::parse($raw['occurred_at']) : CarbonImmutable::now(),
            'user_id' => $raw['user_id'] ?? null,
            'user_name' => $raw['user_name'] ?? null,
            'user_email' => $raw['user_email'] ?? null,
            'app' => $raw['app'] ?? 'noc',
            'event' => $raw['event'] ?? 'access',
            'path' => isset($raw['path']) ? mb_substr((string) $raw['path'], 0, 255) : null,
            'ip_address' => $raw['ip'] ?? null,
            'branch' => $this->branches->resolve($raw['ip'] ?? null),
            'user_agent' => $ua ? mb_substr($ua, 0, 1024) : null,
            'browser' => $parsed['browser'],
            'platform' => $parsed['platform'],
            'device_type' => $parsed['device_type'],
            'session_id' => $raw['session_id'] ?? null,
        ]);
    }
}
