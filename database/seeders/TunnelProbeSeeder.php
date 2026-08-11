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
    /** A media port inside every UCM's default RTP range (10000–20000). */
    private const RTP_SAMPLE_PORT = 10000;

    /**
     * tunnel name => list of [label, target, check_type, port]
     *
     * UCM probes are TCP against the HTTPS API port rather than ICMP: that is
     * the port the app actually needs, and it fails independently of ping.
     *
     * Each UCM also gets an RTP media probe, because a tunnel policy can permit
     * ICMP and TCP/8089 while dropping the UDP voice traffic — the board goes
     * green and no call ever completes. Verified against all seven UCMs on
     * 2026-08-11: every one answers a closed media port with ICMP unreachable,
     * so the probe reports "port closed — path OK" and any future silence is a
     * real change.
     *
     * The SIP probe is seeded PAUSED. The mechanism is sound, but on that same
     * sweep every UCM stayed silent on 5060 while answering ICMP unreachable on
     * every other port — meaning 5060 is bound and the UCM is declining to
     * answer OPTIONS from 172.16.8.11, not that the tunnel is broken. Left
     * active it would mark all seven tunnels degraded forever. Permit SIP
     * requests from the NOC on the UCM first, then enable it per branch.
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

            foreach ($this->withVoiceProbes($probes) as $i => [$label, $target, $type, $port, $active]) {
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
                    'is_active' => $active,
                    'sort_order' => $i,
                ]);

                $this->command?->info("  + {$tunnelName}: {$label} ({$target}".($port ? ":{$port}" : '').')'
                    .($active ? '' : ' [paused]'));
            }
        }
    }

    /**
     * Expand the table above: normalise each row to include an is_active flag,
     * and give every UCM a SIP and an RTP probe alongside its API check.
     *
     * @param  array<int, array{0:string, 1:string, 2:string, 3:?int}>  $probes
     * @return array<int, array{0:string, 1:string, 2:string, 3:?int, 4:bool}>
     */
    private function withVoiceProbes(array $probes): array
    {
        $out = [];

        foreach ($probes as [$label, $target, $type, $port]) {
            $out[] = [$label, $target, $type, $port, true];

            // The UCM row is the one identified by its 8089 API port.
            if ($type !== 'tcp' || $port !== 8089) {
                continue;
            }

            $out[] = ['UCM RTP media', $target, 'udp', self::RTP_SAMPLE_PORT, true];
            $out[] = ['UCM SIP signalling', $target, 'sip', 5060, false];
        }

        return $out;
    }
}
