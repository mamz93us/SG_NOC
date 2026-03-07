<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    // Only created_at (append-only)
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'type',
        'severity',
        'title',
        'message',
        'link',
        'is_read',
        'created_at',
    ];

    protected $casts = [
        'is_read'    => 'boolean',
        'created_at' => 'datetime',
    ];

    // ─────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ─────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────

    public function scopeUnread(Builder $query): Builder
    {
        return $query->where('is_read', false);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    public function severityBorderClass(): string
    {
        return match ($this->severity) {
            'critical' => 'border-danger',
            'warning'  => 'border-warning',
            'info'     => 'border-info',
            default    => 'border-secondary',
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
            'critical' => 'bi-exclamation-octagon-fill text-danger',
            'warning'  => 'bi-exclamation-triangle-fill text-warning',
            'info'     => 'bi-info-circle-fill text-info',
            default    => 'bi-bell-fill text-secondary',
        };
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'approval_request'  => 'Approval Required',
            'approval_action'   => 'Approval Update',
            'workflow_complete' => 'Workflow Completed',
            'workflow_failed'   => 'Workflow Failed',
            'system_alert'      => 'System Alert',
            'noc_alert'         => 'NOC Alert',
            'printer_maintenance' => 'Printer Maintenance',
            default             => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }
}
