<?php

namespace App\Services;

use App\Models\DiscoveryResult;
use App\Models\DiscoveryScan;
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
        $ips = [];

        // CIDR notation
        if (str_contains($input, '/')) {
            $ips = $this->parseCidr($input);

            // Dash range — last octet or full IP
        } elseif (str_contains($input, '-')) {
            [$start, $end] = explode('-', $input, 2);
            $start = trim($start);
            $end = trim($end);

            if (filter_var($end, FILTER_VALIDATE_IP)) {
                // Full IP range: 192.168.1.1-192.168.1.50
                $startLong = ip2long($start);
                $endLong = ip2long($end);
                if ($startLong !== false && $endLong !== false && $endLong >= $startLong) {
                    for ($i = $startLong; $i <= $endLong && count($ips) < 1024; $i++) {
                        $ips[] = long2ip($i);
                    }
                }
            } else {
                // Last-octet range: 192.168.1.1-254
                $parts = explode('.', $start);
                $base = implode('.', array_slice($parts, 0, 3));
                $startOct = (int) end($parts);
                $endOct = (int) $end;
                for ($i = $startOct; $i <= $endOct && count($ips) < 256; $i++) {
                    $ips[] = "{$base}.{$i}";
                }
            }

            // Single IP
        } elseif (filter_var($input, FILTER_VALIDATE_IP)) {
            $ips[] = $input;
        }

        return array_filter($ips, fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP) !== false);
    }

    private function parseCidr(string $cidr): array
    {
        [$base, $prefix] = explode('/', $cidr);
        $prefix = (int) $prefix;

        if ($prefix < 16 || $prefix > 32) {
            return []; // Safety limit — refuse /0 to /15
        }

        $baseInt = ip2long($base);
        $hostBits = 32 - $prefix;
        $count = min(1024, (int) pow(2, $hostBits));
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
            ? 'ping -n 1 -w '.($timeout * 1000)." {$ip}"
            : "ping -c 1 -W {$timeout} {$ip}";
        $output = @shell_exec($cmd.' 2>/dev/null');

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
            $cmd = 'snmpget -v2c -c '.escapeshellarg($community)
                    ." -t {$timeout} -r 0 -On {$ip} ".escapeshellarg($oid).' 2>/dev/null';
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
            'ip_address' => $ip,
            'hostname' => null,
            'mac_address' => null,
            'vendor' => null,
            'model' => null,
            'sys_name' => null,
            'sys_descr' => null,
            'device_type' => 'unknown',
            'is_reachable' => false,
            'snmp_accessible' => false,
            'raw_data' => [],
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
        } catch (\Throwable) {
        }

        // 3. SNMP queries
        $sysDescr = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.1.0', $timeout);
        $sysName = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.5.0', $timeout);
        $sysObjId = $this->snmpGet($ip, $community, '1.3.6.1.2.1.1.2.0', $timeout);

        if ($sysDescr !== null) {
            $result['snmp_accessible'] = true;
            $result['sys_descr'] = trim($sysDescr);
            $result['sys_name'] = $sysName ? trim($sysName) : null;
            $result['raw_data']['sysObjectID'] = $sysObjId;

            // Printer MIB — prtGeneralSerialNumber
            $printerMib = $this->snmpGet($ip, $community, '1.3.6.1.2.1.43.5.1.1.17.1', $timeout);
            $result['raw_data']['printer_mib'] = $printerMib;

            // Enrich model/vendor from sysDescr + sysName (AP names live in sysName)
            $fingerprint = trim($sysDescr.' '.($sysName ?? ''));
            $result['model'] = $this->extractModel($sysDescr);
            $result['vendor'] = $this->extractVendor($fingerprint);

            // Classify device type
            $result['device_type'] = $this->classifyDevice($fingerprint, $sysObjId, $printerMib);
        }

        // 4. HTTP(S) fingerprint — catches Sophos APs (and other web-managed
        //    devices) that don't have SNMP enabled. Only for reachable hosts
        //    not already positively identified, to keep the scan fast.
        if ($result['is_reachable'] && in_array($result['device_type'], ['unknown', 'device'], true)) {
            $http = $this->httpFingerprint($ip, $timeout);
            if ($http !== null) {
                $result['vendor'] = $result['vendor'] ?: $http['vendor'];
                $result['model'] = $result['model'] ?: $http['model'];
                $result['device_type'] = $http['device_type'];
                $result['raw_data']['http_fingerprint'] = $http['signal'];
            }
        }

        return $result;
    }

    /**
     * Probe a host's local web UI / TLS cert for a Sophos access-point
     * signature. Returns null when nothing recognizable is found.
     */
    protected function httpFingerprint(string $ip, int $timeout): ?array
    {
        foreach ([443, 8443] as $port) {
            $signal = $this->fetchTlsAndBody($ip, $port, $timeout);
            if ($signal === null) {
                continue;
            }

            $hay = strtolower($signal);

            // Sophos AP local UI / device cert markers
            if (str_contains($hay, 'sophos')
                && (str_contains($hay, 'ap6') || str_contains($hay, 'apx')
                    || str_contains($hay, 'access point') || str_contains($hay, 'accesspoint')
                    || str_contains($hay, 'wifi') || str_contains($hay, 'wireless'))) {
                return [
                    'vendor' => 'Sophos',
                    'model' => $this->extractModel($signal),
                    'device_type' => 'access_point',
                    'signal' => substr($signal, 0, 300),
                ];
            }

            // Plain Sophos device (firewall mgmt page, etc.)
            if (str_contains($hay, 'sophos')) {
                return [
                    'vendor' => 'Sophos',
                    'model' => null,
                    'device_type' => 'device',
                    'signal' => substr($signal, 0, 300),
                ];
            }
        }

        return null;
    }

    /**
     * Open a TLS socket, capture the peer certificate subject/issuer and a
     * small slice of the HTTP response. Best-effort; returns null on failure.
     */
    protected function fetchTlsAndBody(string $ip, int $port, int $timeout): ?string
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$ip}:{$port}",
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if (! $client) {
            return null;
        }

        $parts = [];

        // Certificate subject/issuer CN — present even when the UI needs auth
        $params = stream_context_get_params($client);
        if (! empty($params['options']['ssl']['peer_certificate'])) {
            $cert = @openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            if (is_array($cert)) {
                $parts[] = $cert['name'] ?? '';
                $parts[] = $cert['issuer']['O'] ?? '';
                $parts[] = $cert['issuer']['CN'] ?? '';
                $parts[] = $cert['subject']['O'] ?? '';
                $parts[] = $cert['subject']['CN'] ?? '';
            }
        }

        // A quick HTTP GET for Server header / page <title>
        @stream_set_timeout($client, $timeout);
        @fwrite($client, "GET / HTTP/1.0\r\nHost: {$ip}\r\nConnection: close\r\n\r\n");
        $body = '';
        $deadline = 4096;
        while (! feof($client) && strlen($body) < $deadline) {
            $chunk = @fread($client, 1024);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
        }
        @fclose($client);
        $parts[] = $body;

        $joined = trim(implode(' ', array_filter($parts)));

        return $joined !== '' ? $joined : null;
    }

    private function extractVendor(string $sysDescr): ?string
    {
        $vendors = ['Ricoh', 'HP', 'Canon', 'Epson', 'Brother', 'Xerox', 'Kyocera', 'Lexmark',
            'Sophos', 'Cisco', 'Meraki', 'Juniper', 'Aruba', 'MikroTik', 'Ubiquiti', 'TP-Link',
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
        // Sophos access-point models first (AP6 420, AP6 840E, APX 320, APX 530, …)
        if (preg_match('/\b(AP6\s?\d{3}E?|APX\s?\d{3})\b/i', $sysDescr, $m)) {
            return trim($m[1]);
        }

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
            'phaser', 'colorqube', 'docuprint', 'kyocera', 'print', 'copier',
            'epson', 'workforce', 'ecotank', 'expression', 'stylus',
            'canon', 'imageclass', 'i-sensys', 'pixma', 'maxify'];
        foreach ($printerKeywords as $kw) {
            if (str_contains($desc, $kw)) {
                return 'printer';
            }
        }
        if ($printerMib !== null) {
            return 'printer';
        } // Printer MIB responded

        // Access-point indicators (Sophos AP6/APX + generic AP keywords).
        // Checked before "switch" so a wireless device isn't misfiled.
        $apKeywords = ['ap6', 'apx', 'access point', 'accesspoint', 'sophos ap',
            'wireless access', 'wifi access', 'wi-fi access'];
        foreach ($apKeywords as $kw) {
            if (str_contains($desc, $kw)) {
                return 'access_point';
            }
        }

        // Switch / network device indicators
        $switchKeywords = ['switch', 'catalyst', 'nexus', 'juniper', 'aruba', 'procurve',
            'ios ', 'junos', 'comware', 'netgear', 'mikrotik', 'ubiquiti',
            'meraki', 'ex series', 'srx'];
        foreach ($switchKeywords as $kw) {
            if (str_contains($desc, $kw)) {
                return 'switch';
            }
        }

        // Generic device
        if ($sysDescr) {
            return 'device';
        }

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
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => 'No valid IPs parsed from range: '.$scan->range_input,
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

                    DiscoveryResult::updateOrCreate(
                        ['discovery_scan_id' => $scan->id, 'ip_address' => $ip],
                        $data
                    );

                    if ($data['is_reachable']) {
                        $reachable++;
                    }
                } catch (\Throwable $e) {
                    Log::warning("Discovery probe failed for {$ip}: ".$e->getMessage());
                }
            }

            $scan->update([
                'status' => 'completed',
                'finished_at' => now(),
                'reachable_count' => $reachable,
            ]);

        } catch (\Throwable $e) {
            Log::error("Discovery scan #{$scan->id} failed: ".$e->getMessage());
            $scan->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
        }
    }
}
