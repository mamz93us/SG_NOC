<?php

namespace App\Jobs;

use App\Models\SophosFirewall;
use App\Models\SophosFirewallRule;
use App\Models\SophosInterface;
use App\Models\SophosNetworkObject;
use App\Models\SophosVpnTunnel;
use App\Services\NocAlertEngine;
use App\Services\Sophos\SophosApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSophosDataJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    public function __construct(public SophosFirewall $firewall) {}

    public function handle(): void
    {
        if (!$this->firewall->sync_enabled) {
            return;
        }

        Log::info("SyncSophosDataJob: Starting sync for {$this->firewall->name} ({$this->firewall->ip})");

        try {
            $api = new SophosApiService($this->firewall);

            $ifaceCount  = $this->syncInterfaces($api);
            $objectCount = $this->syncNetworkObjects($api);
            $vpnCount    = $this->syncVpnTunnels($api);
            $ruleCount   = $this->syncFirewallRules($api);

            Log::info("SyncSophosDataJob: Synced {$this->firewall->name} — {$ifaceCount} interfaces, {$objectCount} objects, {$vpnCount} VPN tunnels, {$ruleCount} rules");

            $this->firewall->update(['last_synced_at' => now()]);

            // Auto-resolve stale Sophos sync failure alerts
            \App\Models\NocEvent::where('module', 'network')
                ->where('entity_type', 'firewall')
                ->where('entity_id', (string) $this->firewall->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            Log::info("SyncSophosDataJob: Completed sync for {$this->firewall->name}");

        } catch (\Throwable $e) {
            Log::error("SyncSophosDataJob: Failed for {$this->firewall->name}", [
                'error' => $e->getMessage(),
            ]);

            // Create NOC alert for sync failure
            app(NocAlertEngine::class)->createOrUpdateEvent(
                'network',
                'firewall',
                (string) $this->firewall->id,
                'warning',
                "Sophos Sync Failed: {$this->firewall->name}",
                "Failed to sync data from Sophos firewall {$this->firewall->name} ({$this->firewall->ip}): {$e->getMessage()}"
            );
        }
    }

    protected function syncInterfaces(SophosApiService $api): int
    {
        $interfaces = $api->getInterfaces();
        Log::debug("SyncSophosDataJob: getInterfaces returned " . count($interfaces) . " items for {$this->firewall->name}");
        $synced = [];

        foreach ($interfaces as $iface) {
            $name = $iface['Name'] ?? $iface['name'] ?? null;
            if (!$name) continue;

            // Extract IP/Netmask — may be nested in IPv4Configuration or flat
            $ipv4 = $iface['IPv4Configuration'] ?? [];
            $ipAddr  = is_array($ipv4) ? ($ipv4['IPAddress'] ?? null) : null;
            $netmask = is_array($ipv4) ? ($ipv4['Netmask'] ?? null) : null;
            if (!$ipAddr) $ipAddr  = $iface['IPAddress'] ?? $iface['ip_address'] ?? null;
            if (!$netmask) $netmask = $iface['Netmask'] ?? $iface['netmask'] ?? null;

            $record = SophosInterface::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'name' => $name],
                [
                    'hardware'   => $this->stringify($iface['Hardware'] ?? $iface['hardware'] ?? null),
                    'ip_address' => $this->stringify($ipAddr),
                    'netmask'    => $this->stringify($netmask),
                    'zone'       => $this->stringify($iface['Zone'] ?? $iface['NetworkZone'] ?? $iface['zone'] ?? null),
                    'status'     => $this->normalizeStatus($this->stringify($iface['Status'] ?? $iface['status'] ?? 'unknown')),
                    'mtu'        => ($mtuVal = $this->stringify($iface['MTU'] ?? $iface['mtu'] ?? null)) !== null ? (int) $mtuVal : null,
                    'speed'      => $this->stringify($iface['Speed'] ?? $iface['speed'] ?? null),
                ]
            );
            $synced[] = $record->id;
        }

        // Remove interfaces no longer present on firewall
        if (!empty($synced)) {
            SophosInterface::where('firewall_id', $this->firewall->id)
                ->whereNotIn('id', $synced)
                ->delete();
        }

        return count($synced);
    }

    protected function syncNetworkObjects(SophosApiService $api): int
    {
        $objects = $api->getIPHosts();
        Log::debug("SyncSophosDataJob: getIPHosts returned " . count($objects) . " items for {$this->firewall->name}");
        if (!empty($objects)) {
            Log::debug("SyncSophosDataJob: IPHost sample keys", ['keys' => array_keys($objects[0] ?? [])]);
        }
        $synced  = [];

        foreach ($objects as $obj) {
            $name = $obj['Name'] ?? $obj['name'] ?? null;
            if (!$name) continue;

            $record = SophosNetworkObject::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'name' => $name],
                [
                    'object_type' => $this->stringify($obj['HostType'] ?? $obj['IPFamily'] ?? $obj['host_type'] ?? null),
                    'ip_address'  => $this->stringify($obj['IPAddress'] ?? $obj['ip_address'] ?? null),
                    'subnet'      => $this->stringify($obj['Subnet'] ?? $obj['subnet'] ?? null),
                    'host_type'   => $this->stringify($obj['HostType'] ?? $obj['host_type'] ?? null),
                ]
            );
            $synced[] = $record->id;
        }

        if (!empty($synced)) {
            SophosNetworkObject::where('firewall_id', $this->firewall->id)
                ->whereNotIn('id', $synced)
                ->delete();
        }

        return count($synced);
    }

    protected function syncVpnTunnels(SophosApiService $api): int
    {
        $tunnels = $api->getIPSecConnections();
        Log::debug("SyncSophosDataJob: getIPSecConnections returned " . count($tunnels) . " items for {$this->firewall->name}");
        if (!empty($tunnels)) {
            Log::debug("SyncSophosDataJob: IPSecConnection sample keys", ['keys' => array_keys($tunnels[0] ?? [])]);
        }
        $synced  = [];

        foreach ($tunnels as $tunnel) {
            $name = $tunnel['Name'] ?? $tunnel['name'] ?? null;
            if (!$name) continue;

            $record = SophosVpnTunnel::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'name' => $name],
                [
                    'connection_type' => $this->stringify($tunnel['ConnectionType'] ?? $tunnel['connection_type'] ?? null),
                    'policy'          => $this->stringify($tunnel['Policy'] ?? $tunnel['policy'] ?? null),
                    'remote_gateway'  => $this->stringify($tunnel['RemoteGateway'] ?? $tunnel['remote_gateway'] ?? null),
                    'local_subnet'    => $this->extractSubnet($tunnel, 'LocalSubnet'),
                    'remote_subnet'   => $this->extractSubnet($tunnel, 'RemoteSubnet'),
                    'status'          => $this->normalizeStatus($this->stringify($tunnel['Status'] ?? $tunnel['status'] ?? 'unknown')),
                    'last_checked_at' => now(),
                ]
            );
            $synced[] = $record->id;
        }

        if (!empty($synced)) {
            SophosVpnTunnel::where('firewall_id', $this->firewall->id)
                ->whereNotIn('id', $synced)
                ->delete();
        }

        return count($synced);
    }

    protected function syncFirewallRules(SophosApiService $api): int
    {
        $rules  = $api->getFirewallRules();
        Log::debug("SyncSophosDataJob: getFirewallRules returned " . count($rules) . " items for {$this->firewall->name}");
        if (!empty($rules)) {
            Log::debug("SyncSophosDataJob: FirewallRule sample keys", ['keys' => array_keys($rules[0] ?? [])]);
        }
        $synced = [];
        $pos    = 0;

        foreach ($rules as $rule) {
            $name = $rule['Name'] ?? $rule['name'] ?? null;
            if (!$name) continue;

            $pos++;
            $record = SophosFirewallRule::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'rule_name' => $name],
                [
                    'position'        => (int) ($this->stringify($rule['Position'] ?? null) ?? $pos),
                    'source_zone'     => $this->extractZone($rule, 'SourceZones'),
                    'dest_zone'       => $this->extractZone($rule, 'DestinationZones'),
                    'source_networks' => $this->extractNetworks($rule, 'SourceNetworks'),
                    'dest_networks'   => $this->extractNetworks($rule, 'DestinationNetworks'),
                    'services'        => $this->extractServices($rule),
                    'action'          => strtolower($this->stringify($rule['Action'] ?? $rule['action'] ?? null) ?? 'drop'),
                    'enabled'         => ($this->stringify($rule['Status'] ?? $rule['status'] ?? 'Enable')) !== 'Disable',
                    'log_traffic'     => ($this->stringify($rule['LogTraffic'] ?? $rule['log_traffic'] ?? 'Disable')) !== 'Disable',
                ]
            );
            $synced[] = $record->id;
        }

        if (!empty($synced)) {
            SophosFirewallRule::where('firewall_id', $this->firewall->id)
                ->whereNotIn('id', $synced)
                ->delete();
        }

        return count($synced);
    }

    // ─── Parsing Helpers ──────────────────────────────────────────

    /**
     * Safely convert a value to string — handles arrays from XML parsing.
     */
    protected function stringify(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_array($value)) {
            // If it's a simple array with one element, use that
            if (count($value) === 1 && isset($value[0])) return (string) $value[0];
            // If it's an empty array, return null
            if (empty($value)) return null;
            // Otherwise JSON-encode it
            return json_encode($value);
        }
        return (string) $value;
    }

    protected function normalizeStatus(?string $raw): string
    {
        if ($raw === null) return 'unknown';
        $lower = strtolower(trim($raw));
        if (in_array($lower, ['up', 'connected', 'enable', 'enabled', 'active', 'online'])) return 'up';
        if (in_array($lower, ['down', 'disconnected', 'disable', 'disabled', 'offline'])) return 'down';
        return 'unknown';
    }

    protected function extractSubnet(array $data, string $key): ?string
    {
        $val = $data[$key] ?? $data[lcfirst($key)] ?? null;
        if (is_array($val)) return json_encode($val);
        return $val;
    }

    protected function extractZone(array $rule, string $key): ?string
    {
        $zones = $rule[$key] ?? $rule[lcfirst($key)] ?? null;
        if (is_array($zones)) {
            $zone = $zones['Zone'] ?? $zones;
            if (is_array($zone)) {
                // Handle nested arrays — flatten to strings
                $flat = array_map(fn($z) => is_array($z) ? json_encode($z) : (string) $z, $zone);
                return implode(', ', $flat);
            }
            return (string) $zone;
        }
        return is_array($zones) ? json_encode($zones) : $zones;
    }

    protected function extractNetworks(array $rule, string $key): ?array
    {
        $networks = $rule[$key] ?? $rule[lcfirst($key)] ?? null;
        if (is_array($networks)) {
            $net = $networks['Network'] ?? $networks;
            if (!is_array($net)) return [$net];
            // Indexed array of networks
            if (isset($net[0])) {
                return array_map(fn($n) => is_array($n) ? json_encode($n) : $n, $net);
            }
            // Single associative network
            return [is_string($net) ? $net : json_encode($net)];
        }
        return $networks ? [(string) $networks] : null;
    }

    protected function extractServices(array $rule): ?array
    {
        $services = $rule['Services'] ?? $rule['services'] ?? null;
        if (is_array($services)) {
            $svc = $services['Service'] ?? $services;
            if (!is_array($svc)) return [$svc];
            // Indexed array of services
            if (isset($svc[0])) {
                return array_map(fn($s) => is_array($s) ? json_encode($s) : $s, $svc);
            }
            // Single associative service
            return [is_string($svc) ? $svc : json_encode($svc)];
        }
        return $services ? [(string) $services] : null;
    }
}
