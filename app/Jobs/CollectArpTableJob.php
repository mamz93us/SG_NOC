<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Models\SophosFirewall;
use App\Services\DhcpLeaseService;
use App\Services\Snmp\SnmpClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CollectArpTableJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;

    public function __construct(public MonitoredHost $host) {}

    public function handle(): void
    {
        if (!$this->host->snmp_enabled) {
            return;
        }

        Log::info("CollectArpTableJob: Starting ARP collection from {$this->host->ip}");

        $client = null;
        try {
            $client = new SnmpClient($this->host);

            // Walk the ARP table: ipNetToMediaPhysAddress
            // OID: .1.3.6.1.2.1.4.22.1.2
            $arpTable = $client->walk('1.3.6.1.2.1.4.22.1.2');

            if (!$arpTable) {
                Log::info("CollectArpTableJob: No ARP entries from {$this->host->ip}");
                return;
            }

            $entries = [];
            foreach ($arpTable as $oid => $macRaw) {
                // OID format: .1.3.6.1.2.1.4.22.1.2.<ifIndex>.<ip1>.<ip2>.<ip3>.<ip4>
                // Extract IP from last 4 OID segments
                $parts = explode('.', $oid);
                if (count($parts) < 4) continue;

                $ip = implode('.', array_slice($parts, -4));

                // Parse MAC address
                $mac = $this->parseMac($macRaw);
                if (!$mac || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) continue;

                // Skip broadcast/multicast MACs
                if (str_starts_with($mac, 'ff:ff:ff') || str_starts_with($mac, '01:00:5e')) continue;

                $entries[] = ['ip' => $ip, 'mac' => $mac];
            }

            Log::info("CollectArpTableJob: Found " . count($entries) . " ARP entries from {$this->host->ip}");

            // Find associated Sophos firewall
            $firewall = SophosFirewall::where('monitored_host_id', $this->host->id)
                ->orWhere('ip', $this->host->ip)
                ->first();

            if ($firewall && !empty($entries)) {
                $service = app(DhcpLeaseService::class);
                $service->syncFromArpTable($entries, $firewall);
            }

        } catch (\Throwable $e) {
            Log::error("CollectArpTableJob: Failed for {$this->host->ip}", [
                'error' => $e->getMessage(),
            ]);
        } finally {
            $client?->close();
        }
    }

    /**
     * Parse SNMP MAC address to standard colon-separated format.
     * Handles both hex-string and octet-string formats.
     */
    protected function parseMac(string $raw): ?string
    {
        // Remove SNMP type prefix (e.g., "Hex-STRING: ")
        $raw = preg_replace('/^[a-zA-Z\-]+:\s*/', '', trim($raw));
        $raw = trim($raw, '" ');

        // If it's already colon-separated hex
        if (preg_match('/^([0-9a-fA-F]{1,2}[:\-]){5}[0-9a-fA-F]{1,2}$/', $raw)) {
            $parts = preg_split('/[:\-]/', $raw);
            return strtolower(implode(':', array_map(fn($p) => str_pad($p, 2, '0', STR_PAD_LEFT), $parts)));
        }

        // Space-separated hex bytes (e.g., "AA BB CC DD EE FF")
        if (preg_match('/^([0-9a-fA-F]{2}\s){5}[0-9a-fA-F]{2}$/', $raw)) {
            return strtolower(implode(':', explode(' ', $raw)));
        }

        // Octet string (raw bytes)
        if (strlen($raw) === 6) {
            return strtolower(implode(':', array_map(fn($b) => sprintf('%02x', ord($b)), str_split($raw))));
        }

        return null;
    }
}
