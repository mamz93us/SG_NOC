<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntuneGroupMember extends Model
{
    protected $fillable = [
        'intune_group_id', 'azure_user_id', 'user_upn', 'display_name', 'status',
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
            'added'   => 'bg-success',
            'pending' => 'bg-warning text-dark',
            'removed' => 'bg-secondary',
            'error'   => 'bg-danger',
            default   => 'bg-secondary',
        };
    }
}
