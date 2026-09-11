<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fingerprint terminal seen in punches, and the last punch it sent.
 *
 * Access-control punches carry no area, so a terminal can be mapped to a
 * branch and time zone of its own. When a punch has an area, the area wins.
 */
class BiotimeTerminal extends Model
{
    protected $fillable = [
        'biotime_source_id',
        'terminal_sn',
        'terminal_alias',
        'area_alias',
        'branch_id',
        'timezone',
        'last_punch_at',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'last_punch_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiotimeSource::class, 'biotime_source_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
