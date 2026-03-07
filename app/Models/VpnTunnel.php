<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VpnTunnel extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'local_id',
        'remote_id',
        'remote_public_ip',
        'remote_subnet',
        'local_subnet',
        'pre_shared_key',
        'ike_version',
        'encryption',
        'hash',
        'dh_group',
        'dpd_delay',
        'lifetime',
        'status',
        'last_checked_at',
    ];

    protected $casts = [
        // 'pre_shared_key' => 'encrypted', // handled manually with error catching
        'last_checked_at' => 'datetime',
        'dh_group' => 'integer',
        'dpd_delay' => 'integer',
    ];

    public function getPreSharedKeyAttribute($value)
    {
        if (empty($value)) return '';
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::error("Decryption failed for VPN Tunnel {$this->id} ({$this->name}). MAC probably invalid.");
            return '********';
        }
    }

    public function setPreSharedKeyAttribute($value)
    {
        $this->attributes['pre_shared_key'] = encrypt($value);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VpnLog::class, 'vpn_id');
    }

    public function monitoredHosts(): HasMany
    {
        return $this->hasMany(MonitoredHost::class, 'vpn_id');
    }
}
