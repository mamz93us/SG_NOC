<?php

namespace App\Models\Itam;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\Device;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One laptop or desktop in Oracle's fixed-asset register, and the NOC employee
 * and asset it is.
 *
 * Oracle's columns are kept as the export wrote them. Everything else is the
 * NOC's reading, written by Services\Itam\Oracle\OracleAssetImporter and
 * OracleAssetResolver, and by a person on the register page:
 *
 * - employee_match: how the Oracle employee number became a NOC employee —
 *   by number, by number narrowed to the register's branches, by name for an
 *   employee the NOC holds no Oracle number for, or by hand. A manual link is
 *   never re-decided.
 * - device_match: how the unit became a NOC asset — the same serial, the same
 *   model as one of the holder's Intune devices, the holder's only Intune
 *   device of that brand, a new asset created for it, or by hand.
 *   `match_evidence` keeps the reasons, and for a unit no Intune device
 *   matched, the closest candidates for a person to look at.
 *
 * A unit with a NOC asset is never re-decided by the next import. One whose
 * asset was later deleted keeps its device_match and shows as deleted rather
 * than being recreated behind someone's back.
 */
class OracleAsset extends Model
{
    use StoresPlainDates;

    public const CATEGORY_LAPTOP = 'laptop';

    public const CATEGORY_DESKTOP = 'desktop';

    public const CATEGORIES = [self::CATEGORY_LAPTOP, self::CATEGORY_DESKTOP];

    public const CATEGORY_LABELS = [
        self::CATEGORY_LAPTOP => 'Laptop',
        self::CATEGORY_DESKTOP => 'Desktop',
    ];

    public const EMPLOYEE_NUMBER = 'number';

    public const EMPLOYEE_NUMBER_BRANCH = 'number_branch';

    public const EMPLOYEE_NAME = 'name';

    public const EMPLOYEE_AMBIGUOUS = 'ambiguous';

    public const EMPLOYEE_NONE = 'none';

    public const EMPLOYEE_MANUAL = 'manual';

    public const EMPLOYEE_MATCH_LABELS = [
        self::EMPLOYEE_NUMBER => 'Oracle number',
        self::EMPLOYEE_NUMBER_BRANCH => 'Oracle number (branch)',
        self::EMPLOYEE_NAME => 'Matched by name',
        self::EMPLOYEE_AMBIGUOUS => 'Several employees',
        self::EMPLOYEE_NONE => 'Not in the NOC',
        self::EMPLOYEE_MANUAL => 'Set by hand',
    ];

    public const DEVICE_SERIAL = 'serial';

    public const DEVICE_MODEL = 'model';

    public const DEVICE_ONLY_PAIR = 'only_pair';

    public const DEVICE_CREATED = 'created';

    public const DEVICE_MANUAL = 'manual';

    public const DEVICE_MATCH_LABELS = [
        self::DEVICE_SERIAL => 'Same serial',
        self::DEVICE_MODEL => 'Same model',
        self::DEVICE_ONLY_PAIR => 'Only one of that brand',
        self::DEVICE_CREATED => 'New asset',
        self::DEVICE_MANUAL => 'Linked by hand',
    ];

    protected array $plainDates = ['purchase_date', 'end_date'];

    protected $fillable = [
        'asset_number',
        'unit',
        'description',
        'purchase_date',
        'end_date',
        'emp_no',
        'emp_name',
        'category',
        'employee_id',
        'employee_match',
        'employee_candidates',
        'device_id',
        'device_match',
        'match_evidence',
        'decided_by',
        'decided_at',
        'first_import_id',
        'last_import_id',
        'removed_at',
    ];

    protected $casts = [
        'unit' => 'integer',
        'purchase_date' => 'date',
        'end_date' => 'date',
        'employee_candidates' => 'array',
        'match_evidence' => 'array',
        'decided_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function lastImport(): BelongsTo
    {
        return $this->belongsTo(OracleAssetImport::class, 'last_import_id');
    }

    public function scopeListed(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /** The key a unit is found by on the next import. */
    public static function keyOf(string $assetNumber, string $empNo, int $unit): string
    {
        return $assetNumber.'|'.$empNo.'|'.$unit;
    }

    public function key(): string
    {
        return self::keyOf($this->asset_number, $this->emp_no, (int) $this->unit);
    }

    /** Decided already: it has a NOC asset, now or before that asset was deleted. */
    public function isResolved(): bool
    {
        return $this->device_match !== null;
    }

    /** The NOC asset type the unit becomes. */
    public function deviceType(): string
    {
        return $this->category === self::CATEGORY_DESKTOP ? 'desktop' : 'laptop';
    }

    public function categoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->category] ?? ucfirst((string) $this->category);
    }

    public function employeeMatchLabel(): ?string
    {
        return $this->employee_match ? (self::EMPLOYEE_MATCH_LABELS[$this->employee_match] ?? $this->employee_match) : null;
    }

    public function deviceMatchLabel(): ?string
    {
        return $this->device_match ? (self::DEVICE_MATCH_LABELS[$this->device_match] ?? $this->device_match) : null;
    }

    /** Oracle's life in whole years (End Date − purchase), or null when it cannot be read. */
    public function lifeYears(): ?int
    {
        if (! $this->purchase_date || ! $this->end_date || $this->end_date->lessThanOrEqualTo($this->purchase_date)) {
            return null;
        }

        $years = (int) round($this->purchase_date->diffInDays($this->end_date) / 365.25);

        return $years >= 1 && $years <= 30 ? $years : null;
    }
}
