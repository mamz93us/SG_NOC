<?php

namespace App\Services\Network;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\MonitoredHost;
use App\Models\NetworkSwitch;
use App\Services\AssetCodeService;
use Illuminate\Support\Facades\Log;

/**
 * Reconciles switch identity across the four inventory sources:
 *   - Meraki     → network_switches
 *   - Assets     → devices (type=switch, authoritative)
 *   - SNMP       → monitored_hosts
 *   - QoS        → switch_qos_stats (already FK'd to devices)
 *
 * Rules:
 *   - `devices` is the canonical identity.
 *   - Any new switch discovered in Meraki or SNMP auto-creates a device row
 *     (idempotent via source+source_id unique key).
 *   - Meraki switches are back-linked with device_id.
 *   - SNMP hosts matching a switch are back-linked (and if missing, an SNMP
 *     stub row is created with snmp_enabled=false so nothing polls half-configured).
 *
 * Matching cascade (when no explicit FK yet):
 *   1. serial / serial_number
 *   2. mac_address (normalized)
 *   3. ip_address
 *   4. (name + branch_id)
 */
class SwitchReconciler
{
    /**
     * Ensure every Meraki NetworkSwitch has a corresponding Device,
     * and a corresponding MonitoredHost stub. Returns counts.
     */
    public function reconcileAll(): array
    {
        $created = ['devices' => 0, 'monitored_hosts' => 0];
        $linked  = ['meraki' => 0, 'snmp' => 0];

        NetworkSwitch::query()->chunkById(100, function ($batch) use (&$created, &$linked) {
            foreach ($batch as $sw) {
                $before = ['device_id' => $sw->device_id];
                $device = $this->ensureDeviceForMerakiSwitch($sw);
                if (!$device) {
                    continue;
                }

                if ($sw->device_id !== $device->id) {
                    $sw->forceFill(['device_id' => $device->id])->save();
                    $linked['meraki']++;
                }
                if ($device->wasRecentlyCreated) {
                    $created['devices']++;
                }

                $host = $this->ensureMonitoredHostForDevice($device);
                if ($host?->wasRecentlyCreated) {
                    $created['monitored_hosts']++;
                }
                if ($host && $host->device_id !== $device->id) {
                    $host->forceFill(['device_id' => $device->id])->save();
                    $linked['snmp']++;
                }
            }
        });

        // Second pass: link any existing MonitoredHost rows whose IP/MAC
        // matches a Device (no FK yet).
        MonitoredHost::whereNull('device_id')->chunkById(100, function ($hosts) use (&$linked) {
            foreach ($hosts as $host) {
                $device = $this->matchDevice(
                    serial: null,
                    mac: null,
                    ip: $host->ip,
                    name: $host->name,
                    branchId: $host->branch_id,
                );
                if ($device && in_array($device->type, ['switch', 'router', 'firewall'])) {
                    $host->forceFill(['device_id' => $device->id])->save();
                    $linked['snmp']++;
                }
            }
        });

        return ['created' => $created, 'linked' => $linked];
    }

    /**
     * Find-or-create a Device for a Meraki NetworkSwitch row.
     */
    public function ensureDeviceForMerakiSwitch(NetworkSwitch $sw): Device
    {
        // Explicit source+source_id lookup first (idempotent).
        $device = Device::firstOrNew(
            ['source' => 'meraki', 'source_id' => $sw->serial],
        );

        // If a manual device exists that matches (serial/MAC/IP/name+branch)
        // but isn't yet owned by Meraki, adopt it instead of creating a dup.
        if (!$device->exists) {
            $match = $this->matchDevice(
                serial: $sw->serial,
                mac:    $sw->mac,
                ip:     $sw->lan_ip,
                name:   $sw->name,
                branchId: $sw->branch_id,
            );

            if ($match) {
                $match->forceFill([
                    'source'    => 'meraki',
                    'source_id' => $sw->serial,
                ]);
                $device = $match;
            }
        }

        $device->fill([
            'type'          => 'switch',
            'name'          => $device->name ?: ($sw->name ?: $sw->serial),
            'model'         => $device->model ?: $sw->model,
            'serial_number' => $device->serial_number ?: $sw->serial,
            'mac_address'   => $device->mac_address ?: $sw->mac,
            'ip_address'    => $sw->lan_ip ?: $device->ip_address,
            'branch_id'     => $device->branch_id ?: $sw->branch_id,
            'source'        => 'meraki',
            'source_id'     => $sw->serial,
            'status'        => $device->status ?: 'active',
        ]);

        // Stamp an asset_code if the device is missing one — sequential per
        // type (SG-SW-000001, …). Done for both brand-new rows and existing
        // rows that pre-date asset-code enforcement.
        if (empty($device->asset_code)) {
            try {
                $device->asset_code = app(AssetCodeService::class)->generate('switch');
            } catch (\Throwable $e) {
                Log::warning('SwitchReconciler: asset code generation failed: ' . $e->getMessage());
            }
        }

        $wasNew = !$device->exists;
        $device->save();

        if ($wasNew) {
            ActivityLog::create([
                'model_type' => Device::class,
                'model_id'   => $device->id,
                'action'     => 'device_auto_created_from_meraki',
                'changes'    => ['serial' => $sw->serial, 'switch_id' => $sw->id],
                'user_id'    => null,
            ]);
        }

        return $device;
    }

    /**
     * Find-or-create a MonitoredHost stub for a Device (switch/router/firewall).
     * The stub starts with snmp_enabled=false so no polling happens until
     * an operator supplies community/credentials.
     */
    public function ensureMonitoredHostForDevice(Device $device): ?MonitoredHost
    {
        if (!in_array($device->type, ['switch', 'router', 'firewall'])) {
            return null;
        }

        if (!$device->ip_address) {
            return null;
        }

        $host = MonitoredHost::where('device_id', $device->id)->first()
            ?? MonitoredHost::where('ip', $device->ip_address)->first();

        if (!$host) {
            $host = new MonitoredHost();
            $host->forceFill([
                'device_id'             => $device->id,
                'branch_id'             => $device->branch_id,
                'name'                  => $device->name,
                'ip'                    => $device->ip_address,
                'type'                  => $device->type,
                'ping_enabled'          => true,
                'ping_interval_seconds' => 60,
                'ping_packet_count'     => 3,
                'snmp_enabled'          => false,
                'snmp_version'          => '2c',
                'snmp_port'             => 161,
                'alert_enabled'         => false,
                'status'                => 'unknown',
            ]);
            $host->save();

            ActivityLog::create([
                'model_type' => MonitoredHost::class,
                'model_id'   => $host->id,
                'action'     => 'monitored_host_auto_created',
                'changes'    => ['device_id' => $device->id, 'reason' => 'switch_reconciler'],
                'user_id'    => null,
            ]);
        }

        return $host;
    }

    /**
     * Attempt to match an existing Device by the identity cascade:
     * serial → MAC → IP → name+branch. Returns null if nothing matches.
     */
    public function matchDevice(
        ?string $serial,
        ?string $mac,
        ?string $ip,
        ?string $name,
        ?int    $branchId,
    ): ?Device {
        if ($serial) {
            $found = Device::where('serial_number', $serial)->first();
            if ($found) return $found;
        }

        if ($mac) {
            $normalized = $this->normalizeMac($mac);
            if ($normalized) {
                $found = Device::whereRaw(
                    "UPPER(REPLACE(REPLACE(REPLACE(mac_address,':',''),'-',''),'.','')) = ?",
                    [$normalized]
                )->first();
                if ($found) return $found;
            }
        }

        if ($ip) {
            $found = Device::where('ip_address', $ip)
                ->whereIn('type', ['switch', 'router', 'firewall'])
                ->first();
            if ($found) return $found;
        }

        if ($name && $branchId) {
            $found = Device::where('name', $name)
                ->where('branch_id', $branchId)
                ->first();
            if ($found) return $found;
        }

        return null;
    }

    private function normalizeMac(?string $mac): ?string
    {
        if (!$mac) return null;
        $clean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        return strlen($clean) === 12 ? $clean : null;
    }
}
