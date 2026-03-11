<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DeviceModel extends Model
{
    protected $fillable = [
        'name',
        'manufacturer',
        'device_type',
        'latest_firmware',
        'release_notes',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'device_model_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function displayName(): string
    {
        if ($this->manufacturer) {
            return "{$this->manufacturer} {$this->name}";
        }
        return $this->name;
    }
}
