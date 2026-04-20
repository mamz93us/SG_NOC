<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SwitchQosStat extends Model
{
    protected $fillable = [
        'device_id', 'device_name', 'device_ip', 'branch_id', 'interface_name',
        'q0_t1_enq', 'q0_t2_enq', 'q0_t3_enq',
        'q1_t1_enq', 'q1_t2_enq', 'q1_t3_enq',
        'q2_t1_enq', 'q2_t2_enq', 'q2_t3_enq',
        'q3_t1_enq', 'q3_t2_enq', 'q3_t3_enq',
        'q0_t1_drop', 'q0_t2_drop', 'q0_t3_drop',
        'q1_t1_drop', 'q1_t2_drop', 'q1_t3_drop',
        'q2_t1_drop', 'q2_t2_drop', 'q2_t3_drop',
        'q3_t1_drop', 'q3_t2_drop', 'q3_t3_drop',
        'policer_in_profile', 'policer_out_of_profile',
        'total_drops', 'polled_at',
    ];

    protected $casts = ['polled_at' => 'datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
