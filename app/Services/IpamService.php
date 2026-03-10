<?php

namespace App\Services;

use App\Models\DhcpLease;
use App\Models\IpamSubnet;
use App\Models\IpReservation;
use App\Models\NetworkClient;
use Illuminate\Support\Collection;

class IpamService
{
    // ─── Subnet Tree ──────────────────────────────────────────────

    /**
     * Get all subnets grouped by branch and optionally VLAN.
     */
    public function getSubnetTree(?int $branchId = null): Collection
    {
        $query = IpamSubnet::with('branch')
            ->withCount(['ipReservations', 'dhcpLeases']);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->orderBy('branch_id')
            ->orderBy('vlan')
            ->orderBy('cidr')
            ->get()
            ->groupBy(fn($s) => $s->branch->name ?? 'Unassigned');
    }

    // ─── Find Subnet for IP ───────────────────────────────────────

    /**
     * Find the matching IPAM subnet for a given IP address.
     */
    public function findSubnetForIp(string $ip, ?int $branchId = null): ?IpamSubnet
    {
        $query = IpamSubnet::query();
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        return $query->get()->first(function (IpamSubnet $subnet) use ($ip) {
            return $subnet->containsIp($ip);
        });
    }

    // ─── IP Grid ──────────────────────────────────────────────────

    /**
     * Build a visual IP grid for a subnet.
     * Returns array of ['ip' => ..., 'status' => ..., 'device' => ..., 'mac' => ...]
     */
    public function getIpGrid(IpamSubnet $subnet): array
    {
        $allIps = $subnet->allIps();

        // Preload reservations and leases for this subnet
        $reservations = IpReservation::where('subnet_id', $subnet->id)
            ->orWhere(function ($q) use ($subnet) {
                $q->where('branch_id', $subnet->branch_id)
                  ->whereIn('ip_address', $allIps);
            })
            ->get()
            ->keyBy('ip_address');

        $leases = DhcpLease::whereIn('ip_address', $allIps)
            ->where(function ($q) use ($subnet) {
                $q->where('subnet_id', $subnet->id)
                  ->orWhere('branch_id', $subnet->branch_id);
            })
            ->where('last_seen', '>=', now()->subHours(24))
            ->get()
            ->keyBy('ip_address');

        $grid = [];
        foreach ($allIps as $ip) {
            $reservation = $reservations->get($ip);
            $lease       = $leases->get($ip);

            if ($reservation && $reservation->status === 'conflict') {
                $status = 'conflict';
            } elseif ($lease && $lease->is_conflict) {
                $status = 'conflict';
            } elseif ($reservation) {
                $status = $reservation->status ?: 'static';
            } elseif ($lease) {
                $status = 'dhcp';
            } else {
                $status = 'available';
            }

            $grid[] = [
                'ip'          => $ip,
                'status'      => $status,
                'device_name' => $reservation?->device_name ?? $lease?->hostname ?? null,
                'mac'         => $reservation?->mac_address ?? $lease?->mac_address ?? null,
                'device_type' => $reservation?->device_type ?? null,
                'vlan'        => $reservation?->vlan ?? $lease?->vlan ?? null,
                'source'      => $lease?->source ?? $reservation?->source ?? null,
            ];
        }

        return $grid;
    }

    // ─── Auto-Assign IP ───────────────────────────────────────────

    /**
     * Find the next available IP in a subnet.
     */
    public function autoAssignIp(IpamSubnet $subnet): ?string
    {
        $allIps = $subnet->allIps();

        $usedIps = collect()
            ->merge(IpReservation::where('subnet_id', $subnet->id)->pluck('ip_address'))
            ->merge(DhcpLease::where('subnet_id', $subnet->id)
                ->where('last_seen', '>=', now()->subHours(24))
                ->pluck('ip_address'))
            ->unique()
            ->toArray();

        foreach ($allIps as $ip) {
            if (!in_array($ip, $usedIps)) {
                return $ip;
            }
        }

        return null;
    }

    // ─── Global Search ────────────────────────────────────────────

    /**
     * Search across IP reservations, DHCP leases, and network clients.
     */
    public function searchGlobal(string $query): array
    {
        $term = '%' . $query . '%';

        $reservations = IpReservation::with('branch')
            ->where('ip_address', 'like', $term)
            ->orWhere('mac_address', 'like', $term)
            ->orWhere('device_name', 'like', $term)
            ->orWhere('assigned_to', 'like', $term)
            ->limit(20)
            ->get();

        $leases = DhcpLease::with('branch')
            ->where('ip_address', 'like', $term)
            ->orWhere('mac_address', 'like', $term)
            ->orWhere('hostname', 'like', $term)
            ->limit(20)
            ->get();

        $clients = NetworkClient::with('networkSwitch')
            ->where('ip', 'like', $term)
            ->orWhere('mac', 'like', $term)
            ->orWhere('hostname', 'like', $term)
            ->limit(20)
            ->get();

        return [
            'reservations' => $reservations,
            'leases'       => $leases,
            'clients'      => $clients,
        ];
    }
}
