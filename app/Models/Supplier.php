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

    public function totalSpend(): float
    {
        return (float) $this->devices()->sum('purchase_cost') + (float) $this->accessories()->sum('purchase_cost');
    }

    public function assetCount(): int
    {
        return $this->devices()->count();
    }
}
