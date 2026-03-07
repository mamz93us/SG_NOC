<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HostCheck extends Model
{
    protected $fillable = [
        'host_id',
        'check_type', // ping, tcp
        'latency_ms',
        'packet_loss',
        'success',
        'checked_at',
    ];

    protected $casts = [
        'latency_ms' => 'float',
        'packet_loss' => 'float',
        'success' => 'boolean',
        'checked_at' => 'datetime',
    ];

    public function host(): BelongsTo
    {
        return $this->belongsTo(MonitoredHost::class, 'host_id');
    }
}
