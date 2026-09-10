<?php

namespace App\Models\Attendance;

use App\Models\Attendance\Concerns\StoresPlainDates;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A public or company holiday — for every branch, or one branch (Egypt and
 * KSA keep different calendars). No absence is recorded on one; punches on
 * it count as overtime.
 */
class AttendanceHoliday extends Model
{
    use StoresPlainDates;

    protected array $plainDates = ['holiday_date'];

    protected $fillable = ['holiday_date', 'name', 'branch_id'];

    protected $casts = [
        'holiday_date' => 'date',
        'branch_id' => 'integer',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
