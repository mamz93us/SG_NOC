<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class License extends Model
{
    protected $fillable = [
        'license_name', 'vendor', 'supplier_id', 'purchase_order_id', 'license_key', 'license_type',
        'purchase_date', 'expiry_date', 'cost', 'vat_rate', 'currency', 'seats', 'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiry_date' => 'date',
        'cost' => 'decimal:2',
        'vat_rate' => 'decimal:2',
        'seats' => 'integer',
    ];

    // Never serialize the decrypted key into JSON / HTML attributes
    protected $hidden = ['license_key'];

    const TYPES = ['subscription', 'perpetual', 'oem', 'freeware'];

    // ─── Encrypt license_key at rest ─────────────────────────────

    public function setLicenseKeyAttribute(?string $value): void
    {
        $this->attributes['license_key'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getLicenseKeyAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Exception) {
            return null;
        }
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function identityLicense(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(IdentityLicense::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(LicenseAssignment::class);
    }

    /** Display name for the license vendor — supplier relation, or legacy free-text fallback. */
    public function vendorDisplay(): ?string
    {
        return $this->supplier?->name ?? $this->vendor ?: null;
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
            && ! $this->isExpired()
            && $this->expiry_date->diffInDays(now()) <= $days;
    }

    public function expiryBadgeClass(): string
    {
        if (! $this->expiry_date) {
            return 'secondary';
        }
        if ($this->isExpired()) {
            return 'danger';
        }
        if ($this->isExpiringSoon()) {
            return 'warning';
        }

        return 'success';
    }

    public function seatUsagePercent(): int
    {
        if ($this->seats <= 0) {
            return 0;
        }

        return (int) round(($this->usedSeats() / $this->seats) * 100);
    }

    // ─────────────────────────────────────────────────────────────
    // Money
    //
    // `cost` is the UNIT price per seat, excluding VAT — that is what a PO
    // quotes, and it stays meaningful when seat counts change. Everything
    // else is derived, so nothing can drift out of step with it.
    // ─────────────────────────────────────────────────────────────

    /** Unit price including VAT. */
    public function unitCostIncVat(): ?float
    {
        if ($this->cost === null) {
            return null;
        }

        return round((float) $this->cost * (1 + ((float) ($this->vat_rate ?? 0) / 100)), 2);
    }

    /** Whole-subscription cost at the current seat count, excluding VAT. */
    public function totalCostExVat(): ?float
    {
        if ($this->cost === null) {
            return null;
        }

        return round((float) $this->cost * max(0, (int) $this->seats), 2);
    }

    /** VAT amount across all seats. */
    public function vatAmount(): ?float
    {
        $ex = $this->totalCostExVat();

        if ($ex === null) {
            return null;
        }

        return round($ex * ((float) ($this->vat_rate ?? 0) / 100), 2);
    }

    /** Whole-subscription cost at the current seat count, including VAT. */
    public function totalCostIncVat(): ?float
    {
        $ex = $this->totalCostExVat();

        return $ex === null ? null : round($ex + (float) $this->vatAmount(), 2);
    }
}
