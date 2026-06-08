<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A branch VM running `sg-branch-agent`.
 *
 * Mirrors BranchLogCollector's token handling (encrypted at rest, hidden
 * from array casts). The same api_token is the shared secret the agent
 * uses to call the NOC and the NOC uses to query the agent's log API.
 */
class BranchAgent extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'hostname',
        'port',
        'api_token',
        'enrollment_code',
        'enrollment_expires_at',
        'enabled',
        'agent_version',
        'last_heartbeat_at',
        'last_health',
        'status',
        'wan_ip',
        'wan_ip_updated_at',
        'dns_domain',
        'dns_subdomain',
        'dns_account_id',
        'vpn_tunnel_id',
        'notes',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'enabled' => 'boolean',
        'enrollment_expires_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'last_health' => 'array',
        'wan_ip_updated_at' => 'datetime',
    ];

    /** Hide the encrypted token from default API responses / array casts. */
    protected $hidden = ['api_token'];

    // ─── Scopes ──────────────────────────────────────────────────────

    /** Enabled agents that have completed enrollment (token issued). */
    public function scopeReady($query)
    {
        return $query->where('enabled', true)->whereNotNull('api_token');
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function wanIpHistory(): HasMany
    {
        return $this->hasMany(BranchAgentWanIpHistory::class)->latest('changed_at');
    }

    public function vpnTunnel(): BelongsTo
    {
        return $this->belongsTo(VpnTunnel::class);
    }

    public function dnsAccount(): BelongsTo
    {
        return $this->belongsTo(DnsAccount::class);
    }

    /** The log-collector row that backs NOC-side log search for this branch. */
    public function logCollector(): ?BranchLogCollector
    {
        return BranchLogCollector::where('code', $this->code)->first();
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /** Fully-qualified DDNS name, e.g. jed.branch.samirgroup.net. */
    public function fqdn(): ?string
    {
        if (! $this->dns_subdomain || ! $this->dns_domain) {
            return null;
        }

        return "{$this->dns_subdomain}.{$this->dns_domain}";
    }

    /** True while the enrollment code is set and still within its TTL. */
    public function enrollmentPending(): bool
    {
        return $this->enrollment_code !== null
            && $this->enrollment_expires_at !== null
            && $this->enrollment_expires_at->isFuture();
    }

    /**
     * Recompute health status from last_heartbeat_at against the staleness
     * threshold. Returns the computed status without saving.
     */
    public function computeStatus(): string
    {
        if (! $this->api_token) {
            return 'pending';
        }
        if (! $this->last_heartbeat_at) {
            return 'pending';
        }

        $staleAfter = (int) config('branch_agents.heartbeat_stale_seconds', 600);
        $downAfter = (int) config('branch_agents.heartbeat_down_seconds', 1800);
        $age = now()->diffInSeconds($this->last_heartbeat_at, true);

        return match (true) {
            $age >= $downAfter => 'down',
            $age >= $staleAfter => 'stale',
            default => 'healthy',
        };
    }
}
