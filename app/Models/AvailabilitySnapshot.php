<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailabilitySnapshot extends Model
{
    protected $fillable = [
        'entity_type', 'entity_id', 'branch_id', 'up', 'latency_ms', 'captured_at',
    ];

    protected $casts = [
        'up' => 'boolean',
        'latency_ms' => 'integer',
        'captured_at' => 'datetime',
    ];
}
