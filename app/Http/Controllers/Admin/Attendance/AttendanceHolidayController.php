<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceHoliday;
use App\Models\Branch;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin → Attendance → Holidays. Adding or removing one recalculates the
 * affected days at once, so an absence on a new holiday disappears.
 */
class AttendanceHolidayController extends Controller
{
    public function index(Request $request): View
    {
        $year = $request->integer('year') ?: (int) now()->year;

        return view('admin.attendance.holidays.index', [
            'holidays' => AttendanceHoliday::with('branch:id,name')
                ->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])
                ->orderBy('holiday_date')
                ->get(),
            'year' => $year,
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, AttendanceDayProcessor $processor): RedirectResponse
    {
        $data = $request->validate([
            'holiday_date' => 'required|date',
            'holiday_to' => 'nullable|date|after_or_equal:holiday_date',
            'name' => 'required|string|max:150',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $from = CarbonImmutable::parse($data['holiday_date']);
        $to = isset($data['holiday_to']) ? CarbonImmutable::parse($data['holiday_to']) : $from;

        if ($from->diffInDays($to) > 30) {
            return back()->withInput()->with('error', 'Add at most 31 days at a time.');
        }

        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;
        $added = 0;

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            $holiday = AttendanceHoliday::firstOrCreate(
                ['holiday_date' => $day->toDateString(), 'branch_id' => $branchId],
                ['name' => $data['name']],
            );
            $added += $holiday->wasRecentlyCreated ? 1 : 0;
        }

        $this->log(0, 'created', $data);
        $days = $this->rebuild($processor, $from, $to, $branchId);

        return back()->with('success', "{$added} holiday day(s) added.".($days ? " {$days} day(s) recalculated." : ''));
    }

    public function destroy(AttendanceHoliday $holiday, AttendanceDayProcessor $processor): RedirectResponse
    {
        $date = CarbonImmutable::parse($holiday->holiday_date->toDateString());
        $branchId = $holiday->branch_id;
        $holiday->delete();
        $this->log($holiday->id, 'deleted', ['holiday_date' => $date->toDateString(), 'name' => $holiday->name, 'branch_id' => $branchId]);

        $days = $this->rebuild($processor, $date, $date, $branchId);

        return back()->with('success', "Holiday \"{$holiday->name}\" removed.".($days ? " {$days} day(s) recalculated." : ''));
    }

    private function rebuild(AttendanceDayProcessor $processor, CarbonImmutable $from, CarbonImmutable $to, ?int $branchId): int
    {
        $today = CarbonImmutable::today();
        if ($from->greaterThan($today)) {
            return 0;
        }

        $processor->shifts()->forget();
        @set_time_limit(300);

        return $processor->rebuildRange(
            $from->toDateString(),
            ($to->greaterThan($today) ? $today : $to)->toDateString(),
            $branchId ? Employee::where('branch_id', $branchId)->pluck('id')->all() : null,
        );
    }

    private function log(int $id, string $action, array $changes): void
    {
        ActivityLog::create([
            'model_type' => 'AttendanceHoliday',
            'model_id' => $id,
            'action' => $action,
            'changes' => $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
