<?php

namespace App\Http\Controllers\Admin\Vacation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Vacation\VacationAbsence;
use App\Models\Vacation\VacationEmployee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Vacations → Leave records: every leave record from Oracle with at
 * least one day in a date range — who is away, on what, from when to when.
 * A month at a time by default, filterable by type, branch and department.
 *
 * Business trips come in the same export; they are listed but kept apart
 * from leave in the counts, because nobody is on holiday on one.
 */
class VacationAbsenceController extends Controller
{
    private const MAX_RANGE_DAYS = 366;

    /** Type filter values besides Oracle's own type names. */
    public const GROUPS = [
        'leave' => 'All leave (no business trips)',
        'trips' => 'Business trips',
    ];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $today = CarbonImmutable::today()->toDateString();

        $inRange = fn () => $this->base($filters)->overlapping($filters['from'], $filters['to']);

        return view('admin.vacations.absences.index', [
            'filters' => $filters,
            'records' => $this->records($filters)->paginate(50)->withQueryString(),
            // What the range holds, per type, before the type and people filters.
            'typeCounts' => $inRange()->active()
                ->groupBy('vacation_absences.absence_type')
                ->selectRaw('vacation_absences.absence_type, COUNT(*) as records, COUNT(DISTINCT vacation_absences.vacation_employee_id) as people')
                ->orderByDesc('records')
                ->toBase()
                ->get(),
            'onLeaveToday' => $this->base($filters)->active()->trips(false)->overlapping($today, $today)->distinct()->count('vacation_absences.vacation_employee_id'),
            'onTripToday' => $this->base($filters)->active()->trips()->overlapping($today, $today)->distinct()->count('vacation_absences.vacation_employee_id'),
            'types' => $this->base($filters)->distinct()->orderBy('vacation_absences.absence_type')->pluck('vacation_absences.absence_type'),
            'books' => VacationEmployee::books(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
            'today' => CarbonImmutable::today(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->records($filters);
        $today = CarbonImmutable::today();

        return response()->streamDownload(function () use ($query, $today) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic names as UTF-8

            fputcsv($out, ['Oracle no', 'Employee', 'Branch', 'Department', 'Type', 'From', 'To', 'Work days', 'Calendar days', 'Status', 'No longer in Oracle since']);

            $query->chunk(500, function ($records) use ($out, $today) {
                foreach ($records as $record) {
                    $employee = $record->vacationEmployee?->employee;

                    fputcsv($out, [
                        $record->vacationEmployee?->oracle_emp_no,
                        $employee?->name,
                        $employee?->branch?->name,
                        $employee?->department?->name,
                        $record->absence_type,
                        $record->start_date->toDateString(),
                        $record->end_date->toDateString(),
                        $record->days(),
                        $record->calendar_days,
                        $record->statusLabel($today),
                        $record->removed_at?->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($out);
        }, "leave-records-{$filters['from']}-to-{$filters['to']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{book: string, from: string, to: string, type: string, branch: ?int, department: ?int, q: string, withdrawn: bool} */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'book' => 'nullable|string|max:40',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|string|max:100',
            'branch' => 'nullable|integer',
            'department' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
            'withdrawn' => 'nullable|boolean',
        ]);

        // No range: this month. A start with no end: to the end of that month.
        $start = isset($v['from']) ? CarbonImmutable::parse($v['from']) : CarbonImmutable::today()->startOfMonth();
        $from = $start->toDateString();
        $to = $v['to'] ?? $start->endOfMonth()->toDateString();

        if ($to < $from) {
            throw ValidationException::withMessages(['to' => 'The end of the range is before its start.']);
        }
        if (CarbonImmutable::parse($from)->diff(CarbonImmutable::parse($to))->days + 1 > self::MAX_RANGE_DAYS) {
            throw ValidationException::withMessages(['to' => 'Look at a year or less at a time.']);
        }

        return [
            'book' => array_key_exists((string) ($v['book'] ?? ''), VacationEmployee::books()) ? $v['book'] : VacationEmployee::defaultBook(),
            'from' => $from,
            'to' => $to,
            'type' => trim((string) ($v['type'] ?? '')),
            'branch' => isset($v['branch']) ? (int) $v['branch'] : null,
            'department' => isset($v['department']) ? (int) $v['department'] : null,
            'q' => trim((string) ($v['q'] ?? '')),
            'withdrawn' => (bool) ($v['withdrawn'] ?? false),
        ];
    }

    /** The book's records, joined to the person and employee they belong to. */
    private function base(array $filters): Builder
    {
        return VacationAbsence::query()
            ->join('vacation_employees', 'vacation_employees.id', '=', 'vacation_absences.vacation_employee_id')
            ->leftJoin('employees', 'employees.id', '=', 'vacation_employees.employee_id')
            ->where('vacation_employees.book', $filters['book']);
    }

    private function records(array $filters): Builder
    {
        return $this->base($filters)
            ->select('vacation_absences.*')
            ->overlapping($filters['from'], $filters['to'])
            ->when(! $filters['withdrawn'], fn ($q) => $q->active())
            ->when($filters['type'] === 'leave', fn ($q) => $q->trips(false))
            ->when($filters['type'] === 'trips', fn ($q) => $q->trips())
            ->when($filters['type'] !== '' && ! array_key_exists($filters['type'], self::GROUPS),
                fn ($q) => $q->where('vacation_absences.absence_type', $filters['type']))
            ->when($filters['branch'], fn ($q, $id) => $q->where('employees.branch_id', $id))
            ->when($filters['department'], fn ($q, $id) => $q->where('employees.department_id', $id))
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('employees.name', 'like', "%{$filters['q']}%")
                ->orWhere('vacation_employees.oracle_emp_no', $filters['q'])))
            ->with([
                'vacationEmployee:id,book,oracle_emp_no,employee_id,match_method',
                'vacationEmployee.employee:id,name,branch_id,department_id,status',
                'vacationEmployee.employee.branch:id,name',
                'vacationEmployee.employee.department:id,name',
            ])
            ->orderBy('vacation_absences.start_date')
            ->orderBy('employees.name')
            ->orderBy('vacation_absences.id');
    }
}
