<?php

namespace App\Console\Commands;

use App\Models\BranchTunnel;
use App\Services\PingService;
use Illuminate\Console\Command;

/**
 * Pings every active branch firewall on the Branch Tunnel Health page and
 * stamps reachability + latency on the row. Pure ICMP, so it reflects the
 * truth whether the tunnel is carried by the Azure VPN gateway or anything
 * else. Scheduled every minute; also invoked by the page's "Ping now" button.
 */
class PingBranchTunnels extends Command
{
    protected $signature = 'tunnel-health:ping';

    protected $description = 'Ping each branch firewall and record reachability + latency.';

    public function handle(PingService $ping): int
    {
        foreach (BranchTunnel::where('is_active', true)->get() as $tunnel) {
            $result = $ping->ping($tunnel->firewall_ip, 2);
            $alive = (bool) ($result['success'] ?? false);
            $latency = $alive && isset($result['latency']) ? (int) round((float) $result['latency']) : null;

            $tunnel->forceFill([
                'ping_status' => $alive ? 'up' : 'down',
                'ping_latency_ms' => $latency,
                'last_ping_at' => now(),
            ])->saveQuietly();

            $this->line(sprintf(
                '  %-12s %-15s %s',
                $tunnel->name,
                $tunnel->firewall_ip,
                $alive ? 'OK '.($latency !== null ? "{$latency} ms" : '') : 'NO REPLY'
            ));
        }

        return self::SUCCESS;
    }
}
