<?php

namespace App\Http\Controllers\Admin\Vacation;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationBalance;
use App\Models\Vacation\VacationEmployee;
use App\Models\Vacation\VacationImport;
use App\Services\Vacation\VacationLinker;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Vacations → Balances: every person's leave balance for a year as
 * Oracle last reported it — last year's balance carried over, this year's
 * leave earned so far, days used and days left — and one person's page with
 * their leave records and which employee they are.
 *
 * Every figure is Oracle's; nothing here is recomputed except the "other
 * adjustments" a balance holds beyond its own columns.
 */
class VacationBalanceController extends Controller
{
    public const SHOW = [
        'all' => 'Everyone',
        'negative' => 'Negative balance',
        'adjusted' => 'Other adjustments in Oracle',
        'no_balance' => 'No balance in Oracle',
        'outdated' => 'Not in the latest balance sheet',
        'unlinked' => 'Not linked to an employee',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $latestAsOf = $this->latestAsOf($filters);

        return view('admin.vacations.balances.index', [
            'filters' => $filters,
            'people' => $this->roster($filters, $latestAsOf)->paginate(50)->withQueryString(),
            'totals' => $this->totals($filters),
            'latestAsOf' => $latestAsOf,
            'years' => $this->years($filters['book']),
            'books' => VacationEmployee::books(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'imported' => VacationImport::where('book', $filters['book'])->exists(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->roster($filters, $this->latestAsOf($filters));

        return response()->streamDownload(function () use ($query, $filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic names as UTF-8

            fputcsv($out, ['Oracle no', 'Oracle person id', 'Employee', 'Branch', 'Department', 'Employee status', 'Linked',
                'Year', 'Last year balance (carried over)', 'This year so far (accrued)', 'Used', 'Other adjustments',
                'Remaining', 'As of']);

            $query->chunk(500, function ($people) use ($out, $filters) {
                foreach ($people as $person) {
                    $balance = $person->balanceFor($filters['year']);
                    $employee = $person->employee;

                    fputcsv($out, [
                        $person->oracle_emp_no,
                        $person->oracle_person_id,
                        $employee?->name,
                        $employee?->branch?->name,
                        $employee?->department?->name,
                        $employee?->status,
                        $person->methodLabel(),
                        $filters['year'],
                        $balance?->carryover,
                        $balance?->accrued,
                        $balance?->used,
                        $balance?->otherAdjustments(),
                        $balance?->balance,
                        $balance?->as_of?->toDateString(),
                    ]);
                }
            });

            fclose($out);
        }, "vacation-balances-{$filters['book']}-{$filters['year']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function show(Request $request, VacationEmployee $vacationEmployee): View
    {
        $person = $vacationEmployee->load([
            'employee.branch:id,name',
            'employee.department:id,name',
            'balances' => fn ($q) => $q->orderByDesc('year'),
            'confirmedBy:id,name',
        ]);

        $recordYears = $person->absences()->pluck('start_date')->map(fn ($date) => (int) substr((string) $date, 0, 4));
        $years = $person->balances->pluck('year')->merge($recordYears)->unique()->sortDesc()->values();

        $request->validate(['year' => 'nullable|integer|min:2000|max:2100']);
        $year = (int) ($request->query('year') ?: ($years->first() ?? CarbonImmutable::today()->year));
        $showWithdrawn = $request->boolean('withdrawn');

        $inYear = fn () => $person->absences()->overlapping("{$year}-01-01", "{$year}-12-31");
        $records = $inYear()->when(! $showWithdrawn, fn ($q) => $q->active())->orderByDesc('start_date')->get();

        $byType = $records->whereNull('removed_at')
            ->groupBy('absence_type')
            ->map(fn ($group, $type) => (object) [
                'type' => $type,
                'trip' => VacationAbsence::isTripType($type),
                'records' => $group->count(),
                'days' => $group->sum(fn (VacationAbsence $record) => $record->days()),
                'calendar_days' => $group->sum('calendar_days'),
            ])
            ->sortBy(fn ($row) => [$row->trip ? 1 : 0, -$row->days])
            ->values();

        return view('admin.vacations.balances.show', [
            'person' => $person,
            'year' => $year,
            'years' => $years,
            'balance' => $person->balanceFor($year),
            'records' => $records,
            'byType' => $byType,
            'withdrawnCount' => $inYear()->whereNotNull('removed_at')->count(),
            'showWithdrawn' => $showWithdrawn,
            'today' => CarbonImmutable::today(),
            'candidates' => Employee::with('branch:id,name')
                ->whereIn('id', $person->candidate_ids ?: [])
                ->get(['id', 'name', 'oracle_emp_no', 'branch_id', 'status']),
            // Only people who can link need the (long) picker list.
            'employeeOptions' => $request->user()?->can('manage-vacations')
                ? Employee::with('branch:id,name')
                    ->whereNull('linked_primary_employee_id')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'oracle_emp_no', 'branch_id', 'status'])
                : collect(),
        ]);
    }

    public function link(Request $request, VacationEmployee $vacationEmployee, VacationLinker $linker): RedirectResponse
    {
        $data = $request->validate(['employee' => 'required|string|max:255']);

        // The picker's value starts with the employee id: "123 · Name · Oracle 456 · Branch".
        if (! preg_match('/^\s*#?(\d+)/', $data['employee'], $m) || ! ($employee = Employee::find((int) $m[1]))) {
            return back()->with('error', 'Pick the employee from the list — start typing a name or Oracle number.');
        }

        $old = $vacationEmployee->employee_id;
        $linker->linkManually($vacationEmployee, $employee, Auth::id());
        $this->log($vacationEmployee, 'vacation_person_linked', ['old_employee_id' => $old, 'employee_id' => $vacationEmployee->employee_id]);

        return back()->with('success', "Oracle number {$vacationEmployee->oracle_emp_no} is now linked to {$vacationEmployee->employee?->name}.");
    }

    public function noEmployee(VacationEmployee $vacationEmployee, VacationLinker $linker): RedirectResponse
    {
        $old = $vacationEmployee->employee_id;
        $linker->linkManually($vacationEmployee, null, Auth::id());
        $this->log($vacationEmployee, 'vacation_person_not_employee', ['old_employee_id' => $old]);

        return back()->with('success', "Oracle number {$vacationEmployee->oracle_emp_no} is marked as nobody in the NOC.");
    }

    public function reset(VacationEmployee $vacationEmployee, VacationLinker $linker): RedirectResponse
    {
        $old = $vacationEmployee->employee_id;
        $linker->resetToAuto($vacationEmployee);
        $this->log($vacationEmployee, 'vacation_person_reset', ['old_employee_id' => $old, 'employee_id' => $vacationEmployee->employee_id]);

        return back()->with('success', "Oracle number {$vacationEmployee->oracle_emp_no} is back on automatic matching: {$vacationEmployee->methodLabel()}.");
    }

    /** @return array{book: string, year: int, branch: ?int, department: ?int, q: string, show: string} */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'book' => 'nullable|string|max:40',
            'year' => 'nullable|integer|min:2000|max:2100',
            'branch' => 'nullable|integer',
            'department' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
            'show' => 'nullable|in:'.implode(',', array_keys(self::SHOW)),
        ]);

        $book = array_key_exists((string) ($v['book'] ?? ''), VacationEmployee::books()) ? $v['book'] : VacationEmployee::defaultBook();

        return [
            'book' => $book,
            'year' => (int) ($v['year'] ?? $this->years($book)->first() ?? CarbonImmutable::today()->year),
            'branch' => isset($v['branch']) ? (int) $v['branch'] : null,
            'department' => isset($v['department']) ? (int) $v['department'] : null,
            'q' => trim((string) ($v['q'] ?? '')),
            'show' => $v['show'] ?? 'all',
        ];
    }

    /**
     * Everyone in the book with a balance for the year or a leave record in it,
     * by employee name; people not linked to an employee come last.
     */
    private function roster(array $filters, ?CarbonImmutable $latestAsOf): Builder
    {
        $year = $filters['year'];

        return VacationEmployee::query()
            ->select('vacation_employees.*')
            ->leftJoin('employees', 'employees.id', '=', 'vacation_employees.employee_id')
            ->leftJoin('vacation_balances', fn ($join) => $join
                ->on('vacation_balances.vacation_employee_id', '=', 'vacation_employees.id')
                ->where('vacation_balances.year', '=', $year))
            ->where('vacation_employees.book', $filters['book'])
            ->where(fn ($q) => $q
                ->whereNotNull('vacation_balances.id')
                ->orWhereExists(fn ($records) => $records->selectRaw('1')
                    ->from('vacation_absences')
                    ->whereColumn('vacation_absences.vacation_employee_id', 'vacation_employees.id')
                    ->where('vacation_absences.start_date', '<=', "{$year}-12-31")
                    ->where('vacation_absences.end_date', '>=', "{$year}-01-01")))
            ->when($filters['branch'], fn ($q, $id) => $q->where('employees.branch_id', $id))
            ->when($filters['department'], fn ($q, $id) => $q->where('employees.department_id', $id))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('employees.name', 'like', "%{$filters['q']}%")
                ->orWhere('vacation_employees.oracle_emp_no', $filters['q'])
                ->orWhere('employees.oracle_emp_no', $filters['q'])))
            ->when($filters['show'] === 'negative', fn ($q) => $q->where('vacation_balances.balance', '<', 0))
            // The threshold is written into the SQL, not bound: SQLite compares a bound
            // float as text, every number sorts below text, and nobody would match.
            ->when($filters['show'] === 'adjusted', fn ($q) => $q->whereRaw(
                'ABS(vacation_balances.balance - (COALESCE(vacation_balances.carryover, 0) + COALESCE(vacation_balances.accrued, 0) - COALESCE(vacation_balances.used, 0))) >= '.VacationBalance::ROUNDING
            ))
            ->when($filters['show'] === 'no_balance', fn ($q) => $q->whereNull('vacation_balances.balance'))
            ->when($filters['show'] === 'outdated', fn ($q) => $latestAsOf
                ? $q->where('vacation_balances.as_of', '<', $latestAsOf->toDateString())
                : $q->whereRaw('1 = 0'))
            ->when($filters['show'] === 'unlinked', fn ($q) => $q->whereNull('vacation_employees.employee_id'))
            ->with([
                'employee:id,name,oracle_emp_no,branch_id,department_id,status',
                'employee.branch:id,name',
                'employee.department:id,name',
                'balances' => fn ($q) => $q->where('year', $year),
            ])
            ->orderByRaw('CASE WHEN employees.name IS NULL THEN 1 ELSE 0 END')
            ->orderBy('employees.name')
            ->orderBy('vacation_employees.oracle_emp_no');
    }

    /** The year added up for the whole book, whatever the filters. */
    private function totals(array $filters): object
    {
        $totals = VacationBalance::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_balances.vacation_employee_id')
            ->where('vacation_employees.book', $filters['book'])
            ->where('vacation_balances.year', $filters['year'])
            ->selectRaw('SUM(CASE WHEN vacation_balances.balance IS NOT NULL THEN 1 ELSE 0 END) as people')
            ->selectRaw('SUM(CASE WHEN vacation_balances.balance < 0 THEN 1 ELSE 0 END) as negative')
            ->selectRaw('SUM(COALESCE(vacation_balances.balance, 0)) as remaining')
            ->toBase()
            ->first();

        // The people "No balance in Oracle" lists: no leave plan yet, or leave records and no balance row.
        $totals->no_balance = $this->roster(['show' => 'no_balance', 'branch' => null, 'department' => null, 'q' => ''] + $filters, null)->count();

        $today = CarbonImmutable::today()->toDateString();

        $totals->unlinked = VacationEmployee::query()
            ->where('book', $filters['book'])
            ->whereNull('employee_id')
            ->where(fn ($q) => $q->whereNull('match_method')->orWhere('match_method', '!=', VacationEmployee::METHOD_MANUAL))
            ->count();
        $totals->on_leave_today = VacationAbsence::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_absences.vacation_employee_id')
            ->where('vacation_employees.book', $filters['book'])
            ->active()
            ->trips(false)
            ->overlapping($today, $today)
            ->distinct()
            ->count('vacation_absences.vacation_employee_id');

        return $totals;
    }

    private function latestAsOf(array $filters): ?CarbonImmutable
    {
        $latest = VacationBalance::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_balances.vacation_employee_id')
            ->where('vacation_employees.book', $filters['book'])
            ->where('vacation_balances.year', $filters['year'])
            ->max('vacation_balances.as_of');

        return $latest ? CarbonImmutable::parse($latest) : null;
    }

    /** Years with balances in the book, newest first. */
    private function years(string $book)
    {
        return VacationBalance::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_balances.vacation_employee_id')
            ->where('vacation_employees.book', $book)
            ->distinct()
            ->orderByDesc('vacation_balances.year')
            ->pluck('vacation_balances.year')
            ->map(fn ($year) => (int) $year)
            ->values();
    }

    private function log(VacationEmployee $person, string $action, array $changes): void
    {
        ActivityLog::create([
            'model_type' => VacationEmployee::class,
            'model_id' => $person->id,
            'model_label' => $person->employee?->name,
            'action' => $action,
            'changes' => ['book' => $person->book, 'oracle_emp_no' => $person->oracle_emp_no] + $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
