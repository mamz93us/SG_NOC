<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MonitoredHost extends Model
{
    protected $fillable = [
        'branch_id',
        'vpn_id',
        'name',
        'ip',
        'type',
        'discovered_type',
        'ping_enabled',
        'ping_interval_seconds',
        'ping_packet_count',
        'alert_email',
        'snmp_enabled',
        'snmp_version',
        'snmp_community',
        'snmp_port',
        'mib_id',
        'alert_enabled',
        'status',
        'last_ping_at',
        'last_snmp_at',
        'last_checked_at',
    ];

    public function mib(): BelongsTo
    {
        return $this->belongsTo(Mib::class);
    }

    protected $hidden = [
        'snmp_community',
    ];

    protected $casts = [
        'ping_enabled' => 'boolean',
        'snmp_enabled' => 'boolean',
        'alert_enabled' => 'boolean',
        // 'snmp_community' removed from casts to handle manually with error catching
        'last_ping_at' => 'datetime',
        'last_snmp_at' => 'datetime',
        'last_checked_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vpnTunnel(): BelongsTo
    {
        return $this->belongsTo(VpnTunnel::class, 'vpn_id');
    }

    public function getSnmpCommunityAttribute($value)
    {
        if (empty($value)) return 'public';
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            \Log::warning("Decryption failed for host {$this->id} ({$this->name}) community string. MAC probably invalid. Defaulting to public.");
            return 'public';
        }
    }

    public function setSnmpCommunityAttribute($value)
    {
        $this->attributes['snmp_community'] = encrypt($value);
    }

    public function hostChecks(): HasMany
    {
        return $this->hasMany(HostCheck::class, 'host_id');
    }

    public function snmpSensors(): HasMany
    {
        return $this->hasMany(SnmpSensor::class, 'host_id');
    }
}
