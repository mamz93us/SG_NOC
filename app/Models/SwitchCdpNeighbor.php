<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchCdpNeighbor extends Model
{
    protected $fillable = [
        'device_id', 'device_name', 'device_ip',
        'local_interface', 'neighbor_device_id', 'neighbor_ip', 'neighbor_port',
        'platform', 'capabilities', 'version', 'holdtime', 'polled_at',
    ];

    protected $casts = [
        'polled_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
