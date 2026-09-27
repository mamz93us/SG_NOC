<?php

namespace App\Models\Attendance;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A raw BioTime punch, copied verbatim. HR never edits one — corrections are
 * AttendanceAdjustment rows laid over the day, see attendance_days.
 *
 * The source database may still change its own row, and then this copy
 * follows it: Services\Attendance\PunchMirror re-reads a window, rewrites
 * punches whose fields moved and stamps `removed_at` on the ones the source
 * no longer holds. Soft-deleting on that column is what keeps a removed punch
 * out of the day processor, the monthly sheet and the Oracle feed without
 * each of them having to remember.
 *
 * punch_time is the device's wall clock; it is read and shown as-is and must
 * never be passed through setTimezone().
 */
class AttendancePunch extends Model
{
    use SoftDeletes;

    /** Stamped, never deleted — the house name for "the source no longer lists it". */
    public const DELETED_AT = 'removed_at';

    public $timestamps = false;

    /**
     * BioTime's punch_state codes, plus the legacy table's CHECKTYPE. Stored
     * for reference; they do not decide in/out — earliest and latest punch do.
     */
    public const STATES = [
        '0' => 'Check in',
        '1' => 'Check out',
        '2' => 'Break out',
        '3' => 'Break in',
        '4' => 'Overtime in',
        '5' => 'Overtime out',
        'I' => 'Check in',
        'O' => 'Check out',
    ];

    protected $fillable = [
        'biotime_source_id',
        'biotime_id',
        'external_id',
        'biotime_employee_id',
        'employee_id',
        'emp_code',
        'punch_time',
        'punch_state',
        'terminal_sn',
        'terminal_alias',
        'area_alias',
        'synced_at',
        'removed_at',
    ];

    protected $casts = [
        'biotime_id' => 'integer',
        'employee_id' => 'integer',
        'punch_time' => 'datetime',
        'synced_at' => 'datetime',
        'removed_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BiotimeSource::class, 'biotime_source_id');
    }

    public function biotimeEmployee(): BelongsTo
    {
        return $this->belongsTo(BiotimeEmployee::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function stateLabel(): string
    {
        $state = (string) $this->punch_state;

        return self::STATES[$state] ?? self::STATES[strtoupper($state)] ?? '—';
    }

    /** The subject key the day processor files this punch under. */
    public function subjectKey(): string
    {
        return $this->employee_id ? 'emp:'.$this->employee_id : 'bt:'.$this->biotime_employee_id;
    }
}
