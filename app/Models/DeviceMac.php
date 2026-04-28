<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Central MAC address registry.
 *
 * Stores every network adapter MAC for every managed asset:
 *   - Windows PCs (Intune-managed)  → azure_device_id
 *   - IP phones, switches, APs, etc. → device_id
 *
 * Used by RADIUS server for 802.1X / MAC Authentication Bypass (MAB):
 *   Query by mac_address to identify the device and grant the correct VLAN.
 *
 * @property int         $id
 * @property string      $mac_address         Normalised: AA:BB:CC:DD:EE:FF
 * @property string      $adapter_type        ethernet|wifi|usb_ethernet|management|virtual
 * @property string|null $adapter_name        OS-level friendly name
 * @property string|null $adapter_description Hardware description
 * @property int|null    $azure_device_id
 * @property int|null    $device_id
 * @property bool        $is_primary
 * @property bool        $is_active
 * @property string      $source              intune|snmp|dhcp|arp|manual|import
 * @property \Carbon\Carbon|null $last_seen_at
 * @property string|null $notes
 */
class DeviceMac extends Model
{
    protected $fillable = [
        'mac_address',
        'adapter_type',
        'adapter_name',
        'adapter_description',
        'azure_device_id',
        'device_id',
        'is_primary',
        'is_active',
        'source',
        'last_seen_at',
        'notes',
    ];

    protected $casts = [
        'is_primary'   => 'boolean',
        'is_active'    => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function azureDevice(): BelongsTo
    {
        return $this->belongsTo(AzureDevice::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function radiusOverride(): HasOne
    {
        return $this->hasOne(RadiusMacOverride::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Static helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Normalise any MAC address string to uppercase colon-separated format.
     * Accepts:  aa-bb-cc-dd-ee-ff  |  aabbccddeeff  |  AA:BB:CC:DD:EE:FF
     * Returns:  AA:BB:CC:DD:EE:FF  or null if invalid.
     */
    public static function normalizeMac(?string $mac): ?string
    {
        if (empty($mac)) return null;
        $clean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $mac));
        if (strlen($clean) !== 12) return null;
        return implode(':', str_split($clean, 2));
    }

    /**
     * Upsert a MAC record by mac_address.
     * Creates or updates; always stamps last_seen_at.
     */
    public static function upsertMac(string $rawMac, array $attributes): ?static
    {
        $mac = static::normalizeMac($rawMac);
        if (!$mac) return null;

        return static::updateOrCreate(
            ['mac_address' => $mac],
            array_merge($attributes, ['last_seen_at' => now()])
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Presentation helpers
    // ─────────────────────────────────────────────────────────────

    public function adapterTypeBadge(): string
    {
        return match ($this->adapter_type) {
            'ethernet'     => 'primary',
            'wifi'         => 'info',
            'usb_ethernet' => 'warning',
            'management'   => 'secondary',
            'virtual'      => 'light',
            default        => 'secondary',
        };
    }

    public function adapterTypeLabel(): string
    {
        return match ($this->adapter_type) {
            'ethernet'     => 'Ethernet',
            'wifi'         => 'Wi-Fi',
            'usb_ethernet' => 'USB Ethernet',
            'management'   => 'Management',
            'virtual'      => 'Virtual',
            default        => ucfirst($this->adapter_type),
        };
    }

    public function sourceBadge(): string
    {
        return match ($this->source) {
            'intune' => 'success',
            'snmp'   => 'info',
            'dhcp'   => 'warning',
            'arp'    => 'secondary',
            'manual' => 'light',
            'import' => 'dark',
            default  => 'secondary',
        };
    }
}
