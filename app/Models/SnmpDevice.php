<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnmpDevice extends Model
{
    use HasFactory;

    public const TYPES = [
        'sophos_xgs'        => 'Sophos XGS firewall',
        'switch_generic'    => 'Switch (generic IF-MIB)',
        'tplink_omada_ap'   => 'TP-Link Omada AP',
        'grandstream_ucm'   => 'Grandstream UCM',
        'phone_icmp'        => 'IP phone (ICMP only)',
        'generic_snmp'      => 'Generic SNMP device',
    ];

    protected $fillable = [
        'branch_log_collector_id',
        'name',
        'host',
        'snmp_version',
        'snmp_community',
        'snmp_port',
        'device_type',
        'polling_interval_s',
        'enabled',
        'notes',
    ];

    protected $casts = [
        'snmp_community' => 'encrypted',
        'enabled'        => 'boolean',
        'last_polled_at' => 'datetime',
    ];

    protected $hidden = ['snmp_community'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BranchLogCollector::class, 'branch_log_collector_id');
    }

    public function scopeEnabled($q)  { return $q->where('enabled', true); }
}
