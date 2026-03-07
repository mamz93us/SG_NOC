<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UcmServer extends Model
{
    protected $fillable = [
        'name',
        'url',
        'cloud_domain',
        'api_username',
        'api_password',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope: only active servers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
