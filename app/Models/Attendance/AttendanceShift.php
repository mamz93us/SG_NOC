<?php

namespace App\Models\Attendance;

use App\Services\Attendance\ShiftRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * When a person is due in and out. Applied to people through
 * AttendanceShiftAssignment; turned into a ShiftRule for the day builder.
 */
class AttendanceShift extends Model
{
    public const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    protected $fillable = [
        'name',
        'start_time',
        'end_time',
        'grace_in_minutes',
        'grace_out_minutes',
        'max_hours',
        'min_overtime_minutes',
        'off_days',
        'is_active',
    ];

    protected $casts = [
        'crosses_midnight' => 'boolean',
        'grace_in_minutes' => 'integer',
        'grace_out_minutes' => 'integer',
        'max_hours' => 'integer',
        'min_overtime_minutes' => 'integer',
        'off_days' => 'array',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // 22:00–06:00 ends the next day. Kept as a column so "is any shift
        // overnight?" is one cheap query.
        static::saving(function (self $shift) {
            $shift->crosses_midnight = $shift->endHm() <= $shift->startHm();
        });
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(AttendanceShiftAssignment::class);
    }

    public function startHm(): string
    {
        return substr((string) $this->start_time, 0, 5);
    }

    public function endHm(): string
    {
        return substr((string) $this->end_time, 0, 5);
    }

    public function timesLabel(): string
    {
        return $this->startHm().'–'.$this->endHm();
    }

    public function offDaysLabel(): string
    {
        $days = array_map(fn ($d) => self::DAYS[(int) $d] ?? $d, $this->off_days ?? []);

        return $days ? implode(', ', $days) : 'None';
    }

    public function toRule(): ShiftRule
    {
        return new ShiftRule(
            start: $this->startHm(),
            end: $this->endHm(),
            graceIn: (int) $this->grace_in_minutes,
            graceOut: (int) $this->grace_out_minutes,
            maxMinutes: $this->max_hours ? (int) $this->max_hours * 60 : null,
            minOvertime: (int) $this->min_overtime_minutes,
            offDays: array_values(array_map('intval', $this->off_days ?? [])),
            id: $this->id,
            name: (string) $this->name,
        );
    }
}
