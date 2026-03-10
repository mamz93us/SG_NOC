<?php

namespace App\Services;

use App\Models\DhcpLease;
use App\Models\Device;
use App\Models\IpamSubnet;
use App\Models\NocEvent;
use App\Models\SophosFirewall;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DhcpLeaseService
{
    // ─── Meraki Sync ──────────────────────────────────────────────

    /**
     * Create/update DHCP leases from Meraki client data.
     *
     * @param array  $clientData   Raw client array from Meraki API
     * @param string $switchSerial Switch that reported this client
     * @param int|null $branchId   Branch of the reporting switch
     */
    public function syncFromMeraki(array $clientData, string $switchSerial, ?int $branchId = null): void
    {
        $mac = $clientData['mac'] ?? null;
        if (!$mac) return;

        $ip = $clientData['ip'] ?? null;

        $lease = DhcpLease::updateOrCreate(
            ['mac_address' => $mac, 'source' => 'meraki'],
            [
                'ip_address'    => $ip,
                'hostname'      => $clientData['description'] ?? $clientData['dhcpHostname'] ?? null,
                'vendor'        => $clientData['manufacturer'] ?? null,
                'vlan'          => $clientData['vlan'] ?? null,
                'source_device' => $switchSerial,
                'switch_serial' => $switchSerial,
                'port_id'       => $clientData['switchport'] ?? null,
                'branch_id'     => $branchId,
                'last_seen'     => now(),
            ]
        );

        // Try to correlate to a device
        $this->correlateDevice($lease);

        // Try to link to a subnet
        if ($ip && $branchId) {
            $this->linkToSubnet($lease, $branchId);
        }
    }

    // ─── ARP / SNMP Sync ─────────────────────────────────────────

    /**
     * Sync DHCP leases from SNMP ARP table entries.
     *
     * @param array           $arpEntries  [[ip => ..., mac => ...], ...]
     * @param SophosFirewall  $firewall    Source firewall
     */
    public function syncFromArpTable(array $arpEntries, SophosFirewall $firewall): void
    {
        foreach ($arpEntries as $entry) {
            $mac = $entry['mac'] ?? null;
            $ip  = $entry['ip']  ?? null;
            if (!$mac || !$ip) continue;

            $lease = DhcpLease::updateOrCreate(
                ['mac_address' => $mac, 'source' => 'snmp'],
                [
                    'ip_address'    => $ip,
                    'source_device' => $firewall->ip,
                    'branch_id'     => $firewall->branch_id,
                    'last_seen'     => now(),
                ]
            );

            $this->correlateDevice($lease);

            if ($firewall->branch_id) {
                $this->linkToSubnet($lease, $firewall->branch_id);
            }
        }
    }

    // ─── Conflict Detection ───────────────────────────────────────

    /**
     * Detect IP conflicts: same IP assigned to multiple MACs.
     */
    public function detectConflicts(?int $branchId = null): int
    {
        // Reset previous conflict flags
        $resetQuery = DhcpLease::where('is_conflict', true);
        if ($branchId) $resetQuery->where('branch_id', $branchId);
        $resetQuery->update(['is_conflict' => false]);

        // Find IPs with multiple active MACs
        $query = DhcpLease::select('ip_address')
            ->where('last_seen', '>=', now()->subHours(24))
            ->whereNotNull('ip_address');

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $conflicts = $query->groupBy('ip_address')
            ->havingRaw('COUNT(DISTINCT mac_address) > 1')
            ->pluck('ip_address');

        $conflictCount = 0;

        foreach ($conflicts as $ip) {
            $leases = DhcpLease::where('ip_address', $ip)
                ->where('last_seen', '>=', now()->subHours(24))
                ->get();

            foreach ($leases as $lease) {
                $lease->update(['is_conflict' => true]);
                $conflictCount++;
            }

            // Create NOC alert
            $macs = $leases->pluck('mac_address')->implode(', ');
            app(NocAlertEngine::class)->createOrUpdateEvent(
                'network',
                'ip_conflict',
                $ip,
                'warning',
                "IP Conflict: {$ip}",
                "IP address {$ip} is assigned to multiple MAC addresses: {$macs}"
            );
        }

        // Auto-resolve conflicts that no longer exist
        NocEvent::where('module', 'network')
            ->where('entity_type', 'ip_conflict')
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereNotIn('entity_id', $conflicts)
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $conflictCount;
    }

    // ─── Device Correlation ───────────────────────────────────────

    /**
     * Try to match a DHCP lease to a Device in inventory by MAC address.
     */
    public function correlateDevice(DhcpLease $lease): void
    {
        if ($lease->device_id) return;

        $device = Device::where('mac_address', $lease->mac_address)->first();
        if ($device) {
            $lease->update(['device_id' => $device->id]);
        }
    }

    // ─── Subnet Linking ───────────────────────────────────────────

    /**
     * Link a lease to its matching IPAM subnet.
     */
    protected function linkToSubnet(DhcpLease $lease, int $branchId): void
    {
        if ($lease->subnet_id || !$lease->ip_address) return;

        $subnet = IpamSubnet::where('branch_id', $branchId)->get()->first(function ($subnet) use ($lease) {
            return $subnet->containsIp($lease->ip_address);
        });

        if ($subnet) {
            $lease->update(['subnet_id' => $subnet->id]);
        }
    }

    // ─── Auto Subnet Creation ─────────────────────────────────────

    /**
     * Create an IPAM subnet if one doesn't already exist for this CIDR.
     */
    public function autoCreateSubnet(string $cidr, int $branchId, string $source = 'meraki'): IpamSubnet
    {
        return IpamSubnet::firstOrCreate(
            ['branch_id' => $branchId, 'cidr' => $cidr],
            [
                'source'    => $source,
                'total_ips' => $this->computeTotalFromCidr($cidr),
            ]
        );
    }

    protected function computeTotalFromCidr(string $cidr): int
    {
        $parts  = explode('/', $cidr);
        $prefix = (int) ($parts[1] ?? 24);
        $total  = pow(2, 32 - $prefix);
        return $total > 2 ? $total - 2 : $total;
    }
}
