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
 * HR corrections to a day: set the check-in / check-out, or excuse the day.
 * Each needs a reason; the punches are never edited; a new correction
 * revokes the previous one, so the full history stays visible.
 */
class AttendanceAdjustmentController extends Controller
{
    public function store(Request $request, AttendanceDay $day, AttendanceDayProcessor $processor): RedirectResponse
    {
        if (! $day->employee_id) {
            return back()->with('error', 'Link this BioTime code to an employee first — a correction belongs to a person.');
        }

        $data = $request->validate([
            'action' => 'required|in:times,excuse',
            'check_in' => 'nullable|date',
            'check_out' => 'nullable|date',
            'excuse' => ['nullable', 'required_if:action,excuse', Rule::in(array_keys(AttendanceAdjustment::EXCUSES))],
            'reason' => 'required|string|max:1000',
        ]);

        $date = $day->work_date->toDateString();
        $values = ['check_in' => null, 'check_out' => null, 'excuse' => null];

        if ($data['action'] === 'times') {
            $in = ! empty($data['check_in']) ? CarbonImmutable::parse($data['check_in']) : null;
            $out = ! empty($data['check_out']) ? CarbonImmutable::parse($data['check_out']) : null;

            if (! $in && ! $out) {
                return back()->withInput()->with('error', 'Enter a check-in, a check-out, or both.');
            }

            // Within a day either side: an overnight shift's check-out is tomorrow.
            $earliest = CarbonImmutable::parse($date)->subDay();
            $latest = CarbonImmutable::parse($date)->addDays(2);
            foreach ([$in, $out] as $time) {
                if ($time && ($time->lessThan($earliest) || ! $time->lessThan($latest))) {
                    return back()->withInput()->with('error', "Times must be within a day of {$date}.");
                }
            }

            if ($in && $out && ! $out->greaterThan($in)) {
                return back()->withInput()->with('error', 'Check-out must be after check-in.');
            }

            if (! $in && ! $day->first_in) {
                return back()->withInput()->with('error', 'There are no punches on this day — give a check-in as well.');
            }

            $values['check_in'] = $in?->format('Y-m-d H:i:s');
            $values['check_out'] = $out?->format('Y-m-d H:i:s');
        } else {
            $values['excuse'] = $data['excuse'];
        }

        $adjustment = DB::transaction(function () use ($day, $date, $values, $data) {
            AttendanceAdjustment::query()
                ->where('employee_id', $day->employee_id)
                ->where('work_date', $date)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now(), 'revoked_by' => Auth::id()]);

            return AttendanceAdjustment::create($values + [
                'employee_id' => $day->employee_id,
                'work_date' => $date,
                'reason' => $data['reason'],
                'created_by' => Auth::id(),
            ]);
        });

        $this->log($adjustment, 'created');

        return $this->rebuildAndShow($processor, $day->employee_id, $date, 'Correction saved: '.$adjustment->summary().'.');
    }

    public function revoke(AttendanceAdjustment $adjustment, AttendanceDayProcessor $processor): RedirectResponse
    {
        if (! $adjustment->isActive()) {
            return back()->with('error', 'That correction was already revoked.');
        }

        $adjustment->forceFill(['revoked_at' => now(), 'revoked_by' => Auth::id()])->save();
        $this->log($adjustment, 'revoked');

        return $this->rebuildAndShow($processor, $adjustment->employee_id, $adjustment->work_date->toDateString(),
            'Correction revoked — the day is back to what the punches say.');
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
