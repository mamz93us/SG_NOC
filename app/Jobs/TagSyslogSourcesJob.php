<?php

namespace App\Jobs;

use App\Models\MonitoredHost;
use App\Models\Printer;
use App\Models\SophosFirewall;
use App\Models\SyslogMessage;
use App\Models\UcmServer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Look up the source_ip of recently-received syslog rows in the device
 * inventories and tag them with source_type + source_id so the UI can
 * filter and the alert matcher can scope rules.
 *
 * Designed to be cheap enough to run every minute. We pull rows that
 * are still NULL on source_type and bulk-update by IP, so each unique
 * sender hits the inventory tables only once per pass.
 */
class TagSyslogSourcesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $unique = SyslogMessage::query()
            ->whereNull('source_type')
            ->where('received_at', '>=', now()->subHours(2))
            ->distinct()
            ->pluck('source_ip');

        if ($unique->isEmpty()) return;

        // Pre-load the lookup tables once. These are small (10s-100s of
        // rows) so it's cheaper than per-row queries.
        $sophos   = SophosFirewall::pluck('id', 'ip');
        $ucm      = $this->ucmIpMap();
        $hosts    = MonitoredHost::pluck('id', 'ip');
        $printers = $this->printerIpMap();

        $vpsLocal = ['127.0.0.1', '::1'];
        $tagged = 0;

        foreach ($unique as $ip) {
            [$type, $id] = $this->classify(
                $ip, $sophos, $ucm, $hosts, $printers, $vpsLocal
            );

            $count = SyslogMessage::where('source_ip', $ip)
                ->whereNull('source_type')
                ->update([
                    'source_type' => $type,
                    'source_id'   => $id,
                ]);

            $tagged += $count;
        }

        if ($tagged > 0) {
            Log::info("TagSyslogSourcesJob: classified {$tagged} rows across {$unique->count()} senders.");
        }
    }

    /**
     * Decide which device an IP belongs to. Returns [source_type, id|null].
     * Order matters: more specific matches first (firewall, switches),
     * then services (UCM/printer), then generic monitored hosts, then VPS.
     */
    private function classify(
        string $ip,
        $sophos,
        $ucm,
        $hosts,
        array $printers,
        array $vpsLocal
    ): array {
        if (in_array($ip, $vpsLocal, true)) {
            return ['vps', null];
        }

        if (isset($sophos[$ip])) {
            return ['sophos', (int) $sophos[$ip]];
        }

        if (isset($ucm[$ip])) {
            return ['ucm', (int) $ucm[$ip]];
        }

        if (isset($printers[$ip])) {
            return ['printer', (int) $printers[$ip]];
        }

        // Network switches don't have a stable management IP column we
        // can index on (lan_ip is from Meraki, often DHCP). Fall back
        // through monitored_hosts: most switches are also monitored
        // hosts via SNMP and their discovered_type tells us 'cisco' /
        // 'meraki' / 'sophos' / etc.
        if (isset($hosts[$ip])) {
            $hostId = (int) $hosts[$ip];
            $type = MonitoredHost::where('id', $hostId)->value('discovered_type');
            return [$this->mapDiscoveredType((string) $type), $hostId];
        }

        return ['unknown', null];
    }

    private function mapDiscoveredType(string $discovered): string
    {
        $d = strtolower($discovered);
        if (str_contains($d, 'sophos'))  return 'sophos';
        if (str_contains($d, 'cisco'))   return 'cisco';
        if (str_contains($d, 'switch'))  return 'cisco';
        if (str_contains($d, 'meraki'))  return 'cisco';
        if (str_contains($d, 'printer')) return 'printer';
        if (str_contains($d, 'mfp'))     return 'printer';
        if (str_contains($d, 'ucm'))     return 'ucm';
        if (str_contains($d, 'grandstream')) return 'ucm';
        return 'unknown';
    }

    /**
     * UCM servers store their connection target as a URL
     * ("https://192.168.1.100:8089"). Extract the host portion and only
     * keep IPv4-literal entries — hostnames generally don't match the
     * syslog source IP at the network layer.
     */
    private function ucmIpMap(): array
    {
        $map = [];
        foreach (UcmServer::pluck('id', 'url') as $url => $id) {
            $host = parse_url((string) $url, PHP_URL_HOST);
            if ($host && filter_var($host, FILTER_VALIDATE_IP)) {
                $map[$host] = (int) $id;
            }
        }
        return $map;
    }

    /**
     * Build IP→id map for printers, supporting multiple possible IP
     * column names across environments.
     */
    private function printerIpMap(): array
    {
        $columns = [];
        foreach (['ip', 'ip_address', 'host', 'lan_ip'] as $col) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('printers', $col)) {
                $columns[] = $col;
            }
        }

        $map = [];
        foreach ($columns as $col) {
            $rows = DB::table('printers')->whereNotNull($col)->pluck('id', $col);
            foreach ($rows as $ip => $id) {
                $map[$ip] = (int) $id;
            }
        }
        return $map;
    }
}
