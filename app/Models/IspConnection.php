<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IspConnection extends Model
{
    protected $fillable = [
        'branch_id',
        'isp_provider_id',
        'isp_provider_package_id',
        'provider',
        'account_number',
        'billing_account_number',
        'purpose',
        'connection_type',
        'customer_type',
        'payment_type',
        'billing_day',
        'package',
        'circuit_id',
        'speed_down',
        'speed_up',
        'static_ip',
        'gateway',
        'subnet',
        'router_device_id',
        'contract_start',
        'contract_end',
        'renewal_date',
        'renewal_remind_days',
        'renewal_reminded_at',
        'monthly_cost',
        'currency',
        'notes',
    ];

    protected $casts = [
        'speed_down' => 'integer',
        'speed_up' => 'integer',
        'billing_day' => 'integer',
        'monthly_cost' => 'decimal:2',
        'contract_start' => 'date',
        'contract_end' => 'date',
        'renewal_date' => 'date',
        'renewal_remind_days' => 'integer',
        'renewal_reminded_at' => 'datetime',
    ];

    const CONNECTION_TYPES = ['copper', 'fiber', '5g', 'dedicated'];

    const CUSTOMER_TYPES = ['business', 'home'];

    const PAYMENT_TYPES = ['prepaid', 'postpaid'];

    const CURRENCIES = ['EGP', 'SAR', 'USD'];

    public function costLabel(): string
    {
        if ($this->monthly_cost === null) {
            return '—';
        }

        return number_format((float) $this->monthly_cost, 2).' '.($this->currency ?: 'EGP');
    }

    // ─── Relationships ──────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function routerDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'router_device_id');
    }

    public function ispProvider(): BelongsTo
    {
        return $this->belongsTo(IspProvider::class, 'isp_provider_id');
    }

    public function ispProviderPackage(): BelongsTo
    {
        return $this->belongsTo(IspProviderPackage::class, 'isp_provider_package_id');
    }

    public function linkChecks(): HasMany
    {
        return $this->hasMany(LinkCheck::class, 'isp_id');
    }

    // ─── Helpers ────────────────────────────────────────────────

    public function isContractExpired(): bool
    {
        return $this->contract_end && $this->contract_end->isPast();
    }

    public function isContractExpiring(int $days = 30): bool
    {
        return $this->contract_end
            && ! $this->contract_end->isPast()
            && $this->contract_end->diffInDays(now()) <= $days;
    }

    public function contractStatusBadge(): string
    {
        if (! $this->contract_end) {
            return 'bg-secondary';
        }
        if ($this->isContractExpired()) {
            return 'bg-danger';
        }
        if ($this->isContractExpiring()) {
            return 'bg-warning text-dark';
        }

        return 'bg-success';
    }

    public function contractStatusLabel(): string
    {
        if (! $this->contract_end) {
            return 'No Contract';
        }
        if ($this->isContractExpired()) {
            return 'Expired';
        }
        if ($this->isContractExpiring()) {
            return 'Expiring Soon';
        }

        return 'Active';
    }

    public function speedLabel(): string
    {
        if (! $this->speed_down && ! $this->speed_up) {
            return '-';
        }

        return ($this->speed_down ?? '?').'/'.($this->speed_up ?? '?').' Mbps';
    }

    // ─── Renewal helpers ─────────────────────────────────────────

    public function isRenewalDue(): bool
    {
        return $this->renewal_date && $this->renewal_date->isPast();
    }

    public function isRenewalSoon(?int $days = null): bool
    {
        $days = $days ?? ($this->renewal_remind_days ?: 2);

        return $this->renewal_date
            && ! $this->renewal_date->isPast()
            && $this->renewal_date->diffInDays(now()) <= $days;
    }

    public function renewalStatusBadge(): string
    {
        if (! $this->renewal_date) {
            return 'bg-secondary';
        }
        if ($this->isRenewalDue()) {
            return 'bg-danger';
        }
        if ($this->isRenewalSoon()) {
            return 'bg-warning text-dark';
        }

        return 'bg-success';
    }

    public function renewalStatusLabel(): string
    {
        if (! $this->renewal_date) {
            return 'No Date';
        }
        if ($this->isRenewalDue()) {
            return 'Overdue';
        }
        if ($this->isRenewalSoon()) {
            return 'Due Soon';
        }

        return 'OK';
    }

    /**
     * Whether this ISP needs a renewal reminder right now.
     *
     * Cycle-aware: if billing_day is set, the renewal repeats every month on
     * that day. Falls back to legacy single-shot renewal_date if billing_day
     * is null.
     */
    public function needsRenewalReminder(): bool
    {
        $next = $this->nextRenewalDate();
        if (! $next) {
            return false;
        }

        $days = $this->renewal_remind_days ?: 2;
        $reminderDate = $next->copy()->subDays($days);

        // Not yet time for the reminder
        if (now()->lt($reminderDate)) {
            return false;
        }

        // Already sent a reminder for THIS cycle (i.e. between this cycle's
        // reminder window and the next cycle starting).
        if ($this->renewal_reminded_at && $this->renewal_reminded_at->gte($reminderDate)) {
            return false;
        }

        return true;
    }

    // ─── Billing-cycle helpers ──────────────────────────────────

    /**
     * Compute the next renewal date based on billing_day. Falls back to
     * renewal_date (legacy single-shot) if billing_day is null.
     */
    public function nextRenewalDate(): ?\Illuminate\Support\Carbon
    {
        if ($this->billing_day) {
            return self::cycleNextFromDay((int) $this->billing_day);
        }

        return $this->renewal_date ? $this->renewal_date->copy() : null;
    }

    /**
     * Return the next N upcoming renewal dates, computed from billing_day.
     *
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Carbon>
     */
    public function upcomingRenewals(int $count = 6): \Illuminate\Support\Collection
    {
        $out = collect();
        if (! $this->billing_day) {
            return $out;
        }

        $next = self::cycleNextFromDay((int) $this->billing_day);
        for ($i = 0; $i < $count; $i++) {
            $out->push($next->copy()->addMonthsNoOverflow($i));
        }

        return $out;
    }

    /**
     * Given a billing-day (1-31), return the next future date at that
     * day-of-month. If today's day-of-month is already past the billing day,
     * the next cycle is next month. Clamped to the last day of the month for
     * short months (e.g. 31 → 28/29/30).
     */
    public static function cycleNextFromDay(int $day): \Illuminate\Support\Carbon
    {
        $day = max(1, min(31, $day));
        $now = now()->startOfDay();

        $candidate = $now->copy()->day(min($day, $now->daysInMonth));
        if ($candidate->lt($now)) {
            $candidate = $now->copy()->addMonthNoOverflow();
            $candidate = $candidate->day(min($day, $candidate->daysInMonth));
        }

        return $candidate;
    }
}
