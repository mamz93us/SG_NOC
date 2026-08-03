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
        'gender',
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
     * Get identity_group_id values for active mappings that match a
     * branch + department + gender.
     *
     * Logic: (branch_id = $branchId OR branch_id IS NULL)
     *        AND (department_id = $deptId OR department_id IS NULL)
     *        AND (gender = $gender OR gender IS NULL)
     *        AND is_active = true
     *
     * NULL on a mapping means "any", so a mapping with no gender set still
     * matches everyone — which is what keeps pre-gender mappings working.
     * A NULL *argument* (employee with no gender recorded) matches only the
     * gender-agnostic mappings, never a male- or female-specific one.
     *
     * Returns a Collection of identity_group_id integers.
     */
    public static function getGroupsFor(?int $branchId, ?int $deptId, ?string $gender = null): Collection
    {
        $gender = $gender ? strtolower(trim($gender)) : null;

        return static::active()
            ->where(function (Builder $q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereNull('branch_id');
            })
            ->where(function (Builder $q) use ($deptId) {
                $q->where('department_id', $deptId)
                  ->orWhereNull('department_id');
            })
            ->where(function (Builder $q) use ($gender) {
                $q->whereNull('gender');
                if ($gender !== null) {
                    $q->orWhereRaw('LOWER(gender) = ?', [$gender]);
                }
            })
            ->pluck('identity_group_id');
    }
}
