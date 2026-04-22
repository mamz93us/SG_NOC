<?php

namespace App\Models;

use App\Models\AssetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Services\DepreciationService;

class Device extends Model
{
    protected $fillable = [
        'type',
        'name',
        'manufacturer',
        'model',
        'device_model_id',
        'serial_number',
        'mac_address',
        'wifi_mac',
        'ip_address',
        'branch_id',
        'floor_id',
        'office_id',
        'department_id',
        'location_description',
        'notes',
        'source',
        'source_id',
        'status',
        'purchase_date',
        'warranty_expiry',
        'firmware_version',
        'latest_firmware',
        // ── ITAM ──────────────────────────────────────────────────────
        'asset_code',
        'purchase_cost',
        'supplier_id',
        'condition',
        'depreciation_method',
        'depreciation_years',
        'current_value',
        // ── Web & SSH proxy ───────────────────────────────────────────────
        'proxy_enabled',
        'web_protocol',
        'web_port',
        'web_path',
        'proxy_legacy_tls',
        'ssh_port',
        'ssh_username',
        // ── Switch QoS probe ───────────────────────────────────────────────
        'telnet_reachable',
        'mls_qos_supported',
        'qos_probed_at',
        'qos_probe_error',
    ];

    protected $casts = [
        'status'             => 'string',
        'purchase_date'      => 'date',
        'warranty_expiry'    => 'date',
        // ITAM
        'purchase_cost'      => 'decimal:2',
        'current_value'      => 'decimal:2',
        'depreciation_years' => 'integer',
        'proxy_enabled'      => 'boolean',
        'proxy_legacy_tls'   => 'boolean',
        'web_port'           => 'integer',
        'ssh_port'           => 'integer',
        'telnet_reachable'   => 'boolean',
        'mls_qos_supported'  => 'boolean',
        'qos_probed_at'      => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function sshSessions(): HasMany
    {
        return $this->hasMany(DeviceSshSession::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(DeviceAccessLog::class)->latest('created_at');
    }

    public function deviceModel(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'device_model_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(NetworkOffice::class, 'office_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(Credential::class);
    }

    public function printer(): HasOne
    {
        return $this->hasOne(Printer::class);
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeAsset::class, 'asset_id');
    }

    public function currentAssignment(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EmployeeAsset::class, 'asset_id')->whereNull('returned_date');
    }

    // ─── ITAM Relationships ────────────────────────────────────────

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function assetHistory(): HasMany
    {
        return $this->hasMany(AssetHistory::class)->orderByDesc('created_at');
    }

    public function licenseAssignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class, 'assignable_id')
                    ->where('assignable_type', self::class);
    }

    public function azureDevice(): HasOne
    {
        return $this->hasOne(AzureDevice::class);
    }

    public function networkSwitch(): HasOne
    {
        return $this->hasOne(NetworkSwitch::class);
    }

    public function monitoredHost(): HasOne
    {
        return $this->hasOne(MonitoredHost::class);
    }

    public function qosStats(): HasMany
    {
        return $this->hasMany(SwitchQosStat::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /** Types that represent personal user equipment (assignable to employees) — dynamic from DB */
    public static function userEquipmentTypes(): array
    {
        return AssetType::userEquipmentSlugs();
    }

    /** Resolve the AssetType record for this device (cached) */
    public function assetType(): ?AssetType
    {
        return AssetType::findBySlug($this->type);
    }

    public function typeLabel(): string
    {
        return $this->assetType()?->label ?? ucfirst($this->type ?? 'Other');
    }

    public function typeIcon(): string
    {
        return $this->assetType()?->icon ?? 'bi-cpu';
    }

    public function typeBadgeClass(): string
    {
        return $this->assetType()?->badge_class ?? 'bg-secondary';
    }

    /** Scope: only user-equipment types (for employee assignment) */
    public function scopeUserEquipment($query)
    {
        return $query->whereIn('type', self::userEquipmentTypes());
    }

    /** Is this device assignable to employees? */
    public function isUserEquipment(): bool
    {
        return $this->assetType()?->is_user_equipment ?? false;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'active'      => 'bg-success',
            'assigned'    => 'bg-primary',
            'available'   => 'bg-success',
            'repair'      => 'bg-warning text-dark',
            'retired'     => 'bg-secondary',
            'maintenance' => 'bg-warning text-dark',
            default       => 'bg-secondary',
        };
    }

    public function isWarrantyExpired(): bool
    {
        if (!$this->warranty_expiry) return false;
        return $this->warranty_expiry->isPast();
    }

    public function warrantyDaysLeft(): ?int
    {
        if (!$this->warranty_expiry) return null;
        return (int) now()->diffInDays($this->warranty_expiry, false);
    }

    /**
     * Calculate the WiFi MAC address for an IP phone from its LAN MAC.
     * Grandstream (and most Yealink/Fanvil) phones use: WiFi MAC = LAN MAC last byte + 1.
     * Example: EC:74:D7:A7:D4:D8  →  EC:74:D7:A7:D4:D9
     */
    public static function calculatePhoneWifiMac(?string $lanMac): ?string
    {
        if (empty($lanMac)) return null;
        $clean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $lanMac));
        if (strlen($clean) !== 12) return null;
        $lastByte = hexdec(substr($clean, 10, 2));
        $nextByte = ($lastByte + 1) & 0xFF;
        $full = substr($clean, 0, 10) . str_pad(dechex($nextByte), 2, '0', STR_PAD_LEFT);
        return implode(':', str_split(strtoupper($full), 2));
    }

    public function isFirmwareOutdated(): bool
    {
        if (!$this->firmware_version || !$this->latest_firmware) return false;
        return $this->firmware_version !== $this->latest_firmware;
    }

    // ─── ITAM Helpers ─────────────────────────────────────────────

    public function conditionBadgeClass(): string
    {
        return match ($this->condition ?? 'new') {
            'new'         => 'bg-success',
            'used'        => 'bg-info text-dark',
            'refurbished' => 'bg-warning text-dark',
            'damaged'     => 'bg-danger',
            default       => 'bg-secondary',
        };
    }

    public function conditionLabel(): string
    {
        return ucfirst($this->condition ?? 'new');
    }

    public function calculateCurrentValue(): float
    {
        return (new DepreciationService())->currentValue($this);
    }

    /**
     * Find or create a device record from a Meraki switch sync.
     */
    public static function syncFromMeraki(NetworkSwitch $switch): self
    {
        return self::updateOrCreate(
            ['source' => 'meraki', 'source_id' => $switch->serial],
            [
                'type'          => 'switch',
                'name'          => $switch->name ?: $switch->serial,
                'model'         => $switch->model,
                'serial_number' => $switch->serial,
                'mac_address'   => $switch->mac,
                'ip_address'    => $switch->lan_ip,
                'branch_id'     => $switch->branch_id,
                'status'        => $switch->status === 'online' ? 'active' : 'active',
            ]
        );
    }
}
