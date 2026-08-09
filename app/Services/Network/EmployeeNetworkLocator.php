<?php

namespace App\Services\Network;

use App\Models\AzureDevice;
use App\Models\DeviceMac;
use App\Models\DhcpLease;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * "Where was this employee's kit last seen on the network?"
 *
 * Walks every MAC we know for an employee — assigned ITAM assets, their
 * Intune-managed devices, and anything in the central MAC registry — and pairs
 * each with its most recent DHCP lease. The lease is what carries the location:
 * branch, network/SSID, and the switch port or firewall interface it came in on.
 */
class EmployeeNetworkLocator
{
    /**
     * @return Collection<int, object{
     *     label: string, mac: string, device_name: ?string,
     *     device_id: ?int, lease: ?DhcpLease
     * }>
     */
    public function locate(Employee $employee): Collection
    {
        $adapters = $this->adaptersFor($employee);

        if ($adapters->isEmpty()) {
            return collect();
        }

        $leases = $this->latestLeaseByMac($adapters->keys()->all());

        return $adapters
            ->map(fn (array $a, string $mac) => (object) [
                'label' => $a['label'],
                'mac' => $mac,
                'device_name' => $a['device_name'],
                'device_id' => $a['device_id'],
                'lease' => $leases[$mac] ?? null,
            ])
            ->values()
            // Seen-recently first, never-seen last.
            ->sortByDesc(fn ($row) => $row->lease?->last_seen?->timestamp ?? 0)
            ->values();
    }

    /**
     * Every MAC belonging to this employee, keyed by normalised MAC.
     *
     * @return Collection<string, array{label: string, device_name: ?string, device_id: ?int}>
     */
    protected function adaptersFor(Employee $employee): Collection
    {
        $adapters = collect();

        $put = function (?string $rawMac, string $label, ?string $deviceName, ?int $deviceId) use ($adapters) {
            $mac = DeviceMac::normalizeMac($rawMac);
            // First writer wins: ITAM assets are added before the registry, so
            // a MAC we already have a friendly name for keeps it.
            if ($mac !== null && ! $adapters->has($mac)) {
                $adapters->put($mac, [
                    'label' => $label,
                    'device_name' => $deviceName,
                    'device_id' => $deviceId,
                ]);
            }
        };

        // 1. Assigned ITAM assets
        foreach ($employee->activeAssets as $assignment) {
            $device = $assignment->device;
            if (! $device) {
                continue;
            }

            $name = $device->name ?: $device->asset_code;
            $put($device->mac_address, 'Ethernet', $name, $device->id);
            $put($device->wifi_mac, 'Wi-Fi', $name, $device->id);
        }

        // 2. Intune-managed devices matched by UPN
        $upn = $employee->identityUser?->user_principal_name ?: $employee->email;
        $azureDevices = $upn
            ? AzureDevice::where('upn', $upn)->get()
            : collect();

        foreach ($azureDevices as $dev) {
            $name = $dev->display_name ?: $dev->model;
            $put($dev->wifi_mac, 'Wi-Fi', $name, $dev->device_id);
            $put($dev->ethernet_mac, 'Ethernet', $name, $dev->device_id);

            foreach ($dev->usb_eth_decoded() as $nic) {
                $put($nic['mac'] ?? null, 'USB Ethernet', $name, $dev->device_id);
            }
        }

        // 3. Anything else the MAC registry holds against those devices
        $deviceIds = $employee->activeAssets->pluck('device_id')->filter()->all();
        $azureIds = $azureDevices->pluck('id')->all();

        if ($deviceIds || $azureIds) {
            DeviceMac::query()
                ->where('is_active', true)
                ->where(function ($q) use ($deviceIds, $azureIds) {
                    if ($deviceIds) {
                        $q->orWhereIn('device_id', $deviceIds);
                    }
                    if ($azureIds) {
                        $q->orWhereIn('azure_device_id', $azureIds);
                    }
                })
                ->with('device')
                ->get()
                ->each(fn (DeviceMac $m) => $put(
                    $m->mac_address,
                    $m->adapterTypeLabel(),
                    $m->device?->name,
                    $m->device_id
                ));
        }

        return $adapters;
    }

    /**
     * Most recent lease per MAC, keyed by normalised MAC.
     *
     * @param  array<int, string>  $macs
     * @return array<string, DhcpLease>
     */
    protected function latestLeaseByMac(array $macs): array
    {
        if (! $macs) {
            return [];
        }

        // MAC columns are stored lower-case here and upper-case in the registry;
        // the collation is case-insensitive, so one whereIn covers both.
        $leases = DhcpLease::query()
            ->whereIn('mac_address', $macs)
            ->with(['branch', 'networkSwitch', 'subnet'])
            ->orderByDesc('last_seen')
            ->get();

        $byMac = [];
        foreach ($leases as $lease) {
            $key = DeviceMac::normalizeMac($lease->mac_address);
            // Ordered newest-first, so the first hit per MAC is the latest.
            if ($key !== null && ! isset($byMac[$key])) {
                $byMac[$key] = $lease;
            }
        }

        return $byMac;
    }
}
