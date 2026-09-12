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
        'device_name',
        'device_user_id',
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

    /**
     * The Oracle number(s) this code is looked up as — what EmployeeLinker
     * searches for, and what the mapping page shows so a non-match is
     * explicable.
     *
     * A source may declare a prefix (Cairo: 55, so badge 512 is Oracle 55512).
     * The prefix REPLACES the raw form rather than joining it: searching both
     * would match the Cairo employee AND the Saudi one who really holds 512,
     * which is the collision the prefix exists to remove.
     *
     * @return list<string>
     */
    public function lookupCodes(): array
    {
        $code = trim((string) $this->emp_code);
        $forms = array_filter([$code, ltrim($code, '0')], fn ($v) => $v !== '');

        if (($prefix = $this->source?->codePrefix() ?: '') !== '') {
            $forms = array_map(fn ($v) => $prefix.$v, $forms);
        }

        return array_values(array_unique($forms));
    }

    /** The number shown next to the code on the mapping page, when it differs from it. */
    public function lookupCode(): ?string
    {
        return $this->lookupCodes()[0] ?? null;
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
