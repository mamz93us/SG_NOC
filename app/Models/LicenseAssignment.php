<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class LicenseAssignment extends Model
{
    protected $fillable = ['license_id', 'assignable_type', 'assignable_id', 'assigned_date', 'notes'];

    protected $casts = ['assigned_date' => 'date'];

    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }

    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
