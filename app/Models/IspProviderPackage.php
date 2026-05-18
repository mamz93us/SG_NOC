<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IspProviderPackage extends Model
{
    protected $fillable = [
        'isp_provider_id',
        'name',
        'speed_down',
        'speed_up',
        'monthly_cost',
        'notes',
    ];

    protected $casts = [
        'speed_down' => 'integer',
        'speed_up' => 'integer',
        'monthly_cost' => 'decimal:2',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(IspProvider::class, 'isp_provider_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(IspConnection::class);
    }

    public function displayName(): string
    {
        $extra = '';
        if ($this->speed_down || $this->speed_up) {
            $extra = ' ('.($this->speed_down ?? '?').'/'.($this->speed_up ?? '?').' Mbps)';
        }

        return $this->name.$extra;
    }
}
