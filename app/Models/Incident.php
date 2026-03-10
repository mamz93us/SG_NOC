<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $fillable = [
        'noc_event_id',
        'branch_id',
        'title',
        'description',
        'severity',
        'status',
        'assigned_to',
        'created_by',
        'resolved_at',
        'resolution_notes',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────

    public function nocEvent(): BelongsTo
    {
        return $this->belongsTo(NocEvent::class, 'noc_event_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(IncidentComment::class)->orderBy('created_at');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'investigating']);
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    // ─── Helpers ────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return in_array($this->status, ['open', 'investigating']);
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'open'          => 'bg-danger',
            'investigating' => 'bg-warning text-dark',
            'resolved'      => 'bg-success',
            'closed'        => 'bg-secondary',
            default         => 'bg-light text-dark',
        };
    }

    public function severityBadgeClass(): string
    {
        return match ($this->severity) {
            'critical' => 'bg-danger',
            'high'     => 'bg-danger bg-opacity-75',
            'medium'   => 'bg-warning text-dark',
            'low'      => 'bg-info text-dark',
            default    => 'bg-secondary',
        };
    }

    public function durationHuman(): string
    {
        if ($this->resolved_at) {
            return $this->created_at->diffForHumans($this->resolved_at, true);
        }
        return $this->created_at->diffForHumans(null, true) . ' (ongoing)';
    }

    public static function severities(): array
    {
        return ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'];
    }

    public static function statuses(): array
    {
        return ['open' => 'Open', 'investigating' => 'Investigating', 'resolved' => 'Resolved', 'closed' => 'Closed'];
    }
}
