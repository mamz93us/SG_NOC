<?php

namespace App\Models\Vacation;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One Oracle person number in one book (config/vacations.php) and the NOC
 * employee it is. Balances and leave records hang off this row, so a person
 * Oracle knows and the NOC does not is still imported and waits for HR.
 *
 * Linked by Services\Vacation\VacationLinker. A `manual` row is HR's decision
 * and no import overwrites it; `manual` with no employee means HR confirmed
 * the number is nobody in the NOC.
 */
class VacationEmployee extends Model
{
    public const METHOD_AUTO = 'auto_empno';

    public const METHOD_AUTO_BRANCH = 'auto_empno_branch';

    public const METHOD_MANUAL = 'manual';

    public const METHOD_AMBIGUOUS = 'ambiguous';

    public const METHOD_NONE = 'none';

    public const METHOD_LABELS = [
        self::METHOD_AUTO => 'Auto: Oracle no.',
        self::METHOD_AUTO_BRANCH => 'Auto: Oracle no. + branch',
        self::METHOD_MANUAL => 'Manual',
        self::METHOD_AMBIGUOUS => 'Ambiguous',
        self::METHOD_NONE => 'No match',
    ];

    protected $fillable = [
        'book',
        'oracle_emp_no',
        'oracle_person_id',
        'employee_id',
        'match_method',
        'candidate_ids',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'candidate_ids' => 'array',
        'confirmed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(VacationBalance::class);
    }

    public function absences(): HasMany
    {
        return $this->hasMany(VacationAbsence::class);
    }

    /** @return array<string, array{label: string, branches: list<string>, weekend: list<int>}> */
    public static function books(): array
    {
        return (array) config('vacations.books', []);
    }

    public static function defaultBook(): string
    {
        $default = (string) config('vacations.default_book', '');

        return array_key_exists($default, static::books()) ? $default : (string) array_key_first(static::books());
    }

    public static function bookLabel(string $book): string
    {
        return static::books()[$book]['label'] ?? $book;
    }

    public function isManual(): bool
    {
        return $this->match_method === self::METHOD_MANUAL;
    }

    public function isConfirmedNotEmployee(): bool
    {
        return $this->isManual() && ! $this->employee_id;
    }

    /** No employee, and HR has not said it is nobody. */
    public function needsLink(): bool
    {
        return ! $this->employee_id && ! $this->isManual();
    }

    public function methodLabel(): string
    {
        if ($this->isConfirmedNotEmployee()) {
            return 'Not an employee';
        }

        return self::METHOD_LABELS[$this->match_method] ?? 'Pending';
    }

    /** The year's balance, read from the loaded `balances` relation. */
    public function balanceFor(int $year): ?VacationBalance
    {
        return $this->balances->firstWhere('year', $year);
    }

    /**
     * The Oracle person records of one NOC employee. Links are only ever made
     * to primary records, so a linked secondary mailbox reads its primary's.
     *
     * @return Collection<int, self>
     */
    public static function forEmployee(Employee $employee): Collection
    {
        return static::query()
            ->where('employee_id', $employee->linked_primary_employee_id ?: $employee->id)
            ->with('balances')
            ->orderBy('id')
            ->get();
    }

    /** People in Oracle's sheets with no employee that HR has not yet ruled on. */
    public static function unlinkedCount(): int
    {
        return static::query()
            ->whereNull('employee_id')
            ->where(fn ($q) => $q->whereNull('match_method')->orWhere('match_method', '!=', self::METHOD_MANUAL))
            ->count();
    }
}
