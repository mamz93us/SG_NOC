<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class SophosFirewall extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'ip',
        'port',
        'serial_number',
        'firmware_version',
        'model',
        'monitored_host_id',
        'api_username',
        'api_password',
        'sync_enabled',
        'last_synced_at',
    ];

    protected $hidden = [
        'api_username',
        'api_password',
    ];

    protected $casts = [
        'port'           => 'integer',
        'sync_enabled'   => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    // ─── Encrypted Accessors ──────────────────────────────────────

    public function setApiUsernameAttribute(?string $value): void
    {
        $this->attributes['api_username'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getApiUsernameAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function setApiPasswordAttribute(?string $value): void
    {
        $this->attributes['api_password'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getApiPasswordAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    // ─── Relationships ────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function monitoredHost(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class);
    }

    public function interfaces(): HasMany
    {
        return $this->hasMany(SophosInterface::class, 'firewall_id');
    }

    public function networkObjects(): HasMany
    {
        return $this->hasMany(SophosNetworkObject::class, 'firewall_id');
    }

    public function vpnTunnels(): HasMany
    {
        return $this->hasMany(SophosVpnTunnel::class, 'firewall_id');
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(SophosFirewallRule::class, 'firewall_id')->orderBy('position');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function apiUrl(): string
    {
        return "https://{$this->ip}:{$this->port}/webconsole/APIController";
    }

    public function syncStatusBadge(): string
    {
        if (!$this->sync_enabled) return 'bg-secondary';
        if (!$this->last_synced_at) return 'bg-warning text-dark';
        if ($this->last_synced_at->diffInMinutes(now()) > 30) return 'bg-danger';
        return 'bg-success';
    }

    public function syncStatusLabel(): string
    {
        if (!$this->sync_enabled) return 'Disabled';
        if (!$this->last_synced_at) return 'Never Synced';
        if ($this->last_synced_at->diffInMinutes(now()) > 30) return 'Stale';
        return 'Synced';
    }
}
