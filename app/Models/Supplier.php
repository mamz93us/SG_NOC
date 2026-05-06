<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'contact_person', 'email', 'phone', 'address', 'notes'];

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

    /**
     * Total spend grouped by currency (since costs are stored in their original
     * currency without conversion). Returns e.g. ['USD' => 1500.00, 'SAR' => 800.00].
     */
    public function totalSpendByCurrency(): array
    {
        $totals = [];

        foreach ($this->devices()->whereNotNull('purchase_cost')->get(['purchase_cost', 'currency']) as $d) {
            $code = $d->currency ?: 'USD';
            $totals[$code] = ($totals[$code] ?? 0) + (float) $d->purchase_cost;
        }

        foreach ($this->accessories()->whereNotNull('purchase_cost')->get(['purchase_cost', 'quantity_total', 'currency']) as $a) {
            $code = $a->currency ?: 'USD';
            $totals[$code] = ($totals[$code] ?? 0) + ((float) $a->purchase_cost * (int) $a->quantity_total);
        }

        foreach ($this->licenses()->whereNotNull('cost')->get(['cost', 'seats', 'currency']) as $l) {
            $code = $l->currency ?: 'USD';
            $totals[$code] = ($totals[$code] ?? 0) + ((float) $l->cost * max(1, (int) $l->seats));
        }

        return $totals;
    }

    public function assetCount(): int
    {
        return $this->devices()->count() + $this->accessories()->count() + $this->licenses()->count();
    }
}
