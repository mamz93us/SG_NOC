<?php

namespace App\Console\Commands;

use App\Models\VpnLog;
use App\Models\VpnTunnel;
use App\Services\PingService;
use Illuminate\Console\Command;

/**
 * Connectivity double-check for the VPN Hub: pings a host inside each
 * tunnel's remote subnet *through* the tunnel (default: first host of the
 * first remote subnet — the branch Sophos at 10.x.0.1) and stamps the result
 * on the tunnel row. IKE can show ESTABLISHED while traffic is actually
 * blackholed (xfrm policy gone, branch-side rule, dead Sophos) — this catches
 * that. Scheduled every 10 minutes; surfaces as the Connectivity column.
 */
class PingVpnTunnels extends Command
{
    protected $signature = 'vpn:ping-tunnels';

    protected $description = 'Ping each VPN tunnel\'s branch firewall through the tunnel and record reachability + latency.';

    public function handle(PingService $ping): int
    {
        foreach (VpnTunnel::all() as $tunnel) {
            $target = $tunnel->pingTarget();
            if (! $target) {
                $this->warn("{$tunnel->name}: no derivable ping target — skipped.");

                continue;
            }

            $result = $ping->ping($target, 2);
            $alive = (bool) ($result['success'] ?? false);
            $latency = $alive && isset($result['latency']) ? (int) round((float) $result['latency']) : null;

            $previous = $tunnel->ping_status;
            $tunnel->forceFill([
                'ping_status' => $alive ? 'up' : 'down',
                'ping_latency_ms' => $latency,
                'last_ping_at' => now(),
            ])->saveQuietly();

            // Log the flip so it shows in the tunnel history (skip the very
            // first probe — going from "never pinged" to a state isn't an event).
            if ($previous !== null && $previous !== $tunnel->ping_status) {
                VpnLog::create([
                    'vpn_id' => $tunnel->id,
                    'event_type' => 'ping_change',
                    'message' => "Connectivity ping to {$target} changed from {$previous} to {$tunnel->ping_status}.",
                ]);
            }

            $this->line(sprintf(
                '  %-10s %-15s %s',
                $tunnel->name,
                $target,
                $alive ? 'OK '.($latency !== null ? "{$latency} ms" : '') : 'NO REPLY'
            ));
        }

        return self::SUCCESS;
    }
}
