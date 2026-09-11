<?php

namespace App\Models\Attendance;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A pay period — a date range for one branch or all of them — approved as one
 * unit and handed to Oracle. See AttendancePeriodService.
 */
class AttendancePeriod extends Model
{
    use StoresPlainDates;

    public const OPEN = 'open';

    public const APPROVED = 'approved';

    public const SENT = 'sent';

    /** Statuses whose days are frozen. */
    public const LOCKING = [self::APPROVED, self::SENT];

    public const STATUS_LABELS = [
        self::OPEN => 'Open',
        self::APPROVED => 'Approved — locked',
        self::SENT => 'Sent to Oracle',
    ];

    protected array $plainDates = ['date_from', 'date_to'];

    protected $fillable = [
        'name',
        'branch_id',
        'date_from',
        'date_to',
        'status',
        'created_by',
    ];

    protected $casts = [
        'branch_id' => 'integer',
        'date_from' => 'date',
        'date_to' => 'date',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
        'reopened_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(AttendanceExport::class);
    }

    public function latestExport(): HasOne
    {
        return $this->hasOne(AttendanceExport::class)->latestOfMany();
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reopenedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKING, true);
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? ucfirst((string) $this->status);
    }

    public function scopeLabel(): string
    {
        return $this->branch?->name ?? 'All branches';
    }

    /** The days this period covers. */
    public function daysQuery(): Builder
    {
        return AttendanceDay::query()
            ->whereBetween('work_date', [$this->date_from->toDateString(), $this->date_to->toDateString()])
            ->when($this->branch_id, fn ($q) => $q->where('branch_id', $this->branch_id));
    }

    /**
     * Periods that share a day with this range and could cover the same people:
     * the same branch, or either side covering every branch.
     */
    public static function overlapping(string $from, string $to, ?int $branchId, ?int $exceptId = null): Builder
    {
        return static::query()
            ->where('date_from', '<=', $to)
            ->where('date_to', '>=', $from)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))
            ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)));
    }
}
