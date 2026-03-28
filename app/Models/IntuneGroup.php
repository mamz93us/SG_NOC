<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntuneGroup extends Model
{
    protected $fillable = [
        'name', 'description', 'azure_group_id', 'group_type',
        'branch_id', 'department_id', 'sync_status', 'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function members(): HasMany
    {
        return $this->hasMany(IntuneGroupMember::class)->orderBy('display_name');
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(IntuneGroupMember::class)->where('status', 'added')->orderBy('display_name');
    }

    public function policies(): HasMany
    {
        return $this->hasMany(IntuneGroupPolicy::class)->orderByDesc('created_at');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    public function syncStatusBadgeClass(): string
    {
        return match ($this->sync_status) {
            'synced'  => 'bg-success',
            'pending' => 'bg-warning text-dark',
            'error'   => 'bg-danger',
            default   => 'bg-secondary',
        };
    }

    public function groupTypeBadgeClass(): string
    {
        return match ($this->group_type) {
            'printer'    => 'bg-primary',
            'policy'     => 'bg-info text-dark',
            'device'     => 'bg-secondary',
            'compliance' => 'bg-warning text-dark',
            default      => 'bg-secondary',
        };
    }
}
