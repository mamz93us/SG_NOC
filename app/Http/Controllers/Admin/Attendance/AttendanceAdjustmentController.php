<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Services\Attendance\AttendanceDayProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * HR edits to a day: the check-in, the check-out or a whole-day excuse, each
 * saved on its own with its own reason. The punches are never edited. A new
 * edit revokes only the previous edit of the same kind, so one side's reason
 * never replaces the other's and the full history stays visible.
 */
class AttendanceAdjustmentController extends Controller
{
    public function store(Request $request, AttendanceDay $day, AttendanceDayProcessor $processor): RedirectResponse
    {
        if (! $day->employee_id) {
            return back()->with('error', 'Link this BioTime code to an employee first — a correction belongs to a person.');
        }

        if ($day->locked) {
            return back()->with('error', 'This day is in an approved period and is locked. Reopen the period to correct it.');
        }

        $data = $request->validate([
            'action' => ['required', Rule::in(AttendanceAdjustment::KINDS)],
            'time' => 'nullable|required_unless:action,'.AttendanceAdjustment::KIND_EXCUSE.'|date',
            'excuse' => ['nullable', 'required_if:action,'.AttendanceAdjustment::KIND_EXCUSE, Rule::in(array_keys(AttendanceAdjustment::EXCUSES))],
            'reason' => 'required|string|max:1000',
        ]);

        $kind = $data['action'];
        $date = $day->work_date->toDateString();

        if ($kind === AttendanceAdjustment::KIND_EXCUSE) {
            $value = $data['excuse'];
        } else {
            $time = CarbonImmutable::parse($data['time']);
            if ($error = $this->timeError($day, $kind, $time)) {
                return back()->withInput()->with('error', $error);
            }
            $value = $time->format('Y-m-d H:i:s');
        }

        $adjustment = DB::transaction(function () use ($day, $date, $kind, $value, $data) {
            AttendanceAdjustment::query()
                ->where('employee_id', $day->employee_id)
                ->where('work_date', $date)
                ->whereNotNull($kind)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => Auth::id()]);

            return AttendanceAdjustment::create([
                'employee_id' => $day->employee_id,
                'work_date' => $date,
                $kind => $value,
                'reason' => $data['reason'],
                'created_by' => Auth::id(),
            ]);
        });

        $this->log($adjustment, 'created');

        return $this->rebuildAndShow($processor, $day->employee_id, $date, 'Saved: '.$adjustment->summary().'.');
    }

    public function revoke(AttendanceAdjustment $adjustment, AttendanceDayProcessor $processor): RedirectResponse
    {
        if (! $adjustment->isActive()) {
            return back()->with('error', 'That correction was already revoked.');
        }

        $locked = AttendanceDay::where('subject_key', 'emp:'.$adjustment->employee_id)
            ->where('work_date', $adjustment->work_date->toDateString())
            ->value('locked');
        if ($locked) {
            return back()->with('error', 'This day is in an approved period and is locked. Reopen the period to change it.');
        }

        $adjustment->forceFill(['revoked_at' => now(), 'revoked_by' => Auth::id()])->save();
        $this->log($adjustment, 'revoked');

        return $this->rebuildAndShow($processor, $adjustment->employee_id, $adjustment->work_date->toDateString(),
            'Revoked: '.$adjustment->summary().'. That part of the day is back to what the punches say.');
    }

    /** Why this time can't be saved for this side, or null. */
    private function timeError(AttendanceDay $day, string $kind, CarbonImmutable $time): ?string
    {
        $date = CarbonImmutable::parse($day->work_date->toDateString());

        // Within a day either side: an overnight shift's check-out is tomorrow.
        if ($time->lessThan($date->subDay()) || ! $time->lessThan($date->addDays(2))) {
            return "Times must be within a day of {$date->toDateString()}.";
        }

        if ($kind === AttendanceAdjustment::KIND_CHECK_IN) {
            return $day->last_out && ! $time->lessThan($day->last_out)
                ? "Check-in must be before the check-out ({$day->last_out->format('H:i')})."
                : null;
        }

        if (! $day->first_in) {
            return 'This day has no check-in. Edit the check-in first.';
        }

        return $time->greaterThan($day->first_in)
            ? null
            : "Check-out must be after the check-in ({$day->first_in->format('H:i')}).";
    }

    private function rebuildAndShow(AttendanceDayProcessor $processor, int $employeeId, string $date, string $message): RedirectResponse
    {
        $subjectKey = 'emp:'.$employeeId;
        $processor->rebuildDay($subjectKey, $date);

        $day = AttendanceDay::where('subject_key', $subjectKey)->where('work_date', $date)->first();

        return $day
            ? redirect()->route('admin.attendance.days.show', $day)->with('success', $message)
            : redirect()->route('admin.attendance.days.index', ['from' => $date, 'to' => $date])
                ->with('success', $message.' Nothing is left to record for that day.');
    }

    private function log(AttendanceAdjustment $adjustment, string $action): void
    {
        ActivityLog::create([
            'model_type' => 'AttendanceAdjustment',
            'model_id' => $adjustment->id,
            'action' => $action,
            'changes' => $adjustment->only(['employee_id', 'check_in', 'check_out', 'excuse', 'reason'])
                + ['work_date' => $adjustment->work_date->toDateString()],
            'user_id' => Auth::id(),
        ]);
    }
}
