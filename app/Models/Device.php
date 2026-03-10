<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    protected $fillable = [
        'type',
        'name',
        'model',
        'serial_number',
        'mac_address',
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
    ];

    protected $casts = [
        'status'          => 'string',
        'purchase_date'   => 'date',
        'warranty_expiry' => 'date',
    ];

    // ─── Relationships ────────────────────────────────────────────

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

    // ─── Helpers ──────────────────────────────────────────────────

    /** Types that represent personal user equipment (assignable to employees) */
    public const USER_EQUIPMENT_TYPES = [
        'laptop', 'desktop', 'monitor', 'keyboard', 'mouse', 'headset', 'tablet',
    ];

    public function typeLabel(): string
    {
        return match ($this->type) {
            // ── Infrastructure ────────────────────────────────────
            'ucm'      => 'UCM / IPPBX',
            'switch'   => 'Network Switch',
            'router'   => 'Router',
            'firewall' => 'Firewall',
            'ap'       => 'Access Point',
            'printer'  => 'Printer',
            'server'   => 'Server',
            // ── User Equipment ────────────────────────────────────
            'laptop'   => 'Laptop',
            'desktop'  => 'Desktop',
            'monitor'  => 'Monitor',
            'keyboard' => 'Keyboard',
            'mouse'    => 'Mouse',
            'headset'  => 'Headset',
            'tablet'   => 'Tablet',
            default    => 'Other',
        };
    }

    public function typeIcon(): string
    {
        return match ($this->type) {
            // ── Infrastructure ────────────────────────────────────
            'ucm'      => 'bi-telephone-fill',
            'switch'   => 'bi-hdd-network',
            'router'   => 'bi-router-fill',
            'firewall' => 'bi-shield-lock-fill',
            'ap'       => 'bi-wifi',
            'printer'  => 'bi-printer-fill',
            'server'   => 'bi-server',
            // ── User Equipment ────────────────────────────────────
            'laptop'   => 'bi-laptop',
            'desktop'  => 'bi-pc-display',
            'monitor'  => 'bi-display',
            'keyboard' => 'bi-keyboard',
            'mouse'    => 'bi-mouse',
            'headset'  => 'bi-headset',
            'tablet'   => 'bi-tablet',
            default    => 'bi-cpu',
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            // ── Infrastructure ────────────────────────────────────
            'ucm'      => 'bg-primary',
            'switch'   => 'bg-info text-dark',
            'router'   => 'bg-warning text-dark',
            'firewall' => 'bg-danger',
            'ap'       => 'bg-success',
            'printer'  => 'bg-secondary',
            'server'   => 'bg-dark',
            // ── User Equipment ────────────────────────────────────
            'laptop', 'desktop'              => 'bg-primary',
            'monitor', 'tablet'              => 'bg-info text-dark',
            'keyboard', 'mouse', 'headset'   => 'bg-secondary',
            default    => 'bg-secondary',
        };
    }

    /** Scope: only user-equipment types (for employee assignment) */
    public function scopeUserEquipment($query)
    {
        return $query->whereIn('type', self::USER_EQUIPMENT_TYPES);
    }

    /** Is this device assignable to employees? */
    public function isUserEquipment(): bool
    {
        return in_array($this->type, self::USER_EQUIPMENT_TYPES);
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

    public function isFirmwareOutdated(): bool
    {
        if (!$this->firmware_version || !$this->latest_firmware) return false;
        return $this->firmware_version !== $this->latest_firmware;
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
