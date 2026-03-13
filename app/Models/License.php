<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class License extends Model
{
    protected $fillable = [
        'license_name', 'vendor', 'license_key', 'license_type',
        'purchase_date', 'expiry_date', 'cost', 'seats', 'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiry_date'   => 'date',
        'cost'          => 'decimal:2',
        'seats'         => 'integer',
    ];

    const TYPES = ['subscription', 'perpetual', 'oem', 'freeware'];

    // ─── Encrypt license_key at rest ─────────────────────────────

    public function setLicenseKeyAttribute(?string $value): void
    {
        $this->attributes['license_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getLicenseKeyAttribute(?string $value): ?string
    {
        if (!$value) return null;
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class);
    }

    public function usedSeats(): int
    {
        return $this->assignments()->count();
    }

    public function availableSeats(): int
    {
        return max(0, $this->seats - $this->usedSeats());
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    public function isExpiringSoon(int $days = 30): bool
    {
        return $this->expiry_date
            && !$this->isExpired()
            && $this->expiry_date->diffInDays(now()) <= $days;
    }

    public function expiryBadgeClass(): string
    {
        if (!$this->expiry_date) return 'secondary';
        if ($this->isExpired()) return 'danger';
        if ($this->isExpiringSoon()) return 'warning';
        return 'success';
    }

    public function seatUsagePercent(): int
    {
        if ($this->seats <= 0) return 0;
        return (int) round(($this->usedSeats() / $this->seats) * 100);
    }
}
