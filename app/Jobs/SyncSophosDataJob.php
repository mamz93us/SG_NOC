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
            \App\Models\NocEvent::where('module', 'sophos')
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
                'sophos',
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
        $synced = [];

        foreach ($interfaces as $iface) {
            $name = $iface['Name'] ?? $iface['name'] ?? null;
            if (!$name) continue;

            $record = SophosInterface::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'name' => $name],
                [
                    'hardware'   => $iface['Hardware'] ?? $iface['hardware'] ?? null,
                    'ip_address' => $iface['IPv4Configuration']['IPAddress'] ?? $iface['IPAddress'] ?? $iface['ip_address'] ?? null,
                    'netmask'    => $iface['IPv4Configuration']['Netmask'] ?? $iface['Netmask'] ?? $iface['netmask'] ?? null,
                    'zone'       => $iface['Zone'] ?? $iface['NetworkZone'] ?? $iface['zone'] ?? null,
                    'status'     => $this->normalizeStatus($iface['Status'] ?? $iface['status'] ?? 'unknown'),
                    'mtu'        => $iface['MTU'] ?? $iface['mtu'] ?? null,
                    'speed'      => $iface['Speed'] ?? $iface['speed'] ?? null,
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
        $synced  = [];

        foreach ($objects as $obj) {
            $name = $obj['Name'] ?? $obj['name'] ?? null;
            if (!$name) continue;

            $record = SophosNetworkObject::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'name' => $name],
                [
                    'object_type' => $obj['HostType'] ?? $obj['IPFamily'] ?? $obj['host_type'] ?? null,
                    'ip_address'  => $obj['IPAddress'] ?? $obj['ip_address'] ?? null,
                    'subnet'      => $obj['Subnet'] ?? $obj['subnet'] ?? null,
                    'host_type'   => $obj['HostType'] ?? $obj['host_type'] ?? null,
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
        $synced  = [];

        foreach ($tunnels as $tunnel) {
            $name = $tunnel['Name'] ?? $tunnel['name'] ?? null;
            if (!$name) continue;

            $record = SophosVpnTunnel::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'name' => $name],
                [
                    'connection_type' => $tunnel['ConnectionType'] ?? $tunnel['connection_type'] ?? null,
                    'policy'          => $tunnel['Policy'] ?? $tunnel['policy'] ?? null,
                    'remote_gateway'  => $tunnel['RemoteGateway'] ?? $tunnel['remote_gateway'] ?? null,
                    'local_subnet'    => $this->extractSubnet($tunnel, 'LocalSubnet'),
                    'remote_subnet'   => $this->extractSubnet($tunnel, 'RemoteSubnet'),
                    'status'          => $this->normalizeStatus($tunnel['Status'] ?? $tunnel['status'] ?? 'unknown'),
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
        $synced = [];
        $pos    = 0;

        foreach ($rules as $rule) {
            $name = $rule['Name'] ?? $rule['name'] ?? null;
            if (!$name) continue;

            $pos++;
            $record = SophosFirewallRule::updateOrCreate(
                ['firewall_id' => $this->firewall->id, 'rule_name' => $name],
                [
                    'position'        => $rule['Position'] ?? $pos,
                    'source_zone'     => $this->extractZone($rule, 'SourceZones'),
                    'dest_zone'       => $this->extractZone($rule, 'DestinationZones'),
                    'source_networks' => $this->extractNetworks($rule, 'SourceNetworks'),
                    'dest_networks'   => $this->extractNetworks($rule, 'DestinationNetworks'),
                    'services'        => $this->extractServices($rule),
                    'action'          => strtolower($rule['Action'] ?? $rule['action'] ?? 'drop'),
                    'enabled'         => ($rule['Status'] ?? $rule['status'] ?? 'Enable') !== 'Disable',
                    'log_traffic'     => ($rule['LogTraffic'] ?? $rule['log_traffic'] ?? 'Disable') !== 'Disable',
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

    protected function normalizeStatus(string $raw): string
    {
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
            return is_array($zone) ? implode(', ', $zone) : $zone;
        }
        return $zones;
    }

    protected function extractNetworks(array $rule, string $key): ?array
    {
        $networks = $rule[$key] ?? $rule[lcfirst($key)] ?? null;
        if (is_array($networks)) {
            $net = $networks['Network'] ?? $networks;
            return is_array($net) ? (isset($net[0]) ? $net : [$net]) : [$net];
        }
        return $networks ? [$networks] : null;
    }

    protected function extractServices(array $rule): ?array
    {
        $services = $rule['Services'] ?? $rule['services'] ?? null;
        if (is_array($services)) {
            $svc = $services['Service'] ?? $services;
            return is_array($svc) ? (isset($svc[0]) ? $svc : [$svc]) : [$svc];
        }
        return $services ? [$services] : null;
    }
}
