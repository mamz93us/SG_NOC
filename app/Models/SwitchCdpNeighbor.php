<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchCdpNeighbor extends Model
{
    protected $fillable = [
        'device_id', 'device_name', 'device_ip',
        'local_interface', 'neighbor_device_id', 'neighbor_ip', 'neighbor_mac', 'neighbor_port',
        'platform', 'capabilities', 'version', 'holdtime', 'polled_at',
        'matched_meraki_serial', 'matched_device_id',
    ];

    protected $casts = [
        'polled_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function merakiSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'matched_meraki_serial', 'serial');
    }

    public function matchedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'matched_device_id');
    }
}
