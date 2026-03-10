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

        $errors = [];

        try {
            $api = new SophosApiService($this->firewall);

            // Each sync is independent — one failure should NOT block others
            $ifaceCount  = $this->safeSyncInterfaces($api, $errors);
            $objectCount = $this->safeSyncNetworkObjects($api, $errors);
            $vpnCount    = $this->safeSyncVpnTunnels($api, $errors);
            $ruleCount   = $this->safeSyncFirewallRules($api, $errors);

            Log::info("SyncSophosDataJob: Synced {$this->firewall->name} — {$ifaceCount} interfaces, {$objectCount} objects, {$vpnCount} VPN tunnels, {$ruleCount} rules");

            $this->firewall->update(['last_synced_at' => now()]);

            // Log to ActivityLog for UI visibility
            \App\Models\ActivityLog::log(
                'Sophos Sync',
                "Synced '{$this->firewall->name}': {$ifaceCount} interfaces, {$objectCount} objects, {$vpnCount} VPN tunnels, {$ruleCount} rules.",
                'success',
                $this->firewall->id
            );

            // Auto-resolve stale Sophos sync failure alerts
            \App\Models\NocEvent::where('module', 'network')
                ->where('entity_type', 'firewall')
                ->where('entity_id', (string) $this->firewall->id)
                ->whereIn('status', ['open', 'acknowledged'])
                ->update(['status' => 'resolved', 'resolved_at' => now()]);

            // Log any per-entity errors that were caught but didn't kill the sync
            if (!empty($errors)) {
                Log::warning("SyncSophosDataJob: {$this->firewall->name} completed with " . count($errors) . " warnings", $errors);
                
                \App\Models\ActivityLog::log(
                    'Sophos Sync Warning',
                    "Sync for '{$this->firewall->name}' had warnings: " . implode(', ', $errors),
                    'warning',
                    $this->firewall->id
                );
            }

            Log::info("SyncSophosDataJob: Completed sync for {$this->firewall->name}");

        } catch (\Throwable $e) {
            Log::error("SyncSophosDataJob: Failed for {$this->firewall->name}", [
                'error' => $e->getMessage(),
            ]);

            // Create NOC alert for sync failure
            try {
                app(NocAlertEngine::class)->createOrUpdateEvent(
                    'network',
                    'firewall',
                    (string) $this->firewall->id,
                    'warning',
                    "Sophos Sync Failed: {$this->firewall->name}",
                    "Failed to sync data from Sophos firewall {$this->firewall->name} ({$this->firewall->ip}): {$e->getMessage()}"
                );
            } catch (\Throwable $alertErr) {
                Log::error("SyncSophosDataJob: Could not create NOC alert", ['error' => $alertErr->getMessage()]);
            }
        }
    }

    // ─── Safe Sync Wrappers (isolate failures) ──────────────────

    protected function safeSyncInterfaces(SophosApiService $api, array &$errors): int
    {
        try {
            return $this->syncInterfaces($api);
        } catch (\Throwable $e) {
            $errors[] = "Interfaces: {$e->getMessage()}";
            Log::error("SyncSophosDataJob: syncInterfaces failed for {$this->firewall->name}", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    protected function safeSyncNetworkObjects(SophosApiService $api, array &$errors): int
    {
        try {
            return $this->syncNetworkObjects($api);
        } catch (\Throwable $e) {
            $errors[] = "NetworkObjects: {$e->getMessage()}";
            Log::error("SyncSophosDataJob: syncNetworkObjects failed for {$this->firewall->name}", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    protected function safeSyncVpnTunnels(SophosApiService $api, array &$errors): int
    {
        try {
            return $this->syncVpnTunnels($api);
        } catch (\Throwable $e) {
            $errors[] = "VpnTunnels: {$e->getMessage()}";
            Log::error("SyncSophosDataJob: syncVpnTunnels failed for {$this->firewall->name}", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    protected function safeSyncFirewallRules(SophosApiService $api, array &$errors): int
    {
        try {
            return $this->syncFirewallRules($api);
        } catch (\Throwable $e) {
            $errors[] = "FirewallRules: {$e->getMessage()}";
            Log::error("SyncSophosDataJob: syncFirewallRules failed for {$this->firewall->name}", ['error' => $e->getMessage()]);
            return 0;
        }
    }

    // ─── Entity Sync Methods ────────────────────────────────────

    protected function syncInterfaces(SophosApiService $api): int
    {
        $interfaces = $api->getInterfaces();
        Log::debug("SyncSophosDataJob: getInterfaces returned " . count($interfaces) . " items for {$this->firewall->name}");
        $synced = [];

        foreach ($interfaces as $iface) {
            try {
                $name = $this->stringify($iface['Name'] ?? $iface['name'] ?? null);
                if (!$name) continue;

                // Extract IP/Netmask — may be nested in IPv4Configuration or flat
                $ipv4 = $iface['IPv4Configuration'] ?? [];
                $ipAddr  = is_array($ipv4) ? ($ipv4['IPAddress'] ?? null) : null;
                $netmask = is_array($ipv4) ? ($ipv4['Netmask'] ?? null) : null;
                if (!$ipAddr) $ipAddr  = $iface['IPAddress'] ?? $iface['ip_address'] ?? null;
                if (!$netmask) $netmask = $iface['Netmask'] ?? $iface['netmask'] ?? null;

                // Determine status — Sophos uses many field names across versions
                $rawStatus = $iface['Status'] ?? $iface['status']
                    ?? $iface['InterfaceStatus'] ?? $iface['LinkStatus']
                    ?? $iface['AdminStatus'] ?? $iface['ActiveStatus'] 
                    ?? $iface['Active'] ?? $iface['Enabled'] ?? null;

                $record = SophosInterface::updateOrCreate(
                    ['firewall_id' => $this->firewall->id, 'name' => $name],
                    [
                        'hardware'   => $this->stringify($iface['Hardware'] ?? $iface['hardware'] ?? null),
                        'ip_address' => $this->stringify($ipAddr),
                        'netmask'    => $this->stringify($netmask),
                        'zone'       => $this->stringify($iface['Zone'] ?? $iface['NetworkZone'] ?? $iface['zone'] ?? null),
                        'status'     => $this->normalizeStatus($this->stringify($rawStatus)),
                        'mtu'        => ($mtuVal = $this->stringify($iface['MTU'] ?? $iface['mtu'] ?? null)) !== null ? (int) $mtuVal : null,
                        'speed'      => $this->stringify($iface['Speed'] ?? $iface['speed'] ?? null),
                    ]
                );
                $synced[] = $record->id;
            } catch (\Throwable $e) {
                $ifName = $iface['Name'] ?? $iface['name'] ?? 'unknown';
                Log::warning("SyncSophosDataJob: Skipped interface '{$ifName}': {$e->getMessage()}");
            }
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
            try {
                $name = $this->stringify($obj['Name'] ?? $obj['name'] ?? null);
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
            } catch (\Throwable $e) {
                $objName = $obj['Name'] ?? $obj['name'] ?? 'unknown';
                Log::warning("SyncSophosDataJob: Skipped IPHost '{$objName}': {$e->getMessage()}");
            }
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
            Log::debug("SyncSophosDataJob: VPN tunnel sample keys", ['keys' => array_keys($tunnels[0] ?? [])]);
        }
        $synced  = [];

        foreach ($tunnels as $tunnel) {
            try {
                $name = $this->stringify($tunnel['Name'] ?? $tunnel['name'] ?? null);
                if (!$name) continue;

                // Sophos uses different field names across versions:
                // SFOS 18+: ConnectionType, Policy, RemoteGateway, LocalSubnet, RemoteSubnet
                // Older: ConnectionType, Authentication/Policy, Gateway/RemoteGateway
                $remoteGw = $tunnel['RemoteGateway'] ?? $tunnel['Gateway'] ?? $tunnel['remote_gateway'] ?? null;
                $policy   = $tunnel['Policy'] ?? $tunnel['Authentication'] ?? $tunnel['policy'] ?? null;

                $record = SophosVpnTunnel::updateOrCreate(
                    ['firewall_id' => $this->firewall->id, 'name' => $name],
                    [
                        'connection_type' => $this->stringify($tunnel['ConnectionType'] ?? $tunnel['Type'] ?? $tunnel['connection_type'] ?? null),
                        'policy'          => $this->stringify($policy),
                        'remote_gateway'  => $this->stringify($remoteGw),
                        'local_subnet'    => $this->extractSubnet($tunnel, 'LocalSubnet'),
                        'remote_subnet'   => $this->extractSubnet($tunnel, 'RemoteSubnet'),
                        'status'          => $this->normalizeStatus($this->stringify($tunnel['Status'] ?? $tunnel['ConnectionStatus'] ?? $tunnel['ActiveStatus'] ?? $tunnel['Active'] ?? $tunnel['status'] ?? null)),
                        'last_checked_at' => now(),
                    ]
                );
                $synced[] = $record->id;
            } catch (\Throwable $e) {
                $tunnelName = $tunnel['Name'] ?? $tunnel['name'] ?? 'unknown';
                Log::warning("SyncSophosDataJob: Skipped VPN tunnel '{$tunnelName}': {$e->getMessage()}");
            }
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
            try {
                $name = $this->stringify($rule['Name'] ?? $rule['name'] ?? null);
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
            } catch (\Throwable $e) {
                $ruleName = $rule['Name'] ?? $rule['name'] ?? 'unknown';
                Log::warning("SyncSophosDataJob: Skipped firewall rule '{$ruleName}': {$e->getMessage()}");
            }
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
     * Safely convert ANY value to string — handles arrays/objects from XML parsing.
     */
    protected function stringify(mixed $value): ?string
    {
        if ($value === null) return null;
        if (is_bool($value)) return $value ? '1' : '0';
        if (is_array($value)) {
            // If it's a simple array with one element, use that
            if (count($value) === 1 && isset($value[0])) return (string) $value[0];
            // If it's an empty array, return null
            if (empty($value)) return null;
            // If it has a single text value (XML attribute parsing), extract it
            if (isset($value[0]) && count($value) === 1) return (string) $value[0];
            // Otherwise JSON-encode it
            return json_encode($value);
        }
        if (is_object($value)) return json_encode($value);
        return (string) $value;
    }

    protected function normalizeStatus(?string $raw): string
    {
        if ($raw === null || $raw === '') return 'unknown';
        $lower = strtolower(trim($raw));

        // Map Sophos-specific status values
        if (in_array($lower, [
            'up', 'connected', 'enable', 'enabled', 'active', 'online',
            'established', 'running', 'operational', 'link up',
        ])) {
            return 'up';
        }

        if (in_array($lower, [
            'down', 'disconnected', 'disable', 'disabled', 'offline',
            'not connected', 'link down', 'inactive', 'not established',
        ])) {
            return 'down';
        }

        // Check for numeric values (1 = Up, 0 = Down)
        if ($lower === '1') return 'up';
        if ($lower === '0') return 'down';

        // Check partial matches
        if (str_contains($lower, 'connect') || str_contains($lower, 'enable') || str_contains($lower, 'active') || str_contains($lower, 'up') || str_contains($lower, 'establish')) {
            return 'up';
        }
        if (str_contains($lower, 'disconnect') || str_contains($lower, 'disable') || str_contains($lower, 'down') || str_contains($lower, 'inactive')) {
            return 'down';
        }

        return 'unknown';
    }

    protected function extractSubnet(array $data, string $key): ?string
    {
        $val = $data[$key] ?? $data[lcfirst($key)] ?? null;
        if ($val === null) return null;
        if (is_array($val)) return json_encode($val);
        return (string) $val;
    }

    protected function extractZone(array $rule, string $key): ?string
    {
        $zones = $rule[$key] ?? $rule[lcfirst($key)] ?? null;
        if ($zones === null) return null;
        if (is_array($zones)) {
            $zone = $zones['Zone'] ?? $zones;
            if (is_array($zone)) {
                $flat = array_map(fn($z) => is_array($z) ? json_encode($z) : (string) $z, $zone);
                return implode(', ', $flat);
            }
            return (string) $zone;
        }
        return (string) $zones;
    }

    protected function extractNetworks(array $rule, string $key): ?array
    {
        $networks = $rule[$key] ?? $rule[lcfirst($key)] ?? null;
        if ($networks === null) return null;
        if (is_array($networks)) {
            $net = $networks['Network'] ?? $networks;
            if (!is_array($net)) return [(string) $net];
            if (isset($net[0])) {
                return array_map(fn($n) => is_array($n) ? json_encode($n) : (string) $n, $net);
            }
            return [json_encode($net)];
        }
        return [(string) $networks];
    }

    protected function extractServices(array $rule): ?array
    {
        $services = $rule['Services'] ?? $rule['services'] ?? null;
        if ($services === null) return null;
        if (is_array($services)) {
            $svc = $services['Service'] ?? $services;
            if (!is_array($svc)) return [(string) $svc];
            if (isset($svc[0])) {
                return array_map(fn($s) => is_array($s) ? json_encode($s) : (string) $s, $svc);
            }
            return [json_encode($svc)];
        }
        return [(string) $services];
    }
}
