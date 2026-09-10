<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A BioTime area_alias, mapped once to a NOC branch and the wall clock its
 * devices keep. Pre-filled from azure_branch_mappings keywords on first sight.
 */
class BiotimeArea extends Model
{
    protected $fillable = [
        'biotime_source_id',
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
