<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchRunningConfig extends Model
{
    protected $fillable = [
        'device_id', 'device_name', 'device_ip', 'branch_id',
        'config_text', 'config_hash', 'size_bytes', 'captured_at',
    ];

    protected $casts = [
        'captured_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
