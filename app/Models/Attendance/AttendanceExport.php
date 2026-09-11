<?php

namespace App\Models\Attendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to hand an approved period to Oracle, with the exact payload.
 *
 * `payload` is JSON text, deliberately not cast: a month for every employee
 * runs to megabytes, so lists never select it and only a download decodes it.
 */
class AttendanceExport extends Model
{
    public const PREPARED = 'prepared';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'attendance_period_id',
        'sender',
        'status',
        'record_count',
        'payload',
        'response',
        'reference',
        'message',
        'created_by',
    ];

    protected $casts = [
        'record_count' => 'integer',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(AttendancePeriod::class, 'attendance_period_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
