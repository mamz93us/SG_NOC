<?php

namespace App\Models;

use App\Services\BranchHealth\BranchHealthConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UcmTrunkCache extends Model
{
    protected $table = 'ucm_trunks_cache';

    protected $fillable = [
        'ucm_id', 'trunk_name', 'trunk_index',
        'host', 'status', 'last_checked_at',
    ];

    protected $casts = [
        'last_checked_at' => 'datetime',
    ];

    public function ucmServer(): BelongsTo
    {
        return $this->belongsTo(UcmServer::class, 'ucm_id');
    }

    /**
     * Statuses that mean the trunk is genuinely carrying calls.
     *
     * Whitelisted rather than blacklisted on purpose. The previous
     * implementation was `! str_contains($status, 'unreachable')`, which counted
     * '', 'unknown', 'rejected', 'timeout' and 'unregistered' as healthy — so a
     * trunk that had never been probed, or that the PBX was actively rejecting,
     * scored the same as a working one.
     */
    public const HEALTHY_STATUSES = ['reachable', 'registered', 'active', 'online', 'up', 'ok'];

    /** Statuses that are a definite, reportable failure. */
    public const FAILED_STATUSES = [
        'unreachable', 'unregistered', 'failed', 'rejected',
        'timeout', 'lagged', 'offline', 'down', 'error',
    ];

    /**
     * The status word, lowercased and stripped of any trailing detail.
     *
     * The UCM decorates statuses ("Reachable (12 ms)"), and substring matching
     * is a trap here because 'unreachable' contains 'reachable'. Taking the
     * leading word makes the comparison exact.
     */
    public function normalizedStatus(): string
    {
        preg_match('/[a-z]+/', strtolower(trim((string) $this->status)), $m);

        return $m[0] ?? '';
    }

    public function isReachable(): bool
    {
        return in_array($this->normalizedStatus(), self::HEALTHY_STATUSES, true);
    }

    public function isFailed(): bool
    {
        return in_array($this->normalizedStatus(), self::FAILED_STATUSES, true);
    }

    /**
     * Neither confirmed good nor confirmed bad — an empty or unrecognised
     * status. Scored as unknown, never as a pass.
     */
    public function isUnknownStatus(): bool
    {
        return ! $this->isReachable() && ! $this->isFailed();
    }

    /** Has the sync touched this row recently enough to believe it? */
    public function isFresh(?int $withinMinutes = null): bool
    {
        if (! $this->last_checked_at) {
            return false;
        }

        $window = $withinMinutes ?? BranchHealthConfig::int('freshness.ucm_trunk', 2);

        return $this->last_checked_at->gte(now()->subMinutes($window));
    }

    public function statusBadgeClass(): string
    {
        if ($this->isReachable()) {
            return 'bg-success';
        }

        return $this->isFailed() ? 'bg-danger' : 'bg-secondary';
    }
}
