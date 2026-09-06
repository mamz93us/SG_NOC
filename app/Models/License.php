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
        'billing_cycle', 'purchase_date', 'expiry_date', 'cost', 'currency',
        'payment_method', 'payment_account', 'seats', 'notes',
    ];

    protected $casts = [
        'purchase_date' => 'date',
        'expiry_date' => 'date',
        'cost' => 'decimal:2',
        'seats' => 'integer',
    ];

    // Never serialize the decrypted key into JSON / HTML attributes
    protected $hidden = ['license_key'];

    // 'ai' is its own type rather than a tag on 'subscription': the AI tools are
    // per-person, month-to-month and bought on a card, so finance reviews them
    // as one basket separately from the annual software estate.
    const TYPES = ['subscription', 'ai', 'perpetual', 'oem', 'freeware'];

    const TYPE_LABELS = [
        'subscription' => 'Subscription',
        'ai' => 'AI Subscription',
        'perpetual' => 'Perpetual',
        'oem' => 'OEM',
        'freeware' => 'Freeware',
    ];

    /** How often `cost` is charged. Drives every figure in the finance reports. */
    const BILLING_CYCLES = ['one_time', 'monthly', 'quarterly', 'annual'];

    const BILLING_CYCLE_LABELS = [
        'one_time' => 'One-time',
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'annual' => 'Annual',
    ];

    /** Months covered by one charge — the divisor behind monthlyRunRate(). */
    const BILLING_CYCLE_MONTHS = [
        'monthly' => 1,
        'quarterly' => 3,
        'annual' => 12,
    ];

    const PAYMENT_METHODS = ['credit_card', 'wire_transfer', 'cash', 'other'];

    const PAYMENT_METHOD_LABELS = [
        'credit_card' => 'Credit Card',
        'wire_transfer' => 'Wire Transfer',
        'cash' => 'Cash',
        'other' => 'Other',
    ];

    /**
     * Methods that charge themselves on renewal day. The payments report splits
     * on this: everything else is a payment somebody has to actually raise.
     */
    const AUTO_CHARGED_METHODS = ['credit_card'];

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
    // `cost` is the price per seat INCLUDING VAT — one figure, as invoiced.
    // There is deliberately no separate tax field: everything that reports
    // money reads this directly, so there is no basis to get wrong.
    // ─────────────────────────────────────────────────────────────

    /** Whole-subscription cost at the current seat count. */
    public function totalCost(): ?float
    {
        if ($this->cost === null) {
            return null;
        }

        return round((float) $this->cost * max(0, (int) $this->seats), 2);
    }

    // ─────────────────────────────────────────────────────────────
    // Recurring billing
    //
    // `cost` is the price of ONE seat for ONE billing period. So the amount
    // that leaves the bank on a renewal date is cost × seats — totalCost().
    // Everything below is that figure viewed two ways: spread evenly per month
    // (run rate, for the usage report) or landed on the month it is actually
    // charged (for the payments report). They are different numbers on purpose
    // and must not be added together.
    // ─────────────────────────────────────────────────────────────

    public function isRecurring(): bool
    {
        return isset(self::BILLING_CYCLE_MONTHS[$this->billing_cycle]);
    }

    /** Months covered by one charge, or null when the licence is not recurring. */
    public function billingCycleMonths(): ?int
    {
        return self::BILLING_CYCLE_MONTHS[$this->billing_cycle] ?? null;
    }

    /** Amount charged on one renewal date, at the current seat count. */
    public function chargeAmount(): ?float
    {
        return $this->isRecurring() ? $this->totalCost() : null;
    }

    /**
     * Cost apportioned to a single month — an annual licence divided by 12.
     * This is the "what is this costing us per month" figure, NOT a payable.
     */
    public function monthlyRunRate(): ?float
    {
        $months = $this->billingCycleMonths();
        if ($months === null || $this->cost === null) {
            return null;
        }

        return round($this->totalCost() / $months, 2);
    }

    /** Same, for one seat. */
    public function monthlyRunRatePerSeat(): ?float
    {
        $months = $this->billingCycleMonths();
        if ($months === null || $this->cost === null) {
            return null;
        }

        return round((float) $this->cost / $months, 2);
    }

    /**
     * The renewal date that falls inside the given month, or null if this
     * licence is not charged that month.
     *
     * `expiry_date` is the anchor — the renewal date as last known. A monthly
     * subscription anchored on the 20th is charged on the 20th of every month,
     * so the anchor is projected forwards AND backwards from rather than being
     * rolled forward in the database: a stored date that a cron has to advance
     * silently rewrites history the moment that cron misses a month.
     *
     * Short months clamp (a 31st anchor is charged on the 30th in November).
     */
    public function renewalDateIn(\Carbon\CarbonInterface $month): ?\Carbon\CarbonImmutable
    {
        $cycleMonths = $this->billingCycleMonths();
        if ($cycleMonths === null || ! $this->expiry_date) {
            return null;
        }

        $anchor = \Carbon\CarbonImmutable::parse($this->expiry_date);
        $target = \Carbon\CarbonImmutable::parse($month)->startOfMonth();

        $monthsApart = ($target->year - $anchor->year) * 12 + ($target->month - $anchor->month);
        if ($monthsApart % $cycleMonths !== 0) {
            return null;
        }

        // $target is the 1st, so setting a clamped day can never overflow.
        $due = $target->day(min($anchor->day, $target->daysInMonth));

        // A licence bought after the month in question was not charged in it.
        if ($this->purchase_date && $this->purchase_date->gt($due)) {
            return null;
        }

        return $due;
    }

    /** True when renewal day charges a card by itself — nobody has to act. */
    public function isAutoCharged(): bool
    {
        return in_array($this->payment_method, self::AUTO_CHARGED_METHODS, true);
    }

    public function typeLabel(): string
    {
        return self::TYPE_LABELS[$this->license_type] ?? ucfirst((string) $this->license_type);
    }

    public function billingCycleLabel(): string
    {
        return self::BILLING_CYCLE_LABELS[$this->billing_cycle] ?? 'One-time';
    }

    public function paymentMethodLabel(): ?string
    {
        return self::PAYMENT_METHOD_LABELS[$this->payment_method] ?? null;
    }

    // ─── Scopes ──────────────────────────────────────────────────

    public function scopeRecurring($query)
    {
        return $query->whereIn('billing_cycle', array_keys(self::BILLING_CYCLE_MONTHS));
    }

    public function scopeAi($query)
    {
        return $query->where('license_type', 'ai');
    }
}
