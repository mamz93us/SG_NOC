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
        'last_health_ok',
        'last_health_at',
        'last_health_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_health_ok' => 'boolean',
        'last_health_at' => 'datetime',
    ];

    /**
     * Scope: only active servers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Has SyncUcmExtensionsJob reached this PBX recently enough to trust the
     * verdict? Older than the window means "we stopped hearing", which the
     * branch health score reports as unknown rather than as healthy.
     */
    public function healthIsFresh(?int $withinMinutes = null): bool
    {
        if (! $this->last_health_at) {
            return false;
        }

        $window = $withinMinutes ?? (int) config('branch_health.freshness.ucm', 2);

        return $this->last_health_at->gte(now()->subMinutes($window));
    }
}
