<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkFloor extends Model
{
    protected $fillable = [
        'branch_id',
        'name',
        'description',
        'sort_order',
        'ext_range_start',
        'ext_range_end',
    ];

    protected $casts = [
        'sort_order'      => 'integer',
        'ext_range_start' => 'integer',
        'ext_range_end'   => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function racks(): HasMany
    {
        return $this->hasMany(NetworkRack::class, 'floor_id')->orderBy('sort_order')->orderBy('name');
    }

    public function switches(): HasMany
    {
        return $this->hasMany(NetworkSwitch::class, 'floor_id');
    }

    public function offices(): HasMany
    {
        return $this->hasMany(NetworkOffice::class, 'floor_id')->orderBy('sort_order')->orderBy('name');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function switchCount(): int
    {
        return $this->switches()->count();
    }
}
