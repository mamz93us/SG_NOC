<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IspProvider extends Model
{
    protected $fillable = ['name', 'default_currency', 'notes'];

    const CURRENCIES = ['EGP', 'SAR', 'USD'];

    public function packages(): HasMany
    {
        return $this->hasMany(IspProviderPackage::class)->orderBy('name');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(IspConnection::class);
    }
}
