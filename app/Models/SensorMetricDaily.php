<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorMetricDaily extends Model
{
    protected $table = 'sensor_metrics_daily';

    protected $fillable = [
        'sensor_id',
        'date',
        'value_avg',
        'value_min',
        'value_max',
        'sample_count',
    ];

    protected $casts = [
        'date'         => 'date',
        'value_avg'    => 'float',
        'value_min'    => 'float',
        'value_max'    => 'float',
        'sample_count' => 'integer',
    ];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(SnmpSensor::class, 'sensor_id');
    }
}
