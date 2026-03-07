<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorMetric extends Model
{
    protected $fillable = [
        'sensor_id',
        'value',
        'recorded_at',
    ];

    protected $casts = [
        'value' => 'float',
        'recorded_at' => 'datetime',
    ];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(SnmpSensor::class, 'sensor_id');
    }
}
