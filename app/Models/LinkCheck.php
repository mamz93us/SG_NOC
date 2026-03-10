<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LinkCheck extends Model
{
    protected $fillable = [
        'isp_id',
        'latency',
        'packet_loss',
        'success',
        'checked_at',
    ];

    protected $casts = [
        'latency'     => 'float',
        'packet_loss' => 'float',
        'success'     => 'boolean',
        'checked_at'  => 'datetime',
    ];

    public function ispConnection(): BelongsTo
    {
        return $this->belongsTo(IspConnection::class, 'isp_id');
    }
}
