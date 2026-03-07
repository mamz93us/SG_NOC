<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'name',
        'description',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function printers(): HasMany
    {
        return $this->hasMany(Printer::class, 'department_id');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class, 'department_id');
    }
}
