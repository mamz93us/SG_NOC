<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpamSubnet extends Model
{
    protected $fillable = [
        'branch_id',
        'vlan',
        'cidr',
        'gateway',
        'description',
        'source',
        'total_ips',
    ];

    protected $casts = [
        'vlan'      => 'integer',
        'total_ips' => 'integer',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function ipReservations(): HasMany
    {
        return $this->hasMany(IpReservation::class, 'subnet_id');
    }

    public function dhcpLeases(): HasMany
    {
        return $this->hasMany(DhcpLease::class, 'subnet_id');
    }

    // ─── CIDR Helpers ─────────────────────────────────────────────

    /**
     * Parse CIDR into [network_long, prefix_length].
     */
    protected function parseCidr(): array
    {
        $parts = explode('/', $this->cidr);
        $ip     = $parts[0] ?? '0.0.0.0';
        $prefix = (int) ($parts[1] ?? 24);

        return [ip2long($ip), $prefix];
    }

    public function networkAddress(): string
    {
        [$network, $prefix] = $this->parseCidr();
        $mask = $prefix > 0 ? (~0 << (32 - $prefix)) : 0;
        return long2ip($network & $mask);
    }

    public function broadcastAddress(): string
    {
        [$network, $prefix] = $this->parseCidr();
        $mask     = $prefix > 0 ? (~0 << (32 - $prefix)) : 0;
        $hostMask = ~$mask & 0xFFFFFFFF;
        return long2ip(($network & $mask) | $hostMask);
    }

    /**
     * Total number of usable IPs (excludes network + broadcast for /30 and larger).
     */
    public function computeTotalIps(): int
    {
        [, $prefix] = $this->parseCidr();
        $total = pow(2, 32 - $prefix);
        return $total > 2 ? $total - 2 : $total;
    }

    /**
     * Return all usable IPs in the subnet as an array of strings.
     */
    public function allIps(): array
    {
        [$network, $prefix] = $this->parseCidr();
        $mask  = $prefix > 0 ? (~0 << (32 - $prefix)) : 0;
        $start = ($network & $mask) + 1;                     // skip network address
        $end   = (($network & $mask) | (~$mask & 0xFFFFFFFF)) - 1; // skip broadcast

        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    /**
     * Check if an IP falls within this subnet.
     */
    public function containsIp(string $ip): bool
    {
        [$network, $prefix] = $this->parseCidr();
        $mask   = $prefix > 0 ? (~0 << (32 - $prefix)) : 0;
        $ipLong = ip2long($ip);

        return ($ipLong & $mask) === ($network & $mask);
    }

    // ─── Utilization ──────────────────────────────────────────────

    public function usedCount(): int
    {
        return $this->ipReservations()->count() + $this->dhcpLeases()->count();
    }

    public function availableCount(): int
    {
        $total = $this->total_ips ?: $this->computeTotalIps();
        return max(0, $total - $this->usedCount());
    }

    public function utilizationPercent(): int
    {
        $total = $this->total_ips ?: $this->computeTotalIps();
        if ($total === 0) return 0;
        return (int) round($this->usedCount() / $total * 100);
    }

    // ─── Boot ─────────────────────────────────────────────────────

    protected static function booted(): void
    {
        static::saving(function (self $subnet) {
            if ($subnet->cidr && !$subnet->total_ips) {
                $subnet->total_ips = $subnet->computeTotalIps();
            }
        });
    }
}
