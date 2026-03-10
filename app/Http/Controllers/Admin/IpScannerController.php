<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\PingService;
use Illuminate\Http\Request;

class IpScannerController extends Controller
{
    protected PingService $ping;

    public function __construct(PingService $ping)
    {
        $this->ping = $ping;
    }

    public function index()
    {
        return view('admin.network.ip-scanner');
    }

    public function scan(Request $request)
    {
        try {
            $request->validate([
                'subnet' => 'required|string|max:50',
            ]);

            $subnet = trim($request->input('subnet'));
            $ips = $this->expandSubnet($subnet);

            if (empty($ips)) {
                return response()->json(['error' => 'Invalid subnet or range. Use CIDR (192.168.1.0/24) or range (192.168.1.1-254).'], 422);
            }

            // Limit to prevent abuse
            if (count($ips) > 254) {
                return response()->json(['error' => 'Scan range too large. Maximum 254 IPs per scan.'], 422);
            }

            $results = [];
            $aliveIps = [];

            // 1. Try parallel scanning with fping if available (much faster)
            $fpingPath = @shell_exec('which fping');
            if ($fpingPath && count($ips) > 1) {
                $startIp = $ips[0];
                $endIp = end($ips);
                $cmd = "fping -g {$startIp} {$endIp} -a -r 1 -t 200 2>/dev/null";
                $output = shell_exec($cmd);
                if ($output) {
                    $aliveIps = array_filter(explode("\n", trim($output)));
                }

                foreach ($ips as $ip) {
                    $isAlive = in_array($ip, $aliveIps);
                    $results[] = [
                        'ip'         => $ip,
                        'alive'      => $isAlive,
                        'latency_ms' => null, // fping -a doesn't show latency in this mode, but we can add another pass if needed for alive ones
                        'hostname'   => null, 
                    ];
                }
            } else {
                // 2. Fallback to serial ping (only if fping fails or single IP)
                foreach ($ips as $ip) {
                    try {
                        $result = $this->ping->ping($ip, 1); 
                    } catch (\Throwable $e) {
                        $result = ['success' => false, 'latency' => null];
                    }

                    $results[] = [
                        'ip'         => $ip,
                        'alive'      => $result['success'],
                        'latency_ms' => $result['latency'] ?? null,
                        'hostname'   => null,
                    ];
                }
            }

            // 3. Briefly try reverse DNS only for ALIVE hosts to avoid bottleneck
            foreach ($results as &$res) {
                if ($res['alive']) {
                    $hostname = @gethostbyaddr($res['ip']);
                    if ($hostname && $hostname !== $res['ip']) {
                        $res['hostname'] = $hostname;
                    }
                }
            }

            $aliveCount = collect($results)->where('alive', true)->count();

            return response()->json([
                'total'   => count($results),
                'alive'   => $aliveCount,
                'results' => $results,
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => collect($e->errors())->flatten()->first()], 422);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('IP Scanner error: ' . $e->getFile() . ':' . $e->getLine() . ' (' . $e->getMessage() . ')');
            return response()->json(['error' => 'Scan failed on server: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Expand a subnet (CIDR) or IP range string into an array of IPs.
     */
    private function expandSubnet(string $input): array
    {
        // CIDR notation: 192.168.1.0/24
        if (str_contains($input, '/')) {
            return $this->expandCidr($input);
        }

        // Range notation: 192.168.1.1-254 or 192.168.1.1-192.168.1.100
        if (str_contains($input, '-')) {
            return $this->expandRange($input);
        }

        // Single IP
        if (filter_var($input, FILTER_VALIDATE_IP)) {
            return [$input];
        }

        return [];
    }

    private function expandCidr(string $cidr): array
    {
        [$base, $prefix] = explode('/', $cidr);
        if (!filter_var($base, FILTER_VALIDATE_IP) || !is_numeric($prefix) || $prefix < 16 || $prefix > 32) {
            return [];
        }

        $start = ip2long($base) & (~0 << (32 - (int)$prefix));
        $end   = $start | ((1 << (32 - (int)$prefix)) - 1);

        $ips = [];
        for ($i = $start + 1; $i < $end; $i++) { // Skip network and broadcast
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    private function expandRange(string $range): array
    {
        $parts = explode('-', $range, 2);
        $startIp = trim($parts[0]);
        $endPart  = trim($parts[1]);

        if (!filter_var($startIp, FILTER_VALIDATE_IP)) return [];

        // Short format: 192.168.1.1-254 (last octet only)
        if (is_numeric($endPart)) {
            $startLong = ip2long($startIp);
            $baseOctets = explode('.', $startIp);
            $baseOctets[3] = (int)$endPart;
            $endIp = implode('.', $baseOctets);
        } else {
            $endIp = $endPart;
        }

        if (!filter_var($endIp, FILTER_VALIDATE_IP)) return [];

        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start > $end) return [];

        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }
}
