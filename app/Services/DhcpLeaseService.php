<?php

namespace App\Services;

use App\Models\Device;
use App\Models\DhcpLease;
use App\Models\FortigateFirewall;
use App\Models\IpamSubnet;
use App\Models\NocEvent;
use App\Models\SophosFirewall;
use App\Services\Network\WifiMacDirectory;

class DhcpLeaseService
{
    public function __construct(protected WifiMacDirectory $wifiMacs) {}

    // ─── Network Labelling ────────────────────────────────────────

    /**
     * The network / SSID label to stamp on a lease from this firewall.
     *
     * A firewall serves both wired and wireless clients. When its label is a
     * Wi-Fi SSID (`label_wifi_only`), only clients whose MAC is a known Wi-Fi
     * adapter get labelled — everything else is left unlabelled rather than
     * being wrongly reported as on the wireless network.
     *
     * @param  SophosFirewall|FortigateFirewall  $firewall
     */
    public function labelFor($firewall, ?string $mac): ?string
    {
        $label = $firewall->network_label ?: null;

        if ($label === null || ! ($firewall->label_wifi_only ?? false)) {
            return $label;
        }

        return $this->wifiMacs->isWifi($mac) ? $label : null;
    }

    // ─── Meraki Sync ──────────────────────────────────────────────

    /**
     * Create/update DHCP leases from Meraki client data.
     *
     * @param  array  $clientData  Raw client array from Meraki API
     * @param  string  $switchSerial  Switch that reported this client
     * @param  int|null  $branchId  Branch of the reporting switch
     */
    public function syncFromMeraki(array $clientData, string $switchSerial, ?int $branchId = null): void
    {
        $mac = $clientData['mac'] ?? null;
        if (! $mac) {
            return;
        }

        $ip = $clientData['ip'] ?? null;

        $lease = DhcpLease::updateOrCreate(
            ['mac_address' => $mac, 'source' => 'meraki'],
            [
                'ip_address' => $ip,
                'hostname' => $clientData['description'] ?? $clientData['dhcpHostname'] ?? null,
                'vendor' => $clientData['manufacturer'] ?? null,
                'vlan' => $clientData['vlan'] ?? null,
                'source_device' => $switchSerial,
                'switch_serial' => $switchSerial,
                'port_id' => $clientData['switchport'] ?? null,
                'branch_id' => $branchId,
                'last_seen' => now(),
            ]
        );

        // Try to correlate to a device
        $this->correlateDevice($lease);

        // Try to link to a subnet
        if ($ip && $branchId) {
            $this->linkToSubnet($lease, $branchId);
        }
    }

    // ─── FortiGate Sync ──────────────────────────────────────────

    /**
     * Create/update DHCP leases from a FortiGate `monitor/system/dhcp` payload.
     *
     * Each entry looks like:
     *   {"ip":"192.168.100.137","mac":"3c:9c:0f:34:85:e1","reserved":false,
     *    "vci":"MSFT 5.0","hostname":"J-AIbrahim","expire_time":1786262394,
     *    "status":"leased","interface":"internal","type":"ipv4", ...}
     *
     * @param  array<int, array<string, mixed>>  $entries  Raw `results` array from the API
     * @return int Number of leases created or updated
     */
    public function syncFromFortiGate(array $entries, FortigateFirewall $firewall): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            $mac = $entry['mac'] ?? null;
            $ip = $entry['ip'] ?? null;
            if (! $mac || ! $ip) {
                continue;
            }

            // Skip anything that isn't an IPv4 lease we can act on.
            if (($entry['type'] ?? 'ipv4') !== 'ipv4') {
                continue;
            }

            $expire = $entry['expire_time'] ?? null;

            $lease = DhcpLease::updateOrCreate(
                [
                    'mac_address' => strtolower($mac),
                    'source' => 'fortigate',
                    'source_device' => $firewall->ip,
                ],
                [
                    'ip_address' => $ip,
                    'hostname' => $entry['hostname'] ?? null,
                    'vendor' => $entry['vci'] ?? null,
                    'interface' => $entry['interface'] ?? null,
                    'is_reserved' => (bool) ($entry['reserved'] ?? false),
                    'network_label' => $this->labelFor($firewall, $mac),
                    'branch_id' => $firewall->branch_id,
                    'lease_end' => $expire ? \Carbon\CarbonImmutable::createFromTimestamp($expire) : null,
                    'last_seen' => now(),
                ]
            );

            $this->correlateDevice($lease);

            if ($firewall->branch_id) {
                $this->linkToSubnet($lease, $firewall->branch_id);
            }

            $count++;
        }

        return $count;
    }

    // ─── ARP / SNMP Sync ─────────────────────────────────────────

    /**
     * Sync DHCP leases from SNMP ARP table entries.
     *
     * @param  array  $arpEntries  [[ip => ..., mac => ...], ...]
     * @param  SophosFirewall  $firewall  Source firewall
     */
    public function syncFromArpTable(array $arpEntries, SophosFirewall $firewall): void
    {
        foreach ($arpEntries as $entry) {
            $mac = $entry['mac'] ?? null;
            $ip = $entry['ip'] ?? null;
            if (! $mac || ! $ip) {
                continue;
            }

            $lease = DhcpLease::updateOrCreate(
                ['mac_address' => $mac, 'source' => 'snmp'],
                [
                    'ip_address' => $ip,
                    'source_device' => $firewall->ip,
                    'network_label' => $this->labelFor($firewall, $mac),
                    'branch_id' => $firewall->branch_id,
                    'last_seen' => now(),
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
     * How recently a lease must have been seen to count as "present right now".
     *
     * A real conflict means two devices hold the address *simultaneously*. The
     * feeds refresh every 5–10 min (Meraki 5, FortiGate 10, ARP 10), so this
     * covers two cycles of the slowest one. A wider window turns ordinary DHCP
     * churn into false conflicts: a phone with MAC randomisation reconnects,
     * takes the address its previous identity just released, and the released
     * row is still sitting there with its old IP.
     */
    public const CONFLICT_WINDOW_MINUTES = 20;

    /**
     * Detect IP conflicts: one address held by multiple MACs at the same time,
     * within a single L3 domain.
     *
     * Scoped per branch on purpose — every branch runs its own RFC1918 space,
     * so `192.168.1.1` legitimately exists at JED, RYD, KBR and ABH at once.
     * Grouping on the address alone reported all of them as one conflict.
     */
    public function detectConflicts(?int $branchId = null, ?int $windowMinutes = null): int
    {
        $window = $windowMinutes ?? self::CONFLICT_WINDOW_MINUTES;
        $since = now()->subMinutes($window);

        // Reset previous conflict flags
        $resetQuery = DhcpLease::where('is_conflict', true);
        if ($branchId) {
            $resetQuery->where('branch_id', $branchId);
        }
        $resetQuery->update(['is_conflict' => false]);

        // Candidate leases: currently present, real addresses only.
        // 169.254.0.0/16 is APIPA — self-assigned when DHCP fails, so several
        // unrelated hosts landing on the same one carries no signal.
        $candidates = fn () => DhcpLease::query()
            ->where('last_seen', '>=', $since)
            ->whereNotNull('ip_address')
            ->where('ip_address', 'not like', '169.254.%')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $groups = $candidates()
            ->selectRaw('branch_id, ip_address')
            ->groupBy('branch_id', 'ip_address')
            ->havingRaw('COUNT(DISTINCT mac_address) > 1')
            ->get();

        $conflictCount = 0;
        $activeKeys = [];

        foreach ($groups as $group) {
            $leases = $candidates()
                ->with('branch')
                ->where('ip_address', $group->ip_address)
                ->when(
                    $group->branch_id === null,
                    fn ($q) => $q->whereNull('branch_id'),
                    fn ($q) => $q->where('branch_id', $group->branch_id)
                )
                ->get();

            DhcpLease::whereIn('id', $leases->pluck('id'))->update(['is_conflict' => true]);
            $conflictCount += $leases->count();

            // Branch is part of the identity now, so two branches sharing an
            // address raise two independent events instead of colliding.
            $key = ($group->branch_id ?? '-').':'.$group->ip_address;
            $activeKeys[] = $key;

            $branchName = $leases->first()?->branch?->name;
            $scope = $branchName ? " at {$branchName}" : '';
            $macs = $leases->pluck('mac_address')->implode(', ');

            app(NocAlertEngine::class)->createOrUpdateEvent(
                'network',
                'ip_conflict',
                $key,
                'warning',
                "IP Conflict: {$group->ip_address}{$scope}",
                "IP address {$group->ip_address}{$scope} is held by multiple MAC addresses "
                ."within the last {$window} minutes: {$macs}"
            );
        }

        // Auto-resolve conflicts that no longer exist. Events raised under the
        // old address-only entity_id never match a key and so resolve here too.
        NocEvent::where('module', 'network')
            ->where('entity_type', 'ip_conflict')
            ->whereIn('status', ['open', 'acknowledged'])
            ->whereNotIn('entity_id', $activeKeys)
            ->update(['status' => 'resolved', 'resolved_at' => now()]);

        return $conflictCount;
    }

    // ─── Device Correlation ───────────────────────────────────────

    /**
     * Try to match a DHCP lease to a Device in inventory by MAC address.
     */
    public function correlateDevice(DhcpLease $lease): void
    {
        if ($lease->device_id) {
            return;
        }

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
        if ($lease->subnet_id || ! $lease->ip_address) {
            return;
        }

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
                'source' => $source,
                'total_ips' => $this->computeTotalFromCidr($cidr),
            ]
        );
    }

    protected function computeTotalFromCidr(string $cidr): int
    {
        $parts = explode('/', $cidr);
        $prefix = (int) ($parts[1] ?? 24);
        $total = pow(2, 32 - $prefix);

        return $total > 2 ? $total - 2 : $total;
    }
}
