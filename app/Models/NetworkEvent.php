<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NetworkEvent extends Model
{
    protected $fillable = [
        'network_id',
        'switch_serial',
        'event_type',
        'occurred_at',
        'description',
        'details',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'details'     => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function networkSwitch(): BelongsTo
    {
        return $this->belongsTo(NetworkSwitch::class, 'switch_serial', 'serial');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    /**
     * Badge class based on event type keyword.
     */
    public function typeBadgeClass(): string
    {
        $type = strtolower($this->event_type ?? '');

        if (str_contains($type, 'error') || str_contains($type, 'fail') || str_contains($type, 'down')) {
            return 'bg-danger';
        }
        if (str_contains($type, 'warn') || str_contains($type, 'alert')) {
            return 'bg-warning text-dark';
        }
        if (str_contains($type, 'connect') || str_contains($type, 'up') || str_contains($type, 'online')) {
            return 'bg-success';
        }
        if (str_contains($type, 'change') || str_contains($type, 'update') || str_contains($type, 'config')) {
            return 'bg-info text-dark';
        }

        return 'bg-secondary';
    }
}
