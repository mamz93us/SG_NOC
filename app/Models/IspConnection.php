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
        'monthly_cost',
        'notes',
    ];

    protected $casts = [
        'speed_down'     => 'integer',
        'speed_up'       => 'integer',
        'monthly_cost'   => 'decimal:2',
        'contract_start' => 'date',
        'contract_end'   => 'date',
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
}
