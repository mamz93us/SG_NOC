<?php

namespace App\Console\Commands;

use App\Models\AccessPoint;
use App\Models\NocEvent;
use App\Services\PingService;
use Illuminate\Console\Command;

/**
 * Ping every monitored access point and record reachability + latency.
 * Sophos APX-series APs have no SNMP, so ICMP is the only health signal we
 * get directly. Branch AP subnets (10.x) are reachable over the IPsec tunnels.
 * Raises a NocEvent when an AP goes down and resolves it when it recovers.
 */
class PingAccessPoints extends Command
{
    protected $signature = 'access-points:ping {--id= : Ping a single access point by ID}';

    protected $description = 'Ping monitored access points and record up/down status + latency';

    public function handle(PingService $ping): int
    {
        $query = AccessPoint::monitored()->whereNotNull('ip_address');
        if ($this->option('id')) {
            $query->where('id', $this->option('id'));
        }
        $aps = $query->get();

        if ($aps->isEmpty()) {
            $this->warn('No monitored access points with an IP address.');

            return 0;
        }

        $up = $down = 0;

        foreach ($aps as $ap) {
            $result = $ping->ping($ap->ip_address, 2);
            $alive = (bool) ($result['success'] ?? false);
            $latency = $alive && isset($result['latency']) ? (int) round((float) $result['latency']) : null;

            $previous = $ap->status;
            $ap->forceFill([
                'status' => $alive ? 'up' : 'down',
                'ping_latency_ms' => $latency,
                'last_ping_at' => now(),
                'last_seen_at' => $alive ? now() : $ap->last_seen_at,
            ])->saveQuietly();

            if ($alive) {
                $up++;
                $this->resolveDownEvent($ap);
            } else {
                $down++;
                // Only alert on a real transition (or first-ever down result)
                if ($previous !== 'down') {
                    $this->raiseDownEvent($ap);
                }
            }

            $this->line(sprintf(
                '  %-26s %-15s %s',
                \Illuminate\Support\Str::limit($ap->name, 25),
                $ap->ip_address,
                $alive ? 'UP '.($latency !== null ? "{$latency} ms" : '') : 'DOWN'
            ));
        }

        $this->info("Access points pinged: {$aps->count()} ({$up} up, {$down} down).");

        return 0;
    }

    protected function raiseDownEvent(AccessPoint $ap): void
    {
        $where = $ap->branch?->name ?? $ap->site;

        NocEvent::firstOrCreate(
            ['source_type' => 'access_point_down', 'source_id' => $ap->id, 'status' => 'open'],
            [
                'module' => 'access_point',
                'severity' => 'critical',
                'title' => 'Access Point Down: '.$ap->name,
                'message' => 'AP '.$ap->name.' ('.$ap->ip_address.')'
                    .($where ? " at {$where}" : '').' is not responding to ping.',
                'first_seen' => now(),
                'last_seen' => now(),
            ]
        )->update(['last_seen' => now()]);
    }

    protected function resolveDownEvent(AccessPoint $ap): void
    {
        NocEvent::where('source_type', 'access_point_down')
            ->where('source_id', $ap->id)
            ->where('status', 'open')
            ->get()
            ->each(fn ($ev) => $ev->update(['status' => 'resolved', 'resolved_at' => now()]));
    }
}
