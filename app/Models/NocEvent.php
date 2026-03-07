<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NocEvent extends Model
{
    protected $fillable = [
        'module',
        'entity_type',
        'entity_id',
        'severity',
        'title',
        'message',
        'first_seen',
        'last_seen',
        'status',
        'acknowledged_by',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'first_seen'  => 'datetime',
        'last_seen'   => 'datetime',
        'resolved_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'acknowledged']);
    }

    public function scopeByModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }

    public function scopeBySeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('severity', 'critical');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'open'         => 'bg-danger',
            'acknowledged' => 'bg-warning text-dark',
            'resolved'     => 'bg-success',
            default        => 'bg-secondary',
        };
    }

    public function severityBadgeClass(): string
    {
        return match ($this->severity) {
            'critical' => 'bg-danger',
            'warning'  => 'bg-warning text-dark',
            'info'     => 'bg-info text-dark',
            default    => 'bg-secondary',
        };
    }

    public function severityIcon(): string
    {
        return match ($this->severity) {
            'critical' => 'bi-exclamation-octagon-fill',
            'warning'  => 'bi-exclamation-triangle-fill',
            'info'     => 'bi-info-circle-fill',
            default    => 'bi-circle-fill',
        };
    }

    public function moduleIcon(): string
    {
        return match ($this->module) {
            'network'  => 'bi-diagram-3-fill',
            'identity' => 'bi-people-fill',
            'voip'     => 'bi-telephone-fill',
            'assets'   => 'bi-cpu-fill',
            default    => 'bi-exclamation-circle',
        };
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function durationHuman(): string
    {
        return $this->first_seen->diffForHumans();
    }
}
