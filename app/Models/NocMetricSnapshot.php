<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NocMetricSnapshot extends Model
{
    protected $fillable = ['metric', 'value', 'branch_id', 'captured_at'];

    protected $casts = [
        'value' => 'float',
        'captured_at' => 'datetime',
    ];
}
