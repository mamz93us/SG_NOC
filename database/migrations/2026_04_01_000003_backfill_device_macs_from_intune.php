<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill devices.mac_address and devices.wifi_mac from Intune-synced data
 * in azure_devices for all linked devices that currently have no MAC address.
 *
 * Only updates rows where:
 *   - devices.mac_address IS NULL  (don't overwrite manually entered MACs)
 *   - azure_devices.ethernet_mac IS NOT NULL
 *   - azure_devices.net_data_synced_at IS NOT NULL (Intune data present)
 *   - azure_devices.device_id = devices.id (linked)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fetch all azure_devices that have Intune HW data and are linked to an ITAM device
        $rows = DB::table('azure_devices')
            ->whereNotNull('device_id')
            ->whereNotNull('net_data_synced_at')
            ->where(fn ($q) => $q->whereNotNull('ethernet_mac')->orWhereNotNull('wifi_mac'))
            ->get(['device_id', 'ethernet_mac', 'wifi_mac']);

        foreach ($rows as $row) {
            $update = [];

            // Only write ethernet_mac → mac_address if device has none
            if ($row->ethernet_mac) {
                $exists = DB::table('devices')
                    ->where('id', $row->device_id)
                    ->whereNull('mac_address')
                    ->exists();
                if ($exists) {
                    $update['mac_address'] = strtoupper(
                        implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $row->ethernet_mac)), 2))
                    );
                }
            }

            // Only write wifi_mac → wifi_mac if device has none
            if ($row->wifi_mac) {
                $wifiExists = DB::table('devices')
                    ->where('id', $row->device_id)
                    ->whereNull('wifi_mac')
                    ->exists();
                if ($wifiExists) {
                    $update['wifi_mac'] = strtoupper(
                        implode(':', str_split(strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $row->wifi_mac)), 2))
                    );
                }
            }

            if (!empty($update)) {
                DB::table('devices')->where('id', $row->device_id)->update($update);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive — cannot safely reverse without knowing which were backfilled
    }
};
