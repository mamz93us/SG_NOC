<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UcmActiveCall extends Model
{
    protected $table = 'ucm_active_calls';

    protected $fillable = [
        'ucm_id', 'caller', 'callee',
        'start_time', 'answered_time', 'duration', 'call_id',
    ];

    protected $casts = [
        'start_time'    => 'datetime',
        'answered_time' => 'datetime',
    ];

    public function ucmServer(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'ucm_id');
    }

    public function durationFormatted(): string
    {
        $secs = $this->duration;
        if ($this->start_time && $secs <= 0) {
            $secs = (int) now()->diffInSeconds($this->start_time);
        }

        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;

        return $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
    }
}
