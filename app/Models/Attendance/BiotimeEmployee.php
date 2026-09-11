<?php

namespace App\Models\Attendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A BioTime emp_code (per source) and the NOC employee it belongs to.
 *
 * Linked by Services\Attendance\EmployeeLinker. A `manual` row is HR's call
 * and the auto-matcher never overwrites it; `manual` with no employee means
 * HR confirmed the code is not a NOC employee (a visitor, a contractor).
 */
class BiotimeEmployee extends Model
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
        'biotime_source_id',
        'emp_code',
        'employee_id',
        'match_method',
        'candidate_ids',
        'areas',
        'terminals',
        'first_punch_at',
        'last_punch_at',
        'confirmed_by',
        'confirmed_at',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'candidate_ids' => 'array',
        'areas' => 'array',
        'terminals' => 'array',
        'first_punch_at' => 'datetime',
        'last_punch_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiotimeSource::class, 'biotime_source_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isManual(): bool
    {
        return $this->match_method === self::METHOD_MANUAL;
    }

    public function isConfirmedNotEmployee(): bool
    {
        return $this->isManual() && ! $this->employee_id;
    }

    public function methodLabel(): string
    {
        if ($this->isConfirmedNotEmployee()) {
            return 'Not an employee';
        }

        return self::METHOD_LABELS[$this->match_method] ?? 'Pending';
    }

    /** Codes with no employee that HR has not yet ruled on. */
    public static function unmappedCount(): int
    {
        return static::query()
            ->whereNull('employee_id')
            ->where(fn ($q) => $q->whereNull('match_method')->orWhere('match_method', '!=', self::METHOD_MANUAL))
            ->count();
    }
}
