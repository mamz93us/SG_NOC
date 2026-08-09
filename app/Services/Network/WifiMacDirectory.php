<?php

namespace App\Services\Network;

use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\DeviceMac;
use Illuminate\Support\Facades\DB;

/**
 * Answers one question: "is this MAC a Wi-Fi adapter on a managed device?"
 *
 * Neither the Sophos nor the FortiGate API tells us whether a DHCP client is
 * wired or wireless, so the only reliable signal we have is the adapter type
 * recorded against the MAC when the device was synced from Intune.
 *
 * Sources, in order of authority:
 *   1. device_macs where adapter_type = 'wifi'  — the central MAC registry
 *      that intune:sync-net-data (and the bulk import) writes into
 *   2. azure_devices.wifi_mac                   — raw Intune net-data column
 *   3. devices.wifi_mac                         — ITAM assets (IP phones, etc.)
 *
 * The whole set is loaded once and held in memory: a lease sync compares
 * thousands of MACs, and a per-MAC query would be thousands of round-trips.
 */
class WifiMacDirectory
{
    /** @var array<string, true>|null Normalised MAC => true */
    protected ?array $wifiMacs = null;

    /**
     * Load (once) every known Wi-Fi MAC, normalised to AA:BB:CC:DD:EE:FF.
     *
     * @return array<string, true>
     */
    public function all(): array
    {
        if ($this->wifiMacs !== null) {
            return $this->wifiMacs;
        }

        $set = [];

        $add = function (?string $mac) use (&$set) {
            $norm = DeviceMac::normalizeMac($mac);
            if ($norm !== null) {
                $set[$norm] = true;
            }
        };

        DeviceMac::query()
            ->where('adapter_type', 'wifi')
            ->where('is_active', true)
            ->pluck('mac_address')
            ->each($add);

        AzureDevice::query()
            ->whereNotNull('wifi_mac')
            ->where('wifi_mac', '!=', '')
            ->pluck('wifi_mac')
            ->each($add);

        if (DB::getSchemaBuilder()->hasColumn('devices', 'wifi_mac')) {
            Device::query()
                ->whereNotNull('wifi_mac')
                ->where('wifi_mac', '!=', '')
                ->pluck('wifi_mac')
                ->each($add);
        }

        return $this->wifiMacs = $set;
    }

    /**
     * Is this MAC a known Wi-Fi adapter?
     */
    public function isWifi(?string $mac): bool
    {
        $norm = DeviceMac::normalizeMac($mac);

        return $norm !== null && isset($this->all()[$norm]);
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Drop the cached set — call after a sync that may have added Wi-Fi MACs.
     */
    public function flush(): void
    {
        $this->wifiMacs = null;
    }
}
