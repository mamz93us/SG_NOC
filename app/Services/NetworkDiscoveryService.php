<?php

namespace App\Services;

use App\Models\DiscoveryScan;
use App\Models\DiscoveryResult;
use Illuminate\Support\Facades\Log;

class NetworkDiscoveryService
{
    // ─── IP Range Parsing ────────────────────────────────────────

    /**
     * Parse a range string into an array of IP strings.
     * Supports:
     *   192.168.1.0/24      CIDR
     *   192.168.1.1-254     last-octet range
     *   192.168.1.1-192.168.1.50  full range
     *   192.168.1.5         single IP
     */
    public function parseRange(string $input): array
    {
        $input = trim($input);
        $ips   = [];

        // CIDR notation
        if (str_contains($input, '/')) {
            $ips = $this->parseCidr($input);

        // Dash range — last octet or full IP
        } elseif (str_contains($input, '-')) {
            [$start, $end] = explode('-', $input, 2);
            $start = trim($start);
            $end   = trim($end);

            if (filter_var($end, FILTER_VALIDATE_IP)) {
                // Full IP range: 192.168.1.1-192.168.1.50
                $startLong = ip2long($start);
                $endLong   = ip2long($end);
                if ($startLong !== false && $endLong !== false && $endLong >= $startLong) {
                    for ($i = $startLong; $i <= $endLong && count($ips) < 1024; $i++) {
                        $ips[] = long2ip($i);
                    }
                }
            } else {
                // Last-octet range: 192.168.1.1-254
                $parts    = explode('.', $start);
                $base     = implode('.', array_slice($parts, 0, 3));
                $startOct = (int) end($parts);
                $endOct   = (int) $end;
                for ($i = $startOct; $i <= $endOct && count($ips) < 256; $i++) {
                    $ips[] = "{$base}.{$i}";
                }
            }

        // Single IP
        } elseif (filter_var($input, FILTER_VALIDATE_IP)) {
            $ips[] = $input;
        }

        return array_filter($ips, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false);
    }

    private function parseCidr(string $cidr): array
    {
        [$base, $prefix] = explode('/', $cidr);
        $prefix = (int) $prefix;

        if ($prefix < 16 || $prefix > 32) {
            return []; // Safety limit — refuse /0 to /15
        }

        $baseInt  = ip2long($base);
        $hostBits = 32 - $prefix;
        $count    = min(1024, (int) pow(2, $hostBits));
        $networkInt = $baseInt & (~((1 << $hostBits) - 1));

        $ips = [];
        // Skip network (.0) and broadcast addresses
        for ($i = 1; $i < $count - 1 && count($ips) < 1024; $i++) {
            $ips[] = long2ip($networkInt + $i);
        }
        return $ips;
    }

    // ─── Host Discovery ──────────────────────────────────────────

    public function ping(string $ip, int $timeout = 1): bool
    {
        $cmd = PHP_OS_FAMILY === 'Windows'
            ? "ping -n 1 -w " . ($timeout * 1000) . " {$ip}"
            : "ping -c 1 -W {$timeout} {$ip}";
        $output = @shell_exec($cmd . ' 2>/dev/null');
        return $output && (
            str_contains($output, 'TTL=') ||    // Windows
            str_contains($output, 'ttl=') ||    // Linux
            str_contains($output, '1 received') ||
            str_contains($output, '1 packets transmitted, 1 received')
        );
    }

    public function snmpGet(string $ip, string $community, string $oid, int $timeout = 2): ?string
    {
        try {
            if (extension_loaded('snmp')) {
                $session = new \SNMP(\SNMP::VERSION_2c, "{$ip}:161", $community, $timeout * 1000000, 1);
                $session->valueretrieval = \SNMP_VALUE_PLAIN;
                $result = @$session->get($oid);
                $session->close();
                return ($result !== false && $result !== null) ? (string) $result : null;
            }
            $cmd    = "snmpget -v2c -c " . escapeshellarg($community)
                    . " -t {$timeout} -r 0 -On {$ip} " . escapeshellarg($oid) . " 2>/dev/null";
            $output = @shell_exec($cmd);
            if ($output && preg_match('/=\s*(.+)$/', trim($output), $m)) {
                return trim(preg_replace('/^[A-Z][a-zA-Z0-9-]+:\s*/', '', $m[1]));
            }
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ─── Device Classification ───────────────────────────────────

    /**
     * Probe a single IP and return a DiscoveryResult data array.
     */
    public function probeHost(string $ip, string $community, int $timeout): array
    {
        $result = [
            'ip_address'      => $ip,
            'hostname'        => null,
            'mac_address'     => null,
            'vendor'          => null,
            'model'           => null,
            'sys_name'        => null,
            'sys_descr'       => null,
            'device_type'     => 'unknown',
            'is_reachable'    => false,
            'snmp_accessible' => false,
            'raw_data'        => [],
        ];

        // 1. Ping
        $result['is_reachable'] = $this->ping($ip, $timeout);

        if (! $result['is_reachable']) {
            return $result;
        }

        // 2. Reverse DNS
        try {
            $host = @gethostbyaddr($ip);
            if ($host && $host !== $ip) {
                $result['hostname'] = $host;
            }
        } catch (\Throwable) {}

        // 3. SNMP queries
        $sysDescr  = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.1.0', $timeout);
        $sysName   = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.5.0', $timeout);
        $sysObjId  = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.2.0', $timeout);

        if ($sysDescr !== null) {
            $result['snmp_accessible'] = true;
            $result['sys_descr'] = trim($sysDescr);
            $result['sys_name']  = $sysName ? trim($sysName) : null;
            $result['raw_data']['sysObjectID'] = $sysObjId;

            // Printer MIB — prtGeneralSerialNumber
            $printerMib = $this->snmpGet($ip, $community, '1.3.6.1.2.1.43.5.1.1.17.1', $timeout);
            $result['raw_data']['printer_mib'] = $printerMib;

            // Enrich model/vendor from sysDescr
            $result['model']  = $this->extractModel($sysDescr);
            $result['vendor'] = $this->extractVendor($sysDescr);

            // Classify device type
            $result['device_type'] = $this->classifyDevice($sysDescr, $sysObjId, $printerMib);
        }

        return $result;
    }

    private function extractVendor(string $sysDescr): ?string
    {
        $vendors = ['Ricoh', 'HP', 'Canon', 'Epson', 'Brother', 'Xerox', 'Kyocera', 'Lexmark',
                    'Cisco', 'Meraki', 'Juniper', 'Aruba', 'MikroTik', 'Ubiquiti', 'TP-Link',
                    'Netgear', 'D-Link', 'Grandstream', 'Yealink', 'Polycom'];
        foreach ($vendors as $vendor) {
            if (stripos($sysDescr, $vendor) !== false) {
                return $vendor;
            }
        }
        return null;
    }

    private function extractModel(string $sysDescr): ?string
    {
        if (preg_match('/\b(MP\s?[A-Z0-9]+|Color\s?LaserJet\s?[A-Z0-9]+|LaserJet\s?[A-Z0-9]+|MFC-[A-Z0-9]+|WorkCentre\s?[A-Z0-9]+|[A-Z]{2,}\s?[A-Z]?\d{3,}[A-Z]?)\b/i', $sysDescr, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function classifyDevice(string $sysDescr, ?string $sysObjId, ?string $printerMib): string
    {
        $desc = strtolower($sysDescr);

        // Printer indicators
        $printerKeywords = ['ricoh', 'printer', 'laserjet', 'mfc', 'workcentre', 'imagerunner',
                            'phaser', 'colorqube', 'docuprint', 'kyocera', 'print', 'copier'];
        foreach ($printerKeywords as $kw) {
            if (str_contains($desc, $kw)) return 'printer';
        }
        if ($printerMib !== null) return 'printer'; // Printer MIB responded

        // Switch / network device indicators
        $switchKeywords = ['switch', 'catalyst', 'nexus', 'juniper', 'aruba', 'procurve',
                           'ios ', 'junos', 'comware', 'netgear', 'mikrotik', 'ubiquiti',
                           'meraki', 'ex series', 'srx'];
        foreach ($switchKeywords as $kw) {
            if (str_contains($desc, $kw)) return 'switch';
        }

        // Generic device
        if ($sysDescr) return 'device';

        return 'unknown';
    }

    // ─── Full Scan ───────────────────────────────────────────────

    /**
     * Run a complete discovery scan, update DiscoveryScan as we go.
     */
    public function runScan(DiscoveryScan $scan): void
    {
        $scan->update(['status' => 'running', 'started_at' => now()]);

        try {
            $ips = $this->parseRange($scan->range_input);

            if (empty($ips)) {
                $scan->update([
                    'status'        => 'failed',
                    'finished_at'   => now(),
                    'error_message' => 'No valid IPs parsed from range: ' . $scan->range_input,
                ]);
                return;
            }

            $scan->update(['total_hosts' => count($ips)]);

            $reachable = 0;

            foreach ($ips as $ip) {
                try {
                    $data = $this->probeHost($ip, $scan->snmp_community, $scan->snmp_timeout);

                    // Check if already imported
                    $data['already_imported'] =
                        \App\Models\Printer::where('ip_address', $ip)->exists() ||
                        \App\Models\NetworkSwitch::where('lan_ip', $ip)->exists() ||
                        \App\Models\Device::where('ip_address', $ip)->exists();

                    DiscoveryResult::create(array_merge(['discovery_scan_id' => $scan->id], $data));

                    if ($data['is_reachable']) {
                        $reachable++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Discovery probe failed for {$ip}: " . $e->getMessage());
                }
            }

            $scan->update([
                'status'          => 'completed',
                'finished_at'     => now(),
                'reachable_count' => $reachable,
            ]);

        } catch (\Throwable $e) {
            Log::error("Discovery scan #{$scan->id} failed: " . $e->getMessage());
            $scan->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
