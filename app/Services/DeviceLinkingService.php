<?php

namespace App\Services;

use App\Models\AzureDevice;
use App\Models\Device;
use Illuminate\Support\Facades\Log;

/**
 * Bidirectional linker between local Device rows (ITAM) and AzureDevice rows
 * (Intune/Entra). Called whenever either side gets a serial number — links by
 * exact serial match.
 *
 * Used by:
 *   - PurchaseOrderController@store: after a Device is materialized from a PO line.
 *   - Device update flows: when serial_number gets set/changed.
 *   - AzureDeviceService::syncDevices: indirect — `attemptAutoLink` already calls
 *     the equivalent matching path on the Azure side.
 */
class DeviceLinkingService
{
    /**
     * Link an existing AzureDevice with this Device, by serial.
     * Returns the AzureDevice if linked, null otherwise.
     */
    public function linkBySerial(Device $device): ?AzureDevice
    {
        if (empty($device->serial_number)) {
            return null;
        }

        $azDev = AzureDevice::where('serial_number', $device->serial_number)
            ->whereNull('device_id')
            ->orderByDesc('last_sync_at')
            ->first();

        if (! $azDev) {
            return null;
        }

        $status = $device->purchase_order_id ? 'linked' : 'pending';

        $azDev->update([
            'device_id' => $device->id,
            'link_status' => $status,
        ]);

        Log::info(
            "DeviceLinkingService: linked AzureDevice #{$azDev->id} ({$azDev->display_name}) ".
            "to Device #{$device->id} ({$device->name}) by serial {$device->serial_number} ".
            "[status={$status}]"
        );

        return $azDev;
    }
}
