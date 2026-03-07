<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Printer extends Model
{
    protected $fillable = [
        'device_id',
        'printer_name',
        'manufacturer',
        'model',
        'serial_number',
        'mac_address',
        'ip_address',
        'branch_id',
        'floor_id',
        'office_id',
        'department_id',
        // Legacy free-text fields (kept for migration compatibility)
        'floor',
        'room',
        'department',
        'toner_model',
        'snmp_community',
        'snmp_version',
        'notes',
        // Maintenance fields
        'toner_last_changed',
        'expected_page_yield',
        'last_service_date',
        'service_interval_days',
    ];

    protected $casts = [
        'toner_last_changed' => 'date',
        'last_service_date'  => 'date',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function networkFloor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(NetworkOffice::class, 'office_id');
    }

    public function departmentModel(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Location string using structured FK fields when available, falling back to legacy strings.
     */
    public function locationLabel(): string
    {
        $parts = [];

        if ($this->office) {
            $parts[] = $this->office->name;
        } elseif ($this->room) {
            $parts[] = $this->room;
        }

        if ($this->networkFloor) {
            array_unshift($parts, $this->networkFloor->name);
        } elseif ($this->floor) {
            array_unshift($parts, $this->floor);
        }

        return implode(' / ', $parts) ?: '—';
    }

    /**
     * Department name, from FK first, then legacy string.
     */
    public function departmentLabel(): string
    {
        return $this->departmentModel?->name ?? $this->department ?? '—';
    }

    /**
     * Number of credentials linked through the device.
     */
    public function credentialsCount(): int
    {
        return $this->device?->credentials()->count() ?? 0;
    }

    // ─────────────────────────────────────────────────────────────
    // Maintenance relations
    // ─────────────────────────────────────────────────────────────

    public function maintenanceLogs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrinterMaintenanceLog::class)->orderByDesc('performed_at');
    }

    // ─────────────────────────────────────────────────────────────
    // Maintenance alerts
    // ─────────────────────────────────────────────────────────────

    public function isMaintenanceDue(): bool
    {
        if (!$this->service_interval_days) return false;

        $lastService = $this->last_service_date ?? $this->created_at?->toDate();
        if (!$lastService) return false;

        return \Carbon\Carbon::parse($lastService)->addDays($this->service_interval_days)->isPast();
    }

    public function isTonerDue(): bool
    {
        if (!$this->toner_last_changed) return false;

        // Assume toner due after 180 days if no page yield tracking
        return \Carbon\Carbon::parse($this->toner_last_changed)->addDays(180)->isPast();
    }

    public function daysSinceLastService(): ?int
    {
        if (!$this->last_service_date) return null;
        return (int) \Carbon\Carbon::parse($this->last_service_date)->diffInDays(now());
    }

    public function daysUntilServiceDue(): ?int
    {
        if (!$this->service_interval_days) return null;
        $lastService = $this->last_service_date ?? $this->created_at?->toDate();
        if (!$lastService) return null;
        $dueDate = \Carbon\Carbon::parse($lastService)->addDays($this->service_interval_days);
        return (int) now()->diffInDays($dueDate, false);
    }
}
