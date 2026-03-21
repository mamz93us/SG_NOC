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
        'printer_url',
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
        // SNMP Monitoring fields
        'snmp_enabled',
        'snmp_last_polled_at',
        'toner_black',
        'toner_cyan',
        'toner_magenta',
        'toner_yellow',
        'toner_waste',
        'drum_black',
        'drum_color',
        'fuser_level',
        'paper_trays',
        'page_count_total',
        'page_count_color',
        'page_count_mono',
        'page_count_copy',
        'page_count_print',
        'page_count_scan',
        'page_count_fax',
        'printer_status',
        'error_state',
        'snmp_sys_description',
        'snmp_model',
        'snmp_serial',
        'toner_warning_threshold',
        'toner_critical_threshold',
        'paper_warning_threshold',
    ];

    protected $casts = [
        'toner_last_changed'   => 'date',
        'last_service_date'    => 'date',
        'snmp_enabled'         => 'boolean',
        'snmp_last_polled_at'  => 'datetime',
        'paper_trays'          => 'array',
        'page_count_total'     => 'integer',
        'page_count_color'     => 'integer',
        'page_count_mono'      => 'integer',
        'page_count_copy'      => 'integer',
        'page_count_print'     => 'integer',
        'page_count_scan'      => 'integer',
        'page_count_fax'       => 'integer',
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

    /** Employees manually assigned to this printer by an admin */
    public function assignedEmployees(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'employee_printer')
                    ->withPivot(['assigned_by', 'notes'])
                    ->withTimestamps();
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
    // Supplies relations
    // ─────────────────────────────────────────────────────────────

    public function supplies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrinterSupply::class);
    }

    public function tonerSupplies(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PrinterSupply::class)->where('supply_type', 'toner');
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

    // ─────────────────────────────────────────────────────────────
    // SNMP Monitoring Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Check if SNMP data is available and recent (within 15 minutes).
     */
    public function hasSnmpData(): bool
    {
        return $this->snmp_enabled
            && $this->snmp_last_polled_at
            && $this->snmp_last_polled_at->diffInMinutes(now()) < 15;
    }

    /**
     * Check if this is a color printer (has cyan/magenta/yellow toner data).
     */
    public function isColorPrinter(): bool
    {
        return $this->toner_cyan !== null
            || $this->toner_magenta !== null
            || $this->toner_yellow !== null;
    }

    /**
     * Get all toner levels as an array.
     */
    public function tonerLevels(): array
    {
        $levels = ['Black' => $this->toner_black];

        if ($this->isColorPrinter()) {
            $levels['Cyan']    = $this->toner_cyan;
            $levels['Magenta'] = $this->toner_magenta;
            $levels['Yellow']  = $this->toner_yellow;
        }

        return $levels;
    }

    /**
     * CSS class for toner level gauge color.
     */
    public static function tonerBarClass(?int $level): string
    {
        if ($level === null || $level < 0) return 'bg-secondary';
        if ($level <= 5)  return 'bg-danger';
        if ($level <= 20) return 'bg-warning';
        return 'bg-success';
    }

    /**
     * Color hex for toner by name.
     */
    public static function tonerColor(string $name): string
    {
        return match (strtolower($name)) {
            'black'   => '#212529',
            'cyan'    => '#0dcaf0',
            'magenta' => '#d63384',
            'yellow'  => '#ffc107',
            'waste'   => '#6c757d',
            default   => '#6c757d',
        };
    }

    /**
     * Human-readable printer status.
     */
    public function statusLabel(): string
    {
        return match ($this->printer_status) {
            'idle'     => 'Idle',
            'printing' => 'Printing',
            'warmup'   => 'Warming Up',
            'error'    => 'Error',
            default    => 'Unknown',
        };
    }

    /**
     * Badge class for printer status.
     */
    public function statusBadgeClass(): string
    {
        return match ($this->printer_status) {
            'idle'     => 'bg-success',
            'printing' => 'bg-primary',
            'warmup'   => 'bg-info',
            'error'    => 'bg-danger',
            default    => 'bg-secondary',
        };
    }

    /**
     * Human-readable error state.
     */
    public function errorLabel(): string
    {
        if (!$this->error_state || $this->error_state === 'normal') return 'Normal';
        return ucwords(str_replace('_', ' ', $this->error_state));
    }

    /**
     * Badge class for error state.
     */
    public function errorBadgeClass(): string
    {
        return match ($this->error_state) {
            'normal', null          => 'bg-success',
            'low_paper', 'low_toner', 'service_needed' => 'bg-warning text-dark',
            default                 => 'bg-danger',
        };
    }

    /**
     * Get lowest toner level across all cartridges.
     */
    public function lowestTonerLevel(): ?int
    {
        $levels = array_filter($this->tonerLevels(), fn($v) => $v !== null && $v >= 0);
        return !empty($levels) ? min($levels) : null;
    }

    /**
     * Get paper tray fill percentage (overall).
     */
    public function paperFillPercent(): ?int
    {
        $trays = $this->paper_trays;
        if (empty($trays)) return null;

        $totalCur = 0;
        $totalMax = 0;
        foreach ($trays as $tray) {
            $totalCur += $tray['current'] ?? 0;
            $totalMax += $tray['max'] ?? 0;
        }

        return $totalMax > 0 ? (int) round(($totalCur / $totalMax) * 100) : null;
    }
}
