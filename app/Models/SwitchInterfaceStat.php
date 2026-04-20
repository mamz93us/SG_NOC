<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchInterfaceStat extends Model
{
    protected $fillable = [
        'device_id', 'device_name', 'device_ip', 'branch_id', 'interface_name',
        'in_octets', 'in_ucast_pkts', 'in_mcast_pkts', 'in_bcast_pkts',
        'out_octets', 'out_ucast_pkts', 'out_mcast_pkts', 'out_bcast_pkts',
        'align_err', 'fcs_err', 'xmit_err', 'rcv_err', 'undersize', 'out_discards',
        'single_col', 'multi_col', 'late_col', 'excess_col', 'carri_sen', 'runts', 'giants',
        'total_out_pkts', 'total_in_pkts', 'drop_percentage',
        'polled_at',
    ];

    protected $casts = [
        'polled_at'       => 'datetime',
        'drop_percentage' => 'decimal:4',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
