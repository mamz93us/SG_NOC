<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntuneGroupPolicy extends Model
{
    protected $fillable = [
        'intune_group_id', 'policy_type', 'intune_policy_id',
        'policy_name', 'policy_payload', 'status',
    ];

    protected $casts = [
        'policy_payload' => 'array',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function group(): BelongsTo
    {
        return $this->belongsTo(IntuneGroup::class, 'intune_group_id');
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'assigned' => 'bg-success',
            'pending'  => 'bg-warning text-dark',
            'error'    => 'bg-danger',
            default    => 'bg-secondary',
        };
    }
}
