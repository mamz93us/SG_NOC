<?php

namespace App\Http\Controllers\Admin\Attendance;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Attendance\AttendanceExport;
use App\Models\Attendance\AttendancePeriod;
use App\Models\Attendance\AttendanceTask;
use App\Models\Branch;
use App\Services\Attendance\AttendanceDayBuilder;
use App\Services\Attendance\AttendancePeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin → Attendance → Periods: the pay periods that go to Oracle — approve
 * and lock them, reopen them, download what was prepared.
 */
class AttendancePeriodController extends Controller
{
    public function index(): View
    {
        return view('admin.attendance.periods.index', [
            'periods' => AttendancePeriod::with([
                'branch:id,name',
                'approvedBy:id,name',
                'latestExport' => fn ($q) => $q->select(['attendance_exports.id', 'attendance_period_id', 'status', 'record_count', 'created_at']),
            ])->orderByDesc('date_from')->orderBy('branch_id')->get(),
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
            'branch_id' => 'nullable|integer|exists:branches,id',
            'name' => 'nullable|string|max:150',
        ]);

        $from = CarbonImmutable::parse($data['date_from']);
        $to = CarbonImmutable::parse($data['date_to']);
        $branchId = isset($data['branch_id']) ? (int) $data['branch_id'] : null;

        if ($from->diffInDays($to) > 62) {
            return back()->withInput()->with('error', 'A period can be at most 63 days.');
        }

        $clash = AttendancePeriod::overlapping($from->toDateString(), $to->toDateString(), $branchId)->with('branch:id,name')->first();
        if ($clash) {
            return back()->withInput()->with('error',
                "It overlaps \"{$clash->name}\" ({$clash->scopeLabel()}, {$clash->date_from->format('d M')} – {$clash->date_to->format('d M Y')}). "
                .'A day can be in only one period for the same people, or it would go to Oracle twice.');
        }

        $branch = $branchId ? Branch::find($branchId) : null;
        $period = AttendancePeriod::create([
            'name' => trim((string) ($data['name'] ?? '')) ?: $from->format('M Y').' · '.($branch?->name ?? 'All branches'),
            'branch_id' => $branchId,
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'status' => AttendancePeriod::OPEN,
            'created_by' => Auth::id(),
        ]);
        $this->log($period, 'created', $period->only(['name', 'branch_id', 'date_from', 'date_to']));

        return redirect()->route('admin.attendance.periods.show', $period)->with('success', "Period \"{$period->name}\" created.");
    }

    public function show(AttendancePeriod $period, AttendancePeriodService $service): View
    {
        $period->load(['branch:id,name', 'approvedBy:id,name', 'reopenedBy:id,name']);

        return view('admin.attendance.periods.show', [
            'period' => $period,
            'readiness' => $period->status === AttendancePeriod::OPEN ? $service->readiness($period) : null,
            'lockedDays' => $period->isLocked() ? $period->daysQuery()->where('attendance_period_id', $period->id)->count() : 0,
            'lateArrivals' => $service->punchesAfterApproval($period),
            'exports' => $period->exports()->with('createdBy:id,name')->latest('id')
                ->get(['id', 'attendance_period_id', 'sender', 'status', 'record_count', 'reference', 'message', 'created_by', 'created_at']),
            'exportQueued' => AttendanceTask::where('type', 'export')->whereIn('status', [AttendanceTask::PENDING, AttendanceTask::RUNNING])
                ->get()->contains(fn (AttendanceTask $t) => ($t->payload['period_id'] ?? null) === $period->id),
            'labels' => AttendanceDayBuilder::LABELS,
        ]);
    }

    public function destroy(AttendancePeriod $period): RedirectResponse
    {
        if ($period->status !== AttendancePeriod::OPEN || $period->exports()->exists()) {
            return back()->with('error', 'Only an open period that was never exported can be deleted.');
        }

        $period->delete();
        $this->log($period, 'deleted', ['name' => $period->name]);

        return redirect()->route('admin.attendance.periods.index')->with('success', "Period \"{$period->name}\" deleted.");
    }

    public function approve(AttendancePeriod $period, AttendancePeriodService $service): RedirectResponse
    {
        try {
            $service->approve($period, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Not approved: '.$e->getMessage());
        }

        $this->log($period, 'approved', ['date_from' => $period->date_from->toDateString(), 'date_to' => $period->date_to->toDateString()]);

        return back()->with('success', "\"{$period->name}\" is approved and its days are locked. The Oracle export is being prepared in the background.");
    }

    public function reopen(Request $request, AttendancePeriod $period, AttendancePeriodService $service): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        try {
            $service->reopen($period, Auth::id(), $data['reason']);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->log($period, 'reopened', ['reason' => $data['reason']]);

        return back()->with('success', "\"{$period->name}\" is open again. Its days are being recalculated with everything that changed since approval.");
    }

    public function export(AttendancePeriod $period): RedirectResponse
    {
        if (! $period->isLocked()) {
            return back()->with('error', 'Approve the period before exporting it.');
        }

        AttendanceTask::queue('export', ['period_id' => $period->id], "Prepare the Oracle export: {$period->name}", Auth::id());
        $this->log($period, 'export-queued', []);

        return back()->with('success', 'The Oracle export is being prepared again in the background.');
    }

    public function download(AttendanceExport $export, string $format): StreamedResponse
    {
        $payload = json_decode($export->payload, true) ?: ['records' => []];
        $name = 'attendance-'.Str::slug($export->period?->name ?? 'period').'-export-'.$export->id;

        if ($format === 'json') {
            return response()->streamDownload(function () use ($payload) {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }, "{$name}.json", ['Content-Type' => 'application/json']);
        }

        return response()->streamDownload(function () use ($payload) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads Arabic names as UTF-8
            fputcsv($out, AttendancePeriodService::RECORD_FIELDS);
            foreach ($payload['records'] ?? [] as $record) {
                fputcsv($out, array_map(
                    fn ($field) => is_bool($record[$field] ?? null) ? ($record[$field] ? 'yes' : 'no') : ($record[$field] ?? ''),
                    AttendancePeriodService::RECORD_FIELDS
                ));
            }
            fclose($out);
        }, "{$name}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function log(AttendancePeriod $period, string $action, array $changes): void
    {
        ActivityLog::create([
            'model_type' => 'AttendancePeriod',
            'model_id' => $period->id,
            'action' => $action,
            'changes' => $changes,
            'user_id' => Auth::id(),
        ]);
    }
}
