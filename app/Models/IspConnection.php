<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IspConnection extends Model
{
    protected $fillable = [
        'branch_id',
        'provider',
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
        'monthly_cost',
        'notes',
    ];

    protected $casts = [
        'speed_down'          => 'integer',
        'speed_up'            => 'integer',
        'monthly_cost'        => 'decimal:2',
        'contract_start'      => 'date',
        'contract_end'        => 'date',
        'renewal_date'        => 'date',
        'renewal_remind_days' => 'integer',
        'renewal_reminded_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function routerDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'router_device_id');
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
            && !$this->contract_end->isPast()
            && $this->contract_end->diffInDays(now()) <= $days;
    }

    public function contractStatusBadge(): string
    {
        if (!$this->contract_end) return 'bg-secondary';
        if ($this->isContractExpired()) return 'bg-danger';
        if ($this->isContractExpiring()) return 'bg-warning text-dark';
        return 'bg-success';
    }

    public function contractStatusLabel(): string
    {
        if (!$this->contract_end) return 'No Contract';
        if ($this->isContractExpired()) return 'Expired';
        if ($this->isContractExpiring()) return 'Expiring Soon';
        return 'Active';
    }

    public function speedLabel(): string
    {
        if (!$this->speed_down && !$this->speed_up) return '-';
        return ($this->speed_down ?? '?') . '/' . ($this->speed_up ?? '?') . ' Mbps';
    }

    // ─── Renewal helpers ─────────────────────────────────────────

    public function isRenewalDue(): bool
    {
        return $this->renewal_date && $this->renewal_date->isPast();
    }

    public function isRenewalSoon(int $days = null): bool
    {
        $days = $days ?? ($this->renewal_remind_days ?: 2);

        return $this->renewal_date
            && !$this->renewal_date->isPast()
            && $this->renewal_date->diffInDays(now()) <= $days;
    }

    public function renewalStatusBadge(): string
    {
        if (!$this->renewal_date) return 'bg-secondary';
        if ($this->isRenewalDue()) return 'bg-danger';
        if ($this->isRenewalSoon()) return 'bg-warning text-dark';
        return 'bg-success';
    }

    public function renewalStatusLabel(): string
    {
        if (!$this->renewal_date) return 'No Date';
        if ($this->isRenewalDue()) return 'Overdue';
        if ($this->isRenewalSoon()) return 'Due Soon';
        return 'OK';
    }

    /**
     * Whether this ISP needs a renewal reminder right now.
     */
    public function needsRenewalReminder(): bool
    {
        if (!$this->renewal_date) return false;

        $days = $this->renewal_remind_days ?: 2;
        $reminderDate = $this->renewal_date->copy()->subDays($days);

        // Not yet time for the reminder
        if (now()->lt($reminderDate)) return false;

        // Already sent a reminder for this renewal cycle
        if ($this->renewal_reminded_at && $this->renewal_reminded_at->gte($reminderDate)) {
            return false;
        }

        return true;
    }
}
