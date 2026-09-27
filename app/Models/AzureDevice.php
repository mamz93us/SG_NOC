<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AzureDevice extends Model
{
    protected $fillable = [
        'azure_device_id', 'intune_managed_device_id', 'display_name', 'device_type', 'os', 'os_version',
        'upn', 'serial_number', 'manufacturer', 'model', 'enrolled_date', 'last_sync_at',
        'last_activity_at', 'device_id', 'link_status', 'raw_data',
        // When Microsoft stopped holding it — see the 2026_09_20 migration.
        'intune_removed_at', 'removed_at',
        // Net data — populated by intune:sync-net-data (NOC-DeviceInfo.ps1)
        'teamviewer_id', 'tv_version', 'cpu_name',
        'wifi_mac', 'ethernet_mac', 'usb_eth_data', 'net_data_synced_at',
    ];

    protected $casts = [
        'enrolled_date'      => 'datetime',
        'last_sync_at'       => 'datetime',
        'last_activity_at'   => 'datetime',
        'net_data_synced_at' => 'datetime',
        'intune_removed_at'  => 'datetime',
        'removed_at'         => 'datetime',
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

    // ─────────────────────────────────────────────────────────────
    // What Microsoft still holds
    // ─────────────────────────────────────────────────────────────

    /** Rows Microsoft still lists. A removed row is kept as the asset's trace. */
    public function scopePresent(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    public function scopeRemoved(Builder $query): Builder
    {
        return $query->whereNotNull('removed_at');
    }

    /**
     * Linked to an ITAM asset **and** still managed by Intune.
     *
     * `link_status = 'linked'` alone is not that. Deleting a device from Intune
     * — which is what offboarding does — leaves the Entra device object behind,
     * so the row stays linked while Intune no longer holds it, and the asset
     * went on reading as enrolled for good. Every "in Intune" list goes through
     * this one definition.
     */
    public function scopeInIntune(Builder $query): Builder
    {
        return $query->where('link_status', 'linked')
            ->whereNull('removed_at')
            ->whereNull('intune_removed_at')
            ->whereNotNull('intune_managed_device_id');
    }

    public function isInIntune(): bool
    {
        return $this->link_status === 'linked'
            && $this->removed_at === null
            && $this->intune_removed_at === null
            && $this->intune_managed_device_id !== null;
    }

    /** What became of it in Microsoft, for the device page. Null while all is well. */
    public function microsoftStateLabel(): ?string
    {
        if ($this->removed_at) {
            return 'Not in Microsoft since '.$this->removed_at->format('d M Y');
        }

        if ($this->intune_removed_at) {
            return 'Left Intune on '.$this->intune_removed_at->format('d M Y');
        }

        if (! $this->intune_managed_device_id) {
            return 'In Entra, not enrolled in Intune';
        }

        return null;
    }
}
