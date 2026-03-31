<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AzureDevice extends Model
{
    protected $fillable = [
        'azure_device_id', 'display_name', 'device_type', 'os', 'os_version',
        'upn', 'serial_number', 'manufacturer', 'model', 'enrolled_date', 'last_sync_at',
        'last_activity_at', 'device_id', 'link_status', 'raw_data',
        // Net data — populated by intune:sync-net-data (NOC-DeviceInfo.ps1)
        'teamviewer_id', 'tv_version', 'cpu_name',
        'wifi_mac', 'ethernet_mac', 'usb_eth_data', 'net_data_synced_at',
    ];

    protected $casts = [
        'enrolled_date'      => 'datetime',
        'last_sync_at'       => 'datetime',
        'last_activity_at'   => 'datetime',
        'net_data_synced_at' => 'datetime',
        'raw_data'           => 'array',
    ];

    const LINK_STATUSES = ['unlinked', 'linked', 'pending', 'rejected'];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function macs(): HasMany
    {
        return $this->hasMany(DeviceMac::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Net data helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Decode the usb_eth_data JSON column.
     * Each item has: name, mac, desc.
     *
     * @return array<int, array{name: string, mac: string, desc: string}>
     */
    public function usb_eth_decoded(): array
    {
        return json_decode($this->usb_eth_data ?? 'null', true) ?? [];
    }

    // ─────────────────────────────────────────────────────────────
    // Link status helpers
    // ─────────────────────────────────────────────────────────────

    public function linkStatusBadgeClass(): string
    {
        return match ($this->link_status) {
            'linked'   => 'success',
            'pending'  => 'warning',
            'rejected' => 'danger',
            default    => 'secondary',
        };
    }

    public function linkStatusLabel(): string
    {
        return ucfirst($this->link_status);
    }
}
