<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkClient extends Model
{
    protected $fillable = [
        'client_id',
        'switch_serial',
        'mac',
        'ip',
        'hostname',
        'description',
        'vlan',
        'port_id',
        'status',
        'usage_kb',
        'manufacturer',
        'os',
        'last_seen',
    ];

    protected $casts = [
        'last_seen' => 'datetime',
        'vlan'      => 'integer',
        'usage_kb'  => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function networkSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_serial', 'serial');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function isOnline(): bool
    {
        return strtolower($this->status ?? '') === 'online';
    }

    public function displayName(): string
    {
        return $this->hostname ?: $this->description ?: $this->mac;
    }

    /**
     * Human-readable status label — never shows raw null.
     */
    public function statusLabel(): string
    {
        if (!$this->status) {
            return 'Unknown';
        }
        return ucfirst(strtolower($this->status));
    }

    /**
     * Human-readable usage (KB → MB → GB).
     */
    public function usageLabel(): string
    {
        if (!$this->usage_kb) {
            return '-';
        }
        if ($this->usage_kb >= 1_000_000) {
            return round($this->usage_kb / 1_000_000, 1) . ' GB';
        }
        if ($this->usage_kb >= 1_000) {
            return round($this->usage_kb / 1_000, 1) . ' MB';
        }
        return $this->usage_kb . ' KB';
    }

    /**
     * Status badge Bootstrap class.
     * Online  → green
     * Offline → grey
     * null    → muted (translucent) to clearly distinguish from "known offline"
     */
    public function statusBadgeClass(): string
    {
        return match (strtolower($this->status ?? '')) {
            'online'  => 'bg-success',
            'offline' => 'bg-secondary',
            default   => 'bg-light text-secondary border',   // null / unknown
        };
    }
}
