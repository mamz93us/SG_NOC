<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkRack extends Model
{
    protected $fillable = [
        'floor_id',
        'name',
        'description',
        'capacity',
        'sort_order',
    ];

    protected $casts = [
        'capacity'   => 'integer',
        'sort_order' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function floor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
    }

    public function switches(): HasMany
    {
        return $this->hasMany(NetworkSwitch::class, 'rack_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function switchCount(): int
    {
        return $this->switches()->count();
    }

    /**
     * Full label: "Branch / Floor / Rack"
     */
    public function fullLabel(): string
    {
        $parts = [];
        if ($this->floor?->branch) {
            $parts[] = $this->floor->branch->name;
        }
        if ($this->floor) {
            $parts[] = $this->floor->name;
        }
        $parts[] = $this->name;
        return implode(' › ', $parts);
    }
}
