<?php

namespace Database\Seeders;

use App\Models\BranchTunnel;
use Illuminate\Database\Seeder;

/**
 * Seeds the watchdog with a probe per subnet each branch tunnel is meant to
 * carry, beyond the gateway firewall it already pings.
 *
 * These targets were verified from the NOC on 2026-08-06 while diagnosing why
 * the JED UCM was unreachable despite the board showing JED up. The gateway
 * subnet (10.x.0.0/24) was reachable at every branch; the voice VLANs at JED
 * (10.1.8.0/24), RYD (10.2.88.0/24) and all of WH-JED (10.5.0.0/24) were not.
 *
 * Idempotent — re-running only fills in missing probes, and never overwrites a
 * probe an operator has since edited in the UI.
 */
class TunnelProbeSeeder extends Seeder
{
    /**
     * tunnel name => list of [label, target, check_type, port]
     *
     * UCM probes are TCP against the HTTPS API port rather than ICMP: that is
     * the port the app actually needs, and it fails independently of ping.
     */
    private const PROBES = [
        'CAI' => [
            ['UCM (voice VLAN)', '10.9.8.10', 'tcp', 8089],
            ['Voice VLAN gateway', '10.9.8.1', 'icmp', null],
        ],
        'JED' => [
            ['UCM (voice VLAN)', '10.1.8.10', 'tcp', 8089],
            ['Voice VLAN core switch', '10.1.8.5', 'icmp', null],
        ],
        'ABH' => [
            ['UCM', '10.4.0.9', 'tcp', 8089],
        ],
        'KBR' => [
            ['UCM', '10.3.0.10', 'tcp', 8089],
        ],
        'RYD' => [
            ['UCM (voice VLAN)', '10.2.88.10', 'tcp', 8089],
            ['Voice VLAN gateway', '10.2.88.1', 'icmp', null],
        ],
        'WH-RYD' => [
            ['UCM', '10.6.0.10', 'tcp', 8089],
        ],
        'WH-JED' => [
            ['UCM', '10.5.0.9', 'tcp', 8089],
        ],
    ];

    public function run(): void
    {
        $tunnels = BranchTunnel::all()->keyBy('name');

        foreach (self::PROBES as $tunnelName => $probes) {
            $tunnel = $tunnels->get($tunnelName);

            if (! $tunnel) {
                $this->command?->warn("Tunnel '{$tunnelName}' not found — skipped.");

                continue;
            }

            foreach ($probes as $i => [$label, $target, $type, $port]) {
                $existing = $tunnel->probes()
                    ->where('target', $target)
                    ->where('check_type', $type)
                    ->when($port !== null, fn ($q) => $q->where('port', $port))
                    ->first();

                if ($existing) {
                    continue;   // leave operator edits alone
                }

                $tunnel->probes()->create([
                    'label' => $label,
                    'target' => $target,
                    'check_type' => $type,
                    'port' => $port,
                    'sort_order' => $i,
                ]);

                $this->command?->info("  + {$tunnelName}: {$label} ({$target}".($port ? ":{$port}" : '').')');
            }
        }
    }
}
