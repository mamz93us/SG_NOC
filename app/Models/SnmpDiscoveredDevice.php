<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SnmpDiscoveredDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'branch_log_collector_id',
        'host',
        'mac',
        'sys_descr',
        'sys_name',
        'suggested_type',
        'snmp_responding',
        'status',
        'first_seen_at',
        'last_seen_at',
        'seen_count',
        'notes',
    ];

    protected $casts = [
        'snmp_responding' => 'boolean',
        'first_seen_at'   => 'datetime',
        'last_seen_at'    => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(BranchLogCollector::class, 'branch_log_collector_id');
    }

    /**
     * Best-guess device type from a SNMP sysDescr string.
     * Branch's nmap-discover.sh gets first cut; this is the fallback.
     */
    public static function guessTypeFromSysDescr(?string $sysDescr): ?string
    {
        if (!$sysDescr) return null;
        $s = strtolower($sysDescr);
        return match (true) {
            str_contains($s, 'sophos') || str_contains($s, 'xgs') => 'sophos_xgs',
            str_contains($s, 'tp-link omada') || str_contains($s, 'omada')
                || str_contains($s, 'eap')                       => 'tplink_omada_ap',
            str_contains($s, 'grandstream')                      => 'grandstream_ucm',
            str_contains($s, 'meraki')                           => 'meraki_switch',
            str_contains($s, 'cisco') || str_contains($s, 'catalyst')
                || str_contains($s, 'ios software')              => 'cisco_switch',
            str_contains($s, 'tplink') || str_contains($s, 'tp-link')
                || str_contains($s, 'switch') || str_contains($s, 'h3c')
                || str_contains($s, 'huawei') || str_contains($s, 'aruba') => 'switch_generic',
            default                                              => 'generic_snmp',
        };
    }
}
