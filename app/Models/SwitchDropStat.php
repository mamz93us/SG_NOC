<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SwitchDropStat extends Model
{
    protected $fillable = [
        'device_name','device_ip','branch','branch_id','interface_name','interface_index',
        'in_discards','out_discards','in_errors','out_errors','in_octets','out_octets',
        'crc_errors','runts','giants','polled_at',
    ];

    protected $casts = ['polled_at' => 'datetime'];

    public function branch() { return $this->belongsTo(Branch::class); }

    public function getTotalDropsAttribute(): int
    {
        return (int)($this->in_discards + $this->out_discards + $this->in_errors + $this->out_errors);
    }
}
