<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fingerprint terminal seen in punches, and the last punch it sent.
 */
class BiotimeTerminal extends Model
{
    protected $fillable = [
        'biotime_source_id',
        'terminal_sn',
        'terminal_alias',
        'area_alias',
        'last_punch_at',
    ];

    protected $casts = [
        'last_punch_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiotimeSource::class, 'biotime_source_id');
    }
}
