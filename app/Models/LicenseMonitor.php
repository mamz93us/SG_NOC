<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LicenseMonitor extends Model
{
    protected $fillable = [
        'sku_id', 'display_name', 'critical_threshold',
        'last_alerted_at', 'is_active',
    ];

    protected $casts = [
        'critical_threshold' => 'integer',
        'last_alerted_at'    => 'datetime',
        'is_active'          => 'boolean',
    ];

    public function identityLicense()
    {
        return $this->belongsTo(IdentityLicense::class, 'sku_id', 'sku_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isTriggered(): bool
    {
        $license = $this->identityLicense;
        if (! $license) return false;
        return $license->available <= $this->critical_threshold;
    }

    public function canAlert(): bool
    {
        if (! $this->last_alerted_at) return true;
        return $this->last_alerted_at->diffInDays(now()) >= 7; // max once per week
    }

    public function availabilityColor(): string
    {
        $license = $this->identityLicense;
        if (! $license) return 'secondary';
        $available = $license->available;
        if ($available <= $this->critical_threshold) return 'danger';
        if ($available <= $this->critical_threshold * 2) return 'warning';
        return 'success';
    }
}
