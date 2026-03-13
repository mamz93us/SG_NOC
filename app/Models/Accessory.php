<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Accessory extends Model
{
    protected $fillable = [
        'name', 'category', 'quantity_total', 'quantity_available',
        'supplier_id', 'purchase_cost', 'notes',
    ];

    protected $casts = [
        'quantity_total'     => 'integer',
        'quantity_available' => 'integer',
        'purchase_cost'      => 'decimal:2',
    ];

    const CATEGORIES = ['cable', 'adapter', 'bag', 'charger', 'dock', 'mouse', 'keyboard', 'headset', 'other'];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AccessoryAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(AccessoryAssignment::class)->whereNull('returned_date');
    }

    public function isAvailable(): bool
    {
        return $this->quantity_available > 0;
    }

    public function availabilityBadgeClass(): string
    {
        if ($this->quantity_available <= 0) return 'danger';
        if ($this->quantity_available <= 2) return 'warning';
        return 'success';
    }
}
