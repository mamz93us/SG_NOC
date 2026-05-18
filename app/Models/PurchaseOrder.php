<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $fillable = [
        'po_number',
        'po_date',
        'supplier_id',
        'currency',
        'subtotal',
        'tax',
        'total',
        'notes',
        'status',
        'created_by',
    ];

    protected $casts = [
        'po_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    const STATUSES = ['draft', 'submitted', 'received'];

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(Accessory::class);
    }

    public function licenses(): HasMany
    {
        return $this->hasMany(License::class);
    }

    public function recalcTotals(): void
    {
        $subtotal = $this->items()->get()->sum(fn ($i) => (float) $i->unit_cost * (int) $i->quantity);
        $this->subtotal = $subtotal;
        $this->total = $subtotal + (float) $this->tax;
        $this->save();
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'received' => 'bg-success',
            'submitted' => 'bg-primary',
            'draft' => 'bg-secondary',
            default => 'bg-secondary',
        };
    }
}
