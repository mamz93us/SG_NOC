<?php

namespace App\Services;

/**
 * Parsers for:
 *  - `show interfaces counters`          (traffic: InOctets, InUcastPkts, ...)
 *  - `show interfaces counters errors`   (Align-Err, FCS-Err, ...)
 *  - `show cdp neighbors detail`         (CDP topology)
 *
 * Each method returns plain arrays suitable for mass insert.
 */
class CiscoInterfaceParser
{
    /**
     * `show interfaces counters`
     *
     * Port            InOctets    InUcastPkts    InMcastPkts    InBcastPkts
     * Gi0/1    518683689147      654425818        1257648        9491972
     *
     * Port           OutOctets   OutUcastPkts   OutMcastPkts   OutBcastPkts
     * Gi0/1    983979901101      967850389       23873641       42357903
     *
     * Returns: [iface_name => ['in_octets'=>…, 'in_ucast_pkts'=>…, … , 'out_*' => …]]
     */
    public function parseInterfaceCounters(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = preg_split('/\n/', $raw);

        $out = [];
        $mode = null; // 'in' or 'out'

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;

            if (preg_match('/^Port\b.+InOctets\b/i', $trim)) { $mode = 'in';  continue; }
            if (preg_match('/^Port\b.+OutOctets\b/i', $trim)) { $mode = 'out'; continue; }
            if (!$mode) continue;

            $parts = preg_split('/\s+/', $trim);
            if (count($parts) < 5) continue;

            $name = $this->normalizeInterface($parts[0]);
            if ($name === null) continue;

            if ($mode === 'in') {
                $out[$name]['in_octets']      = (int) $parts[1];
                $out[$name]['in_ucast_pkts']  = (int) $parts[2];
                $out[$name]['in_mcast_pkts']  = (int) $parts[3];
                $out[$name]['in_bcast_pkts']  = (int) $parts[4];
            } else {
                $out[$name]['out_octets']     = (int) $parts[1];
                $out[$name]['out_ucast_pkts'] = (int) $parts[2];
                $out[$name]['out_mcast_pkts'] = (int) $parts[3];
                $out[$name]['out_bcast_pkts'] = (int) $parts[4];
            }
        }

        return $out;
    }

    /**
     * `show interfaces counters errors`
     *
     * Port    Align-Err    FCS-Err    Xmit-Err    Rcv-Err    UnderSize    OutDiscards
     * Gi0/1   0            0          0           0          0            87450
     *
     * Port    Single-Col   Multi-Col  Late-Col    Excess-Col Carri-Sen    Runts   Giants
     * Gi0/1   0            0          0           0          0            0       0
     */
    public function parseInterfaceErrors(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = preg_split('/\n/', $raw);

        $out = [];
        $mode = null; // 'err' (first table) or 'col' (second table)

        foreach ($lines as $line) {
            $trim = trim($line);
            if ($trim === '') continue;

            if (preg_match('/^Port\s+Align-Err/i', $trim))  { $mode = 'err'; continue; }
            if (preg_match('/^Port\s+Single-Col/i', $trim)) { $mode = 'col'; continue; }
            if (!$mode) continue;

            $parts = preg_split('/\s+/', $trim);

            $name = $this->normalizeInterface($parts[0] ?? '');
            if ($name === null) continue;

            if ($mode === 'err' && count($parts) >= 7) {
                $out[$name]['align_err']    = (int) $parts[1];
                $out[$name]['fcs_err']      = (int) $parts[2];
                $out[$name]['xmit_err']     = (int) $parts[3];
                $out[$name]['rcv_err']      = (int) $parts[4];
                $out[$name]['undersize']    = (int) $parts[5];
                $out[$name]['out_discards'] = (int) $parts[6];
            } elseif ($mode === 'col' && count($parts) >= 8) {
                $out[$name]['single_col'] = (int) $parts[1];
                $out[$name]['multi_col']  = (int) $parts[2];
                $out[$name]['late_col']   = (int) $parts[3];
                $out[$name]['excess_col'] = (int) $parts[4];
                $out[$name]['carri_sen']  = (int) $parts[5];
                $out[$name]['runts']      = (int) $parts[6];
                $out[$name]['giants']     = (int) $parts[7];
            }
        }

        return $out;
    }

    /**
     * `show cdp neighbors detail`
     *
     * Device ID: Core-SW-2
     * Entry address(es):
     *   IP address: 10.1.0.101
     * Platform: cisco WS-C3560X-48T, Capabilities: Router Switch IGMP
     * Interface: GigabitEthernet0/1, Port ID (outgoing port): GigabitEthernet0/48
     * Holdtime : 152 sec
     * Version :
     *   Cisco IOS Software, ...
     * --------------------------
     *
     * Returns: list of arrays, each representing one neighbor.
     */
    public function parseCdpNeighborsDetail(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        // Split on separator lines (dashes) which Cisco emits between each entry.
        $blocks = preg_split('/^-{5,}\s*$/m', $raw);

        $neighbors = [];
        foreach ($blocks as $block) {
            $block = trim($block);
            if ($block === '' || !preg_match('/Device ID\s*:/i', $block)) continue;

            $n = [
                'neighbor_device_id' => null,
                'neighbor_ip'        => null,
                'local_interface'    => null,
                'neighbor_port'      => null,
                'platform'           => null,
                'capabilities'       => null,
                'holdtime'           => null,
                'version'            => null,
            ];

            if (preg_match('/Device ID\s*:\s*(\S+)/i', $block, $m)) {
                $n['neighbor_device_id'] = $m[1];
            }
            if (preg_match('/IP address\s*:\s*([0-9a-fA-F.:]+)/i', $block, $m)) {
                $n['neighbor_ip'] = $m[1];
            }
            if (preg_match('/Platform\s*:\s*([^,\n]+)\s*,\s*Capabilities\s*:\s*([^\n]+)/i', $block, $m)) {
                $n['platform']     = trim($m[1]);
                $n['capabilities'] = trim($m[2]);
            }
            if (preg_match('/Interface\s*:\s*([^,\n]+)\s*,\s*Port ID.*?:\s*([^\n]+)/i', $block, $m)) {
                $n['local_interface'] = $this->normalizeInterface(trim($m[1])) ?? trim($m[1]);
                $n['neighbor_port']   = trim($m[2]);
            }
            if (preg_match('/Holdtime\s*:\s*(\d+)/i', $block, $m)) {
                $n['holdtime'] = (int) $m[1];
            }
            if (preg_match('/Version\s*:\s*\n\s*([^\n]+)/i', $block, $m)) {
                $n['version'] = trim($m[1]);
            }

            if ($n['neighbor_device_id'] && $n['local_interface']) {
                $neighbors[] = $n;
            }
        }

        return $neighbors;
    }

    /**
     * Normalize Cisco short/long interface names to the long form used elsewhere.
     * "Gi0/47"  -> "GigabitEthernet0/47"
     * "Fa0/1"   -> "FastEthernet0/1"
     * "Te1/0/1" -> "TenGigabitEthernet1/0/1"
     * Longer names pass through unchanged.
     */
    public function normalizeInterface(?string $s): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        if ($s === '' || !preg_match('/^([A-Za-z]+)([0-9\/]+)$/', $s, $m)) return null;

        $map = [
            'fa' => 'FastEthernet',
            'gi' => 'GigabitEthernet',
            'te' => 'TenGigabitEthernet',
            'tw' => 'TwoGigabitEthernet',
            'fo' => 'FortyGigE',
            'hu' => 'HundredGigE',
            'po' => 'Port-channel',
            'vl' => 'Vlan',
        ];
        $prefix = strtolower(substr($m[1], 0, 2));
        if (isset($map[$prefix]) && strlen($m[1]) <= 3) {
            return $map[$prefix] . $m[2];
        }
        return $m[1] . $m[2];
    }
}
