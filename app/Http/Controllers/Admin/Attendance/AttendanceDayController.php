<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceAdjustment;
use App\Models\Attendance\AttendanceDay;
use App\Models\Attendance\AttendanceTask;
use App\Models\Attendance\BiotimeEmployee;
use App\Models\Attendance\BiotimeSource;
use App\Models\Branch;
use App\Models\Department;
use App\Services\Attendance\AttendanceDayBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Attendance → Check-in / Check-out: every person × day, with the
 * earliest punch as check-in and the latest as check-out, measured against
 * their shift.
 */
class AttendanceDayController extends Controller
{
    private const STATUSES = ['error', 'ok', 'unmapped', 'missing_out', 'absent', 'late', 'early_leave',
        'overtime', 'over_max', 'excused', 'adjusted', 'duplicates'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);

        $days = $this->scoped($filters)
            ->with(['employee:id,name,oracle_emp_no,department_id,branch_id', 'employee.department:id,name',
                'branch:id,name', 'shift:id,name,start_time,end_time', 'biotimeEmployee.source:id,name'])
            ->orderByDesc('work_date')
            ->orderByDesc('has_error')
            ->orderBy('first_in')
            ->paginate(50)
            ->withQueryString();

        return view('admin.attendance.days.index', [
            'days' => $days,
            'filters' => $filters,
            'stats' => $this->stats($filters),
            'sources' => BiotimeSource::orderBy('name')->get(['id', 'name']),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'departments' => Department::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(AttendanceDay $day): View
    {
        $day->load(['employee.department', 'employee.branch', 'biotimeEmployee.source', 'branch', 'shift']);

        return view('admin.attendance.days.show', [
            'day' => $day,
            'punches' => $day->punchesQuery()->with('source:id,name')->orderBy('punch_time')->orderBy('id')->get(),
            'adjustments' => $day->employee_id
                ? AttendanceAdjustment::with(['createdBy:id,name', 'revokedBy:id,name'])
                    ->where('employee_id', $day->employee_id)
                    ->where('work_date', $day->work_date->toDateString())
                    ->latest('id')
                    ->get()
                : collect(),
            'excuses' => AttendanceAdjustment::EXCUSES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = "attendance-{$filters['from']}-to-{$filters['to']}.csv";

        return response()->streamDownload(function () use ($filters) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic names as UTF-8

            fputcsv($out, ['Date', 'BioTime Code', 'Oracle No', 'Name', 'Department', 'Branch', 'Status', 'Shift',
                'Scheduled In', 'Scheduled Out', 'Check In', 'Check Out', 'Worked (h:mm)', 'Late (min)',
                'Early Leave (min)', 'Overtime (min)', 'Excuse', 'Punches', 'Errors', 'Warnings']);

            $this->scoped($filters)
                ->with(['employee:id,name,oracle_emp_no,department_id', 'employee.department:id,name',
                    'branch:id,name', 'shift:id,name'])
                ->orderBy('work_date')
                ->orderBy('emp_codes')
                ->orderBy('id')
                ->lazy(1000)
                ->each(function (AttendanceDay $d) use ($out) {
                    $errors = [];
                    $warnings = [];
                    foreach ($d->flagBadges() as $badge) {
                        $badge['error'] ? $errors[] = $badge['label'] : $warnings[] = $badge['label'];
                    }

                    fputcsv($out, [
                        $d->work_date->toDateString(),
                        $d->emp_codes,
                        $d->employee?->oracle_emp_no,
                        $d->employee?->name ?? 'UNMAPPED',
                        $d->employee?->department?->name,
                        $d->branch?->name,
                        $d->statusLabel(),
                        $d->shift?->name,
                        $d->scheduled_start?->format('H:i'),
                        $d->scheduled_end?->format('H:i'),
                        $d->first_in?->format('Y-m-d H:i'),
                        $d->last_out?->format('Y-m-d H:i'),
                        $d->worked_minutes === null ? '' : $d->workedLabel(),
                        $d->late_minutes,
                        $d->early_leave_minutes,
                        $d->overtime_minutes,
                        $d->excuse ? (AttendanceAdjustment::EXCUSES[$d->excuse] ?? $d->excuse) : '',
                        $d->punch_count,
                        implode('; ', $errors),
                        implode('; ', $warnings),
                    ]);
                });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Queued: rebuilding weeks for everyone takes far longer than a web request may. */
    public function reprocess(Request $request): RedirectResponse
    {
        $data = $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) > 92) {
            return back()->with('error', 'Rebuild at most 93 days at a time — use `php artisan attendance:process` for longer ranges.');
        }

        AttendanceTask::queue('rebuild', ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'employee_ids' => null],
            "Recalculate {$from->format('d M')} – {$to->format('d M')} (requested)", Auth::id());

        ActivityLog::create([
            'model_type' => 'AttendanceDay',
            'model_id' => 0,
            'action' => 'rebuild-queued',
            'changes' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "Recalculation of {$from->toDateString()} to {$to->toDateString()} queued — the banner above shows when it is done.");
    }

    /** @return array{from: string, to: string, source: ?int, branch: ?int, department: ?int, status: ?string, q: string} */
    private function filters(Request $request): array
    {
        $v = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'source' => 'nullable|integer',
            'branch' => 'nullable|integer',
            'department' => 'nullable|integer',
            'status' => 'nullable|in:'.implode(',', self::STATUSES),
            'q' => 'nullable|string|max:100',
        ]);

        $to = isset($v['to']) ? CarbonImmutable::parse($v['to']) : CarbonImmutable::today();
        $from = isset($v['from']) ? CarbonImmutable::parse($v['from']) : $to->subDays(6);
        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        return [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'source' => isset($v['source']) ? (int) $v['source'] : null,
            'branch' => isset($v['branch']) ? (int) $v['branch'] : null,
            'department' => isset($v['department']) ? (int) $v['department'] : null,
            'status' => $v['status'] ?? null,
            'q' => trim((string) ($v['q'] ?? '')),
        ];
    }

    private function scoped(array $f, bool $withStatus = true): Builder
    {
        $query = AttendanceDay::query()->whereBetween('work_date', [$f['from'], $f['to']]);

        if ($f['branch']) {
            $query->where('branch_id', $f['branch']);
        }

        if ($f['department']) {
            $query->whereHas('employee', fn ($e) => $e->where('department_id', $f['department']));
        }

        if ($f['source']) {
            $codes = BiotimeEmployee::where('biotime_source_id', $f['source']);
            $query->where(fn ($q) => $q
                ->whereIn('employee_id', (clone $codes)->whereNotNull('employee_id')->select('employee_id'))
                ->orWhereIn('biotime_employee_id', (clone $codes)->select('id')));
        }

        if ($f['q'] !== '') {
            $search = $f['q'];
            $query->where(fn ($q) => $q
                ->where('emp_codes', 'like', "%{$search}%")
                ->orWhereHas('employee', fn ($e) => $e
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('oracle_emp_no', $search)));
        }

        if ($withStatus) {
            match ($f['status']) {
                'error' => $query->where('has_error', true),
                'ok' => $query->where('has_error', false),
                'unmapped' => $query->whereNull('employee_id'),
                'missing_out' => $query->whereJsonContains('flags', AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT),
                'absent' => $query->where('status', AttendanceDayBuilder::STATUS_ABSENT),
                'late' => $query->where('late_minutes', '>', 0),
                'early_leave' => $query->where('early_leave_minutes', '>', 0),
                'overtime' => $query->where('overtime_minutes', '>', 0),
                'over_max' => $query->whereJsonContains('flags', AttendanceDayBuilder::FLAG_OVER_MAX_HOURS),
                'excused' => $query->where('status', AttendanceDayBuilder::STATUS_EXCUSED),
                'adjusted' => $query->whereJsonContains('flags', AttendanceDayBuilder::FLAG_ADJUSTED),
                'duplicates' => $query->whereJsonContains('flags', AttendanceDayBuilder::FLAG_DUPLICATES),
                default => null,
            };
        }

        return $query;
    }

    private function stats(array $filters): array
    {
        $base = $this->scoped($filters, false);

        return [
            'days' => (clone $base)->count(),
            'people' => (clone $base)->distinct()->count('subject_key'),
            'errors' => (clone $base)->where('has_error', true)->count(),
            'absent' => (clone $base)->where('status', AttendanceDayBuilder::STATUS_ABSENT)->count(),
            'missing_out' => (clone $base)->whereJsonContains('flags', AttendanceDayBuilder::FLAG_MISSING_CHECK_OUT)->count(),
            'late' => (clone $base)->where('late_minutes', '>', 0)->count(),
            'unmapped' => (clone $base)->whereNull('employee_id')->count(),
        ];
    }
}
