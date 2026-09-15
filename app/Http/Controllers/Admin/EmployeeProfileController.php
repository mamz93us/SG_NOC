<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationBalance;
use App\Models\Vacation\VacationEmployee;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\MonthlySheet;
use App\Services\Attendance\MonthlyTotals;
use App\Services\People\EmployeeProfile;
use App\Services\People\ProfileYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Admin → Attendance → Employee profiles: one page per person with their
 * attendance and their Oracle vacation side by side — the year month by month,
 * the month day by day, the balance and the leave records — and the days where
 * the two disagree, such as an absence Oracle has as leave.
 *
 * Either view-attendance or view-vacations opens it; each half of the page is
 * shown only to people who hold that half's own permission.
 */
class EmployeeProfileController extends Controller
{
    public const DATA = [
        'all' => 'Everyone',
        'attendance' => 'Punching on BioTime',
        'vacation' => 'In Oracle\'s vacation data',
    ];

    public const STATUS = [
        'active' => 'Current staff',
        'left' => 'Has left',
        'all' => 'Everyone',
    ];

    public function index(Request $request): View
    {
        $v = $request->validate([
            'q' => 'nullable|string|max:100',
            'branch' => 'nullable|integer',
            'department' => 'nullable|integer',
            'status' => 'nullable|in:'.implode(',', array_keys(self::STATUS)),
            'data' => 'nullable|in:'.implode(',', array_keys(self::DATA)),
        ]);

        $filters = [
            'q' => trim((string) ($v['q'] ?? '')),
            'branch' => isset($v['branch']) ? (int) $v['branch'] : null,
            'department' => isset($v['department']) ? (int) $v['department'] : null,
            'status' => $v['status'] ?? 'active',
            'data' => $v['data'] ?? 'all',
        ];
        [$canAttendance, $canVacations] = $this->permissions($request);

        // Primary records only: a linked secondary mailbox is the same person.
        $employees = Employee::query()
            ->whereNull('linked_primary_employee_id')
            ->when($filters['status'] === 'active', fn ($q) => $q->where('status', '!=', 'terminated'))
            ->when($filters['status'] === 'left', fn ($q) => $q->where('status', 'terminated'))
            ->when($filters['branch'], fn ($q, $id) => $q->where('branch_id', $id))
            ->when($filters['department'], fn ($q, $id) => $q->where('department_id', $id))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$filters['q']}%")
                ->orWhere('email', 'like', "%{$filters['q']}%")
                ->orWhere('oracle_emp_no', $filters['q'])))
            ->when($filters['data'] === 'attendance' && $canAttendance, fn ($q) => $q
                ->whereIn('id', BiotimeEmployee::query()->whereNotNull('employee_id')->select('employee_id')))
            ->when($filters['data'] === 'vacation' && $canVacations, fn ($q) => $q
                ->whereIn('id', VacationEmployee::query()->whereNotNull('employee_id')->select('employee_id')))
            ->with(['branch:id,name', 'department:id,name'])
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        $ids = $employees->getCollection()->pluck('id')->all();
        $today = CarbonImmutable::today();

        return view('admin.people.index', [
            'filters' => $filters,
            'employees' => $employees,
            'today' => $today,
            'canAttendance' => $canAttendance,
            'canVacations' => $canVacations,
            'attendance' => $canAttendance ? $this->monthAttendance($ids, $today) : collect(),
            'punching' => $canAttendance && $ids !== []
                ? BiotimeEmployee::query()->whereIn('employee_id', $ids)->distinct()->pluck('employee_id')->flip()
                : collect(),
            'balances' => $canVacations ? $this->balances($ids, $today->year) : collect(),
            'awayToday' => $canVacations ? $this->awayToday($ids, $today) : collect(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Employee $employee, EmployeeProfile $profile, MonthlySheet $sheet): View|RedirectResponse
    {
        // A linked secondary mailbox is the same person; the attendance and the Oracle link live on the primary.
        if ($employee->linked_primary_employee_id && ($primary = $employee->linkedPrimary)) {
            return redirect()->route('admin.people.show', ['employee' => $primary->id] + $request->only('month'));
        }

        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);

        $today = CarbonImmutable::today();
        $month = (string) ($request->query('month') ?: $today->format('Y-m'));
        $monthStart = CarbonImmutable::parse($month.'-01');
        $year = $monthStart->year;
        [$canAttendance, $canVacations] = $this->permissions($request);

        $people = $canVacations ? $profile->oraclePeople($employee) : collect();
        $yearLeave = $profile->leave($people, "{$year}-01-01", "{$year}-12-31");
        $activeLeave = $yearLeave->whereNull('removed_at')->values();
        $ahead = $profile->leave($people, $today->toDateString(), $today->addYear()->toDateString())->whereNull('removed_at');
        $days = $canAttendance ? $sheet->build($employee, $month) : [];

        return view('admin.people.show', [
            'employee' => $employee->load(['branch:id,name', 'department:id,name', 'manager:id,name', 'supervisor:id,name']),
            'month' => $month,
            'monthStart' => $monthStart,
            'year' => $year,
            'today' => $today,
            'canAttendance' => $canAttendance,
            'canVacations' => $canVacations,

            'yearStats' => $canAttendance ? $profile->year($employee, $year, $activeLeave, EmployeeProfile::weekend($people)) : null,
            'days' => $days,
            'monthTotals' => $canAttendance ? MonthlyTotals::fromDays($days) : null,
            'codes' => $canAttendance ? BiotimeEmployee::where('employee_id', $employee->id)->pluck('emp_code')->unique()->implode(', ') : '',
            'leaveByDate' => ProfileYear::coverage($activeLeave, $monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()),

            'people' => $people,
            'balance' => $profile->balance($people, $year),
            'records' => $activeLeave,
            'withdrawnCount' => $yearLeave->count() - $activeLeave->count(),
            'awayToday' => $ahead->filter(fn (VacationAbsence $record) => $record->start_date->lte($today))->values(),
            'nextLeave' => $ahead
                ->filter(fn (VacationAbsence $record) => $record->start_date->gt($today) && ! $record->isBusinessTrip())
                ->sortBy(fn (VacationAbsence $record) => $record->start_date->toDateString())
                ->first(),
        ]);
    }

    /** @return array{0: bool, 1: bool} view-attendance, view-vacations */
    private function permissions(Request $request): array
    {
        $user = $request->user();

        return [(bool) $user?->can('view-attendance'), (bool) $user?->can('view-vacations')];
    }

    /**
     * This month's present, absent and late days per person, straight from
     * attendance_days — the rows the Monthly sheet counts.
     *
     * @param  list<int>  $ids
     */
    private function monthAttendance(array $ids, CarbonImmutable $today): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return AttendanceDay::query()
            ->whereIn('employee_id', $ids)
            ->whereBetween('work_date', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
            ->groupBy('employee_id')
            ->selectRaw('employee_id')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as present_days', [AttendanceDayBuilder::STATUS_PRESENT])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as absent_days', [AttendanceDayBuilder::STATUS_ABSENT])
            ->selectRaw('SUM(CASE WHEN late_minutes > 0 THEN 1 ELSE 0 END) as late_days')
            ->toBase()
            ->get()
            ->keyBy('employee_id');
    }

    /**
     * Each person's Oracle balance for the year.
     *
     * @param  list<int>  $ids
     * @return Collection<int, VacationBalance> keyed by employee id
     */
    private function balances(array $ids, int $year): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return VacationBalance::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_balances.vacation_employee_id')
            ->whereIn('vacation_employees.employee_id', $ids)
            ->where('vacation_balances.year', $year)
            ->orderBy('vacation_employees.id')
            ->get(['vacation_balances.*', 'vacation_employees.employee_id as profile_employee_id'])
            ->unique('profile_employee_id')
            ->keyBy('profile_employee_id');
    }

    /**
     * The leave records covering today, per person.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Collection<int, VacationAbsence>> keyed by employee id
     */
    private function awayToday(array $ids, CarbonImmutable $today): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return VacationAbsence::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_absences.vacation_employee_id')
            ->whereIn('vacation_employees.employee_id', $ids)
            ->active()
            ->overlapping($today->toDateString(), $today->toDateString())
            ->get(['vacation_absences.*', 'vacation_employees.employee_id as profile_employee_id'])
            ->groupBy('profile_employee_id');
    }
}
