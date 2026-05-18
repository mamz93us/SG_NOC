<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'line_type',
        'asset_id',
        'quantity',
        'unit_cost',
        'branch_id',
        'name',
        'manufacturer',
        'model',
        'serial_number',
        'device_type',
        'category',
        'license_type',
        'seats',
        'expiry_date',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_cost' => 'decimal:2',
        'seats' => 'integer',
        'expiry_date' => 'date',
    ];

    const LINE_TYPES = ['device', 'accessory', 'license'];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Resolve the concrete asset row created from this line (Device|Accessory|License).
     */
    public function asset()
    {
        if (! $this->asset_id) {
            return null;
        }

        return match ($this->line_type) {
            'device' => Device::find($this->asset_id),
            'accessory' => Accessory::find($this->asset_id),
            'license' => License::find($this->asset_id),
            default => null,
        };
    }

    /**
     * Build the Device name in the format "Name Serial PO:Number".
     * Used by the PurchaseOrderController when materializing devices.
     */
    public function buildDeviceName(string $poNumber): string
    {
        $parts = array_filter([
            $this->name,
            $this->manufacturer,
            $this->model,
            $this->serial_number,
            "PO:{$poNumber}",
        ]);

        return implode(' ', $parts);
    }

    public function lineTotal(): float
    {
        return (float) $this->unit_cost * (int) $this->quantity;
    }
}
