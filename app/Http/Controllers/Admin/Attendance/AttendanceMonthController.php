<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\MonthlySheet;
use App\Services\Attendance\MonthlyTotals;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Attendance → Monthly sheet: one person's whole month on one page —
 * every day's check-in, check-out and punches, with the month's absences,
 * hours, missing check-outs, lateness and early leaves added up.
 *
 * The index is the picker: everyone who punches on BioTime, with the same
 * month's totals beside them, so HR can see who needs looking at first.
 *
 * Every figure comes from attendance_days, which the Check-in / Check-out page
 * builds — nothing is recomputed here, so the two pages can never disagree.
 */
class AttendanceMonthController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $employees = $this->employees($filters);

        return view('admin.attendance.monthly.index', [
            'filters' => $filters,
            'employees' => $employees,
            'totals' => $this->rosterTotals($filters, $employees->getCollection()->pluck('id')->all()),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Employee $employee, MonthlySheet $sheet): View
    {
        $month = $this->month($request);
        $days = $sheet->build($employee, $month);

        return view('admin.attendance.monthly.show', [
            'employee' => $employee->load(['branch:id,name', 'department:id,name']),
            'month' => $month,
            'monthStart' => CarbonImmutable::parse($month.'-01'),
            'days' => $days,
            'totals' => MonthlyTotals::fromDays($days),
            'codes' => BiotimeEmployee::where('employee_id', $employee->id)->pluck('emp_code')->implode(', '),
        ]);
    }

    public function export(Request $request, Employee $employee, MonthlySheet $sheet): StreamedResponse
    {
        $month = $this->month($request);
        $days = $sheet->build($employee, $month);
        $totals = MonthlyTotals::fromDays($days);
        $name = preg_replace('/[^A-Za-z0-9]+/', '-', (string) $employee->name);

        return response()->streamDownload(function () use ($days, $totals, $employee, $month) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic names as UTF-8

            fputcsv($out, ['Employee', $employee->name, 'Oracle no', $employee->oracle_emp_no, 'Month', $month]);
            fputcsv($out, []);
            fputcsv($out, ['Date', 'Day', 'Calendar', 'Shift', 'Scheduled in', 'Scheduled out', 'Status',
                'Check in', 'Check out', 'Worked (h:mm)', 'Late (min)', 'Early leave (min)', 'Overtime (min)',
                'Punches', 'Punch times', 'Excuse', 'Errors', 'Warnings']);

            foreach ($days as $day) {
                $row = $day->day;
                $errors = [];
                $warnings = [];
                foreach ($row?->flagBadges() ?? [] as $badge) {
                    $badge['error'] ? $errors[] = $badge['label'] : $warnings[] = $badge['label'];
                }

                fputcsv($out, [
                    $day->date,
                    CarbonImmutable::parse($day->date)->format('D'),
                    $day->kindLabel(),
                    $row?->shift?->name ?? $day->shift?->name,
                    $row?->scheduled_start?->format('H:i'),
                    $row?->scheduled_end?->format('H:i'),
                    $row?->statusLabel() ?? $day->emptyLabel(),
                    $row?->first_in?->format('Y-m-d H:i'),
                    $row?->last_out?->format('Y-m-d H:i'),
                    $row && $row->worked_minutes !== null ? $row->workedLabel() : '',
                    (int) $row?->late_minutes,
                    (int) $row?->early_leave_minutes,
                    (int) $row?->overtime_minutes,
                    $day->punches->count(),
                    $day->punches->map(fn ($p) => $p->punch_time->format('H:i'))->implode(' '),
                    $row?->excuse ? (AttendanceAdjustment::EXCUSES[$row->excuse] ?? $row->excuse) : '',
                    implode('; ', $errors),
                    implode('; ', $warnings),
                ]);
            }

            fputcsv($out, []);
            fputcsv($out, ['Totals']);
            foreach ([
                'Work days' => $totals->workDays,
                'Present' => $totals->presentDays,
                'Absent' => $totals->absentDays,
                'Excused' => $totals->excusedDays,
                'Days off' => $totals->offDays,
                'Holidays' => $totals->holidayDays,
                'Not recorded' => $totals->unrecordedDays,
                'Total worked (h:mm)' => $totals->workedLabel(),
                'Overtime (h:mm)' => $totals->overtimeLabel(),
                'Late days' => $totals->lateDays,
                'Late (min)' => $totals->lateMinutes,
                'Early leave days' => $totals->earlyLeaveDays,
                'Early leave (min)' => $totals->earlyLeaveMinutes,
                'Missing check-outs' => $totals->missingCheckOuts,
                'Punches' => $totals->punches,
                'Days with errors' => $totals->errorDays,
            ] as $label => $value) {
                fputcsv($out, [$label, $value]);
            }

            fclose($out);
        }, "attendance-{$name}-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{month: string, branch: ?int, department: ?int, q: string} */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'branch' => 'nullable|integer',
            'department' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
        ]);

        return [
            'month' => $this->month($request),
            'branch' => isset($v['branch']) ? (int) $v['branch'] : null,
            'department' => isset($v['department']) ? (int) $v['department'] : null,
            'q' => trim((string) ($v['q'] ?? '')),
        ];
    }

    /** Y-m, this month when absent or malformed. */
    private function month(Request $request): string
    {
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        return $request->query('month') ?: CarbonImmutable::today()->format('Y-m');
    }

    /**
     * The picker: people with at least one BioTime code — nobody else can have
     * a check-in — newest-hired last, by name.
     *
     * @return LengthAwarePaginator<Employee>
     */
    private function employees(array $filters): LengthAwarePaginator
    {
        return Employee::query()
            ->whereIn('id', BiotimeEmployee::query()->whereNotNull('employee_id')->select('employee_id'))
            ->when($filters['branch'], fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['department'], fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['q'] !== '', function ($q) use ($filters) {
                $search = $filters['q'];
                $q->where(fn ($w) => $w
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('oracle_emp_no', $search)
                    ->orWhereIn('id', BiotimeEmployee::query()
                        ->where('emp_code', 'like', "%{$search}%")
                        ->whereNotNull('employee_id')
                        ->select('employee_id')));
            })
            ->with(['branch:id,name', 'department:id,name'])
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * The month added up per person, straight from attendance_days — the same
     * rows the sheet shows, so the two always agree.
     *
     * Days off and holidays are deliberately not counted here: they are
     * calendar facts with no row, and resolving every person's shift for every
     * day of the month would be a page full of work for a column nobody sorts
     * by. The sheet shows them.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, object> employee_id => totals
     */
    private function rosterTotals(array $filters, array $employeeIds): Collection
    {
        if ($employeeIds === []) {
            return collect();
        }

        [$from, $to] = $this->range($filters['month']);

        $base = fn () => AttendanceDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->whereBetween('work_date', [$from, $to]);

        $totals = $base()
            ->groupBy('employee_id')
            ->selectRaw('employee_id')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as present_days', [AttendanceDayBuilder::STATUS_PRESENT])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as absent_days', [AttendanceDayBuilder::STATUS_ABSENT])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as excused_days', [AttendanceDayBuilder::STATUS_EXCUSED])
            ->selectRaw('SUM(COALESCE(worked_minutes, 0)) as worked_minutes')
            ->selectRaw('SUM(overtime_minutes) as overtime_minutes')
            ->selectRaw('SUM(late_minutes) as late_minutes')
            ->selectRaw('SUM(early_leave_minutes) as early_leave_minutes')
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days')
            ->selectRaw('SUM(CASE WHEN early_leave_minutes > 0 THEN 1 ELSE 0 END) as early_leave_days')
            ->selectRaw('SUM(CASE WHEN has_error = 1 THEN 1 ELSE 0 END) as error_days')
            ->get()
            ->keyBy('employee_id');

        // A missing check-out is a flag, not a column: last_out is also null on
        // an excused day, where it is not an error at all.
        $missing = $base()
            ->whereJsonContains('flags', AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, COUNT(*) as missing_check_outs')
            ->pluck('missing_check_outs', 'employee_id');

        return $totals->each(function ($row) use ($missing) {
            $row->missing_check_outs = (int) ($missing[$row->employee_id] ?? 0);
        });
    }

    /** @return array{0: string, 1: string} */
    private function range(string $month): array
    {
        $start = CarbonImmutable::parse($month.'-01');

        return [$start->toDateString(), $start->endOfMonth()->toDateString()];
    }
}
