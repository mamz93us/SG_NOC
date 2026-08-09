<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class FortigateFirewall extends Model
{
    protected $table = 'fortigate_firewalls';

    protected $fillable = [
        'branch_id',
        'name',
        'ip',
        'port',
        'vdom',
        'api_token',
        'network_label',
        'label_wifi_only',
        'serial_number',
        'firmware_version',
        'model',
        'monitored_host_id',
        'sync_enabled',
        'last_synced_at',
        'last_sync_error',
        'last_lease_count',
    ];

    protected $hidden = [
        'api_token',
    ];

    protected $casts = [
        'port' => 'integer',
        'sync_enabled' => 'boolean',
        'label_wifi_only' => 'boolean',
        'last_synced_at' => 'datetime',
        'last_lease_count' => 'integer',
    ];

    // ─── Encrypted Accessors ──────────────────────────────────────

    public function setApiTokenAttribute(?string $value): void
    {
        $this->attributes['api_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getApiTokenAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
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

    /**
     * DHCP leases this firewall has reported.
     */
    public function dhcpLeases(): HasMany
    {
        return $this->hasMany(DhcpLease::class, 'source_device', 'ip')
            ->where('source', 'fortigate');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function apiBaseUrl(): string
    {
        return "https://{$this->ip}:{$this->port}/api/v2";
    }

    /**
     * Masked token for display — never render the raw key in a view.
     */
    public function maskedToken(): string
    {
        $token = $this->api_token;
        if (! $token) {
            return '—';
        }

        return substr($token, 0, 4).str_repeat('•', 12).substr($token, -4);
    }

    public function syncStatusBadge(): string
    {
        if (! $this->sync_enabled) {
            return 'bg-secondary';
        }
        if ($this->last_sync_error) {
            return 'bg-danger';
        }
        if (! $this->last_synced_at) {
            return 'bg-warning text-dark';
        }
        if ($this->last_synced_at->diffInMinutes(now()) > 30) {
            return 'bg-danger';
        }

        return 'bg-success';
    }

    public function syncStatusLabel(): string
    {
        if (! $this->sync_enabled) {
            return 'Disabled';
        }
        if ($this->last_sync_error) {
            return 'Error';
        }
        if (! $this->last_synced_at) {
            return 'Never Synced';
        }
        if ($this->last_synced_at->diffInMinutes(now()) > 30) {
            return 'Stale';
        }

        return 'Synced';
    }
}
