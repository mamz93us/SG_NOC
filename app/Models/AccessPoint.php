<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccessPoint extends Model
{
    protected $fillable = [
        'name', 'vendor', 'controller', 'model', 'serial_number', 'mac_address',
        'ip_address', 'site', 'branch_id', 'firmware', 'license_state', 'profile',
        'config_status', 'channel_2g', 'channel_5g', 'channel_6g',
        'cpu_usage', 'memory_usage', 'uptime_seconds',
        'monitor_enabled', 'status', 'ping_latency_ms', 'last_ping_at', 'last_seen_at',
        'device_id', 'raw',
    ];

    protected $casts = [
        'monitor_enabled' => 'boolean',
        'cpu_usage' => 'integer',
        'memory_usage' => 'integer',
        'uptime_seconds' => 'integer',
        'ping_latency_ms' => 'integer',
        'last_ping_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'raw' => 'array',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeMonitored(Builder $query): Builder
    {
        return $query->where('monitor_enabled', true);
    }

    public function scopeVendor(Builder $query, string $vendor): Builder
    {
        return $query->where('vendor', $vendor);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'up' => 'bg-success',
            'down' => 'bg-danger',
            default => 'bg-secondary',
        };
    }

    public function vendorLabel(): string
    {
        return match ($this->vendor) {
            'sophos' => 'Sophos',
            'tp_link' => 'TP-Link',
            default => ucfirst((string) $this->vendor),
        };
    }

    public function controllerLabel(): string
    {
        return match ($this->controller) {
            'sophos_central' => 'Sophos Central',
            'omada' => 'Omada Cloud',
            'manual' => 'Manual',
            default => (string) $this->controller,
        };
    }

    public function uptimeHuman(): ?string
    {
        if (! $this->uptime_seconds) {
            return null;
        }
        $d = intdiv($this->uptime_seconds, 86400);
        $h = intdiv($this->uptime_seconds % 86400, 3600);

        return $d > 0 ? "{$d}d {$h}h" : "{$h}h";
    }
}
