<?php

namespace App\Models\Attendance;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One person on the attendance owner list: they may ask the home-portal
 * assistant about the attendance of everyone in the company, or of everyone in
 * the branches listed here — a general manager, a branch GM. Their own direct
 * reports need no row; manager_id / supervisor_id already say so.
 *
 * Read by AssistantToolbox::team(), edited at Attendance ▸ Owners. The row is
 * always on the primary record: a linked secondary mailbox is the same person,
 * and the toolbox looks the row up under both ids.
 */
class AttendanceOwner extends Model
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_BRANCHES = 'branches';

    public const SCOPES = [
        self::SCOPE_COMPANY => 'Whole company',
        self::SCOPE_BRANCHES => 'Chosen branches',
    ];

    protected $fillable = [
        'employee_id',
        'scope',
        'branch_ids',
        'title',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'branch_ids' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompanyWide(): bool
    {
        return $this->scope === self::SCOPE_COMPANY;
    }

    /** @return list<int> the branches; empty for the whole company */
    public function branchIds(): array
    {
        if ($this->isCompanyWide()) {
            return [];
        }

        return array_values(array_unique(array_map('intval', (array) $this->branch_ids)));
    }

    /**
     * What one person's rows add up to. Branches stop mattering once any row
     * covers the whole company.
     *
     * @param  list<int>  $employeeIds  one person: their record and the primary it mirrors
     * @return array{company: bool, branch_ids: list<int>}
     */
    public static function accessFor(array $employeeIds): array
    {
        $rows = $employeeIds === [] ? collect() : static::query()->whereIn('employee_id', $employeeIds)->get();

        if ($rows->contains(fn (self $owner) => $owner->isCompanyWide())) {
            return ['company' => true, 'branch_ids' => []];
        }

        return [
            'company' => false,
            'branch_ids' => $rows->flatMap(fn (self $owner) => $owner->branchIds())->unique()->sort()->values()->all(),
        ];
    }

    /**
     * "Whole company", or the branch names.
     *
     * @param  Collection<int, Branch>  $branches  keyed by id
     */
    public function accessLabel(Collection $branches): string
    {
        if ($this->isCompanyWide()) {
            return self::SCOPES[self::SCOPE_COMPANY];
        }

        $names = collect($this->branchIds())
            ->map(fn (int $id) => $branches->get($id)?->name ?? "deleted branch #{$id}")
            ->sort()
            ->implode(', ');

        return $names !== '' ? $names : 'no branches';
    }
}
