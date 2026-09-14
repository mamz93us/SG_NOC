<?php

namespace App\Models\Attendance;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\Employee;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An HR edit to one person's day. Each row sets exactly one thing — the
 * check-in, the check-out, or a whole-day excuse — with its own reason. The
 * raw punches are never touched; the day is rebuilt with the edits laid over
 * them.
 *
 * One active edit per kind per person per day. A new edit revokes only the
 * previous one of the same kind, so editing the check-out leaves the check-in
 * edit and its reason in force, and the history of who changed what stays.
 * Rows from before 2026-09-14 could set both times under one reason; the
 * split migration turned each of those into one row per side.
 */
class AttendanceAdjustment extends Model
{
    use StoresPlainDates;

    public const KIND_CHECK_IN = 'check_in';

    public const KIND_CHECK_OUT = 'check_out';

    public const KIND_EXCUSE = 'excuse';

    /** The kinds, each also the column that carries its value. */
    public const KINDS = [self::KIND_CHECK_IN, self::KIND_CHECK_OUT, self::KIND_EXCUSE];

    public const EXCUSES = [
        'annual_leave' => 'Annual leave',
        'sick_leave' => 'Sick leave',
        'mission' => 'Business mission',
        'wfh' => 'Work from home',
        'permission' => 'Permission',
        'other' => 'Other',
    ];

    protected array $plainDates = ['work_date'];

    protected $fillable = [
        'employee_id',
        'work_date',
        'check_in',
        'check_out',
        'excuse',
        'reason',
        'created_by',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'employee_id' => 'integer',
        'work_date' => 'date',
        'check_in' => 'datetime',
        'check_out' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    /**
     * What this edit sets: one kind, or both times for a row older than the split.
     *
     * @return list<string>
     */
    public function kinds(): array
    {
        return array_values(array_filter(self::KINDS, fn (string $kind) => $this->{$kind} !== null));
    }

    public function summary(): string
    {
        $parts = [];
        if ($this->check_in) {
            $parts[] = 'Check-in set to '.$this->timeLabel($this->check_in);
        }
        if ($this->check_out) {
            $parts[] = 'Check-out set to '.$this->timeLabel($this->check_out);
        }
        if ($this->excuse) {
            $parts[] = 'Excused: '.(self::EXCUSES[$this->excuse] ?? $this->excuse);
        }

        return implode(' · ', $parts);
    }

    /** 17:30 — or 11 Sep 06:00 when the time falls on another date than the day. */
    public function timeLabel(DateTimeInterface $time): string
    {
        return $time->format('Y-m-d') === $this->work_date->toDateString() ? $time->format('H:i') : $time->format('d M H:i');
    }
}
