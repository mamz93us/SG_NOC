<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VoiceQualityReport extends Model
{
    protected $fillable = [
        'call_id',
        'extension','remote_extension','remote_ip','branch','branch_id','codec',
        'mos_lq','mos_cq','r_factor','jitter_avg','jitter_max','packet_loss',
        'burst_loss','rtt','quality_label','call_start','call_end','call_duration_seconds',
    ];

    protected $casts = [
        'call_start' => 'datetime',
        'call_end'   => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }

    public static function mosLabel(float $mos): string
    {
        if ($mos >= 4.3) return 'excellent';
        if ($mos >= 4.0) return 'good';
        if ($mos >= 3.6) return 'fair';
        if ($mos >= 3.0) return 'poor';
        return 'bad';
    }

    public static function mosColor(float $mos): string
    {
        if ($mos >= 4.0) return 'success';
        if ($mos >= 3.6) return 'warning';
        return 'danger';
    }

    public function scopePoor($q) { return $q->where('mos_lq', '<', 3.0); }
    public function scopeToday($q) { return $q->whereDate('created_at', today()); }
}
