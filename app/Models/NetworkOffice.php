<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkOffice extends Model
{
    protected $fillable = [
        'floor_id',
        'name',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function floor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
    }

    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class, 'office_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'office_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Full label: "Branch › Floor › Office"
     */
    public function fullLabel(): string
    {
        $branch = $this->floor?->branch?->name ?? '?';
        $floor  = $this->floor?->name           ?? '?';
        return "{$branch} › {$floor} › {$this->name}";
    }
}
