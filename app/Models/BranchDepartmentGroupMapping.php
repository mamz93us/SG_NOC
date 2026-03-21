<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class BranchDepartmentGroupMapping extends Model
{
    protected $fillable = [
        'branch_id',
        'department_id',
        'identity_group_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function identityGroup(): BelongsTo
    {
        return $this->belongsTo(IdentityGroup::class, 'identity_group_id');
    }

    // ─── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    // ─── Static helpers ───────────────────────────────────────────

    /**
     * Get identity_group_id values for active mappings that match a branch + department.
     *
     * Logic: (branch_id = $branchId OR branch_id IS NULL)
     *        AND (department_id = $deptId OR department_id IS NULL)
     *        AND is_active = true
     *
     * Returns a Collection of identity_group_id integers.
     */
    public static function getGroupsFor(?int $branchId, ?int $deptId): Collection
    {
        return static::active()
            ->where(function (Builder $q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereNull('branch_id');
            })
            ->where(function (Builder $q) use ($deptId) {
                $q->where('department_id', $deptId)
                  ->orWhereNull('department_id');
            })
            ->pluck('identity_group_id');
    }
}
