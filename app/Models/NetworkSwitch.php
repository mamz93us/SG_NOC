<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkSwitch extends Model
{
    protected $fillable = [
        'device_id',
        'serial',
        'network_id',
        'network_name',
        'name',
        'model',
        'mac',
        'lan_ip',
        'firmware',
        'status',
        'port_count',
        'clients_count',
        'last_reported_at',
        'branch_id',
        'floor_id',
        'rack_id',
        'uplink_port_ids',
        'raw_data',
    ];

    protected $casts = [
        'last_reported_at' => 'datetime',
        'raw_data'         => 'array',
        'uplink_port_ids'  => 'array',
        'port_count'       => 'integer',
        'clients_count'    => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function ports(): HasMany
    {
        return $this->hasMany(NetworkPort::class, 'switch_serial', 'serial');
    }

    public function clients(): HasMany
    {
        return $this->hasMany(NetworkClient::class, 'switch_serial', 'serial');
    }

    public function events(): HasMany
    {
        return $this->hasMany(NetworkEvent::class, 'switch_serial', 'serial');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(NetworkRack::class, 'rack_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Returns a human-readable "Branch › Floor › Rack" string.
     * Only includes levels that are actually set.
     */
    public function locationBreadcrumb(): string
    {
        $parts = [];

        if ($this->branch) {
            $parts[] = $this->branch->name;
        }
        if ($this->floor) {
            $parts[] = $this->floor->name;
        }
        if ($this->rack) {
            $parts[] = $this->rack->name;
        }

        return implode(' › ', $parts) ?: '—';
    }

    /**
     * Returns true if the given port_id is in the user-assigned uplink list.
     */
    public function isManualUplink(string|int $portId): bool
    {
        return in_array((string) $portId, array_map('strval', $this->uplink_port_ids ?? []), true);
    }

    public function isOnline(): bool
    {
        return $this->status === 'online';
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'online'   => 'bg-success',
            'offline'  => 'bg-danger',
            'alerting' => 'bg-warning text-dark',
            default    => 'bg-secondary',
        };
    }

    public function statusIcon(): string
    {
        return match ($this->status) {
            'online'   => 'bi-circle-fill text-success',
            'offline'  => 'bi-circle-fill text-danger',
            'alerting' => 'bi-exclamation-circle-fill text-warning',
            default    => 'bi-circle text-secondary',
        };
    }

    /**
     * Percentage of ports that are connected.
     */
    public function connectedPortPercent(): int
    {
        if (!$this->port_count) {
            return 0;
        }
        $connected = $this->ports()->where('status', 'Connected')->count();
        return (int) round(($connected / $this->port_count) * 100);
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    public function scopeOffline($query)
    {
        return $query->where('status', 'offline');
    }
}
