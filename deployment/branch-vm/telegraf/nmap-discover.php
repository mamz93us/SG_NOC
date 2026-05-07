<?php
/**
 * SG_NOC Branch VM — nmap-based SNMP device discovery.
 *
 * Sweeps every local /24 subnet attached to the VM (or the explicit
 * SCAN_SUBNETS list from the env file), finds live hosts, probes each
 * for SNMP sysDescr.0 + sysName.0 with the configured community, and
 * POSTs the full set to the NOC's /api/branch-config/discovered-devices
 * endpoint. The operator approves/rejects findings in the NOC UI.
 *
 * Run hourly by sg-noc-nmap-discover.timer.
 */

declare(strict_types=1);

const ENV_FILE = '/etc/sg-noc-branch.env';

$env       = parse_env_file(ENV_FILE);
$noc_url   = rtrim($env['NOC_URL'] ?? '', '/');
$token     = $env['API_TOKEN']           ?? '';
$branch    = $env['BRANCH_ID']           ?? '';
$community = $env['SCAN_SNMP_COMMUNITY'] ?? 'public';

if ($noc_url === '' || $token === '' || $branch === '') {
    fwrite(STDERR, "ERROR: NOC_URL / API_TOKEN / BRANCH_ID missing in " . ENV_FILE . "\n");
    exit(1);
}

// ─── Determine which subnets to scan ─────────────────────────────────────
if (!empty($env['SCAN_SUBNETS'])) {
    $subnets = preg_split('/\s+/', trim($env['SCAN_SUBNETS']));
} else {
    $subnets = detect_local_subnets();
}

$subnets = array_values(array_filter(array_unique($subnets)));
if (!$subnets) {
    fwrite(STDERR, "ERROR: no subnets to scan (set SCAN_SUBNETS in env or check 'ip addr')\n");
    exit(2);
}

fwrite(STDERR, "scanning " . count($subnets) . " subnet(s): " . implode(' ', $subnets) . "\n");

// ─── nmap ping-sweep each subnet ─────────────────────────────────────────
$live = [];
foreach ($subnets as $net) {
    $cmd = sprintf(
        'nmap -sn -PE -PA80,443 -PS22,80,443 --max-rtt-timeout 1s %s 2>/dev/null',
        escapeshellarg($net)
    );
    $out = (string) shell_exec($cmd);
    if (preg_match_all(
        '/Nmap scan report for (?:[\w\-.]+ \()?(\d+\.\d+\.\d+\.\d+)\)?/m',
        $out,
        $m
    )) {
        $live = array_merge($live, $m[1]);
    }
}

$live = array_values(array_unique($live));
fwrite(STDERR, "live hosts: " . count($live) . "\n");

if (!$live) {
    // Still POST an empty list so the NOC sees the scan ran.
    post_to_noc($noc_url, $token, []);
    fwrite(STDOUT, "no live hosts found\n");
    exit(0);
}

// ─── Probe SNMP on each live host ────────────────────────────────────────
$discoveries = [];
foreach ($live as $host) {
    $sysDescr = trim(snmp_oneshot($host, $community, '1.3.6.1.2.1.1.1.0'), " \t\n\r\"");
    $sysName  = trim(snmp_oneshot($host, $community, '1.3.6.1.2.1.1.5.0'), " \t\n\r\"");

    $discoveries[] = [
        'host'            => $host,
        'sys_descr'       => $sysDescr !== '' ? mb_substr($sysDescr, 0, 1000) : null,
        'sys_name'        => $sysName  !== '' ? mb_substr($sysName,  0, 100)  : null,
        'snmp_responding' => $sysDescr !== '',
    ];
}

$snmpResp = count(array_filter($discoveries, fn ($d) => $d['snmp_responding']));
fwrite(STDERR, "snmp-responding: $snmpResp / " . count($discoveries) . "\n");

// ─── POST to NOC ─────────────────────────────────────────────────────────
[$code, $body] = post_to_noc($noc_url, $token, $discoveries);
if ($code !== 200) {
    fwrite(STDERR, "ERROR: NOC POST failed (HTTP $code): " . substr($body, 0, 200) . "\n");
    exit(3);
}

fwrite(STDOUT, "reported " . count($discoveries) . " hosts; NOC: $body\n");
exit(0);

// ─── Helpers ──────────────────────────────────────────────────────────────

function parse_env_file(string $path): array
{
    $out = [];
    if (!is_readable($path)) return $out;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $out[trim($k)] = trim($v, "\"' \t");
    }
    return $out;
}

function detect_local_subnets(): array
{
    $output = (string) shell_exec("ip -4 -o addr show 2>/dev/null");
    $subnets = [];
    foreach (explode("\n", $output) as $line) {
        // e.g. "2: ens34    inet 10.3.0.22/24 brd 10.3.0.255 scope global ens34"
        if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+(\d+\.\d+\.\d+\.\d+)\/(\d+)/', $line, $m)) continue;
        $iface = $m[1];
        $ip    = $m[2];
        $mask  = (int) $m[3];

        // Skip loopback, docker bridges, virtual veth, IPsec interfaces
        if (preg_match('/^(lo|docker|br-|virbr|veth|tun|tap|ipsec)/', $iface)) continue;

        // Cap at /24 — bigger sweeps take too long.
        if ($mask < 24) $mask = 24;

        $parts = explode('.', $ip);
        $cidr  = "{$parts[0]}.{$parts[1]}.{$parts[2]}.0/$mask";
        $subnets[$cidr] = true;
    }
    return array_keys($subnets);
}

function snmp_oneshot(string $host, string $community, string $oid): string
{
    $cmd = sprintf(
        'snmpget -v 2c -c %s -t 2 -r 1 -Oqv %s %s 2>/dev/null',
        escapeshellarg($community),
        escapeshellarg($host),
        escapeshellarg($oid)
    );
    return (string) shell_exec($cmd);
}

/** @return array{0:int,1:string} */
function post_to_noc(string $noc_url, string $token, array $devices): array
{
    $payload = json_encode(['devices' => $devices]);
    $ch = curl_init("$noc_url/api/branch-config/discovered-devices");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer $token",
            "Content-Type: application/json",
            "Accept: application/json",
        ],
    ]);
    $body = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [(int) $code, $body];
}
