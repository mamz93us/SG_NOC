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

        foreach ($this->accessories()->whereNotNull('purchase_cost')->get(['purchase_cost', 'currency']) as $a) {
            $code = $a->currency ?: 'USD';
            $totals[$code] = ($totals[$code] ?? 0) + (float) $a->purchase_cost;
        }

        return $totals;
    }

    public function assetCount(): int
    {
        return $this->devices()->count();
    }
}
