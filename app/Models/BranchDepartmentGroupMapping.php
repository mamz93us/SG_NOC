<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchDepartmentGroupMapping extends Model
{
    protected $fillable = [
        'branch_id',
        'department_id',
        'azure_group_id',
        'azure_group_name',
        'notes',
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

    // ─── Static helpers ───────────────────────────────────────────

    /**
     * Get Azure group IDs that match a given branch + department combination.
     * Returns mappings where:
     *   - branch_id = $branchId  OR  branch_id IS NULL  (wildcard)
     *   - AND department_id = $deptId  OR  department_id IS NULL  (wildcard)
     */
    public static function getGroupsFor(?int $branchId, ?int $deptId): \Illuminate\Support\Collection
    {
        return static::query()
            ->where(function ($q) use ($branchId) {
                $q->where('branch_id', $branchId)
                  ->orWhereNull('branch_id');
            })
            ->where(function ($q) use ($deptId) {
                $q->where('department_id', $deptId)
                  ->orWhereNull('department_id');
            })
            ->get();
    }
}
