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
        'floor_id',
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

    public function floor(): BelongsTo
    {
        return $this->belongsTo(NetworkFloor::class, 'floor_id');
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
     * branch + department + gender + floor.
     *
     * Every dimension follows the same rule: NULL on the mapping means "any",
     * so a mapping that leaves a field blank still matches everyone — which is
     * what keeps older, narrower mappings working after each new dimension is
     * added. A NULL *argument* (we don't know the employee's gender/floor yet)
     * matches only the mappings that are agnostic about it, never a specific
     * one — so nothing is assigned prematurely.
     *
     * Floor in particular is unknown until the manager returns the setup form,
     * which is why provisioning calls this twice: once at account creation with
     * floorId = null, and again afterwards with the real floor.
     *
     * Returns a Collection of identity_group_id integers.
     */
    public static function getGroupsFor(
        ?int $branchId,
        ?int $deptId,
        ?string $gender = null,
        ?int $floorId = null
    ): Collection {
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
            ->where(function (Builder $q) use ($floorId) {
                $q->whereNull('floor_id');
                if ($floorId !== null) {
                    $q->orWhere('floor_id', $floorId);
                }
            })
            ->pluck('identity_group_id');
    }
}
