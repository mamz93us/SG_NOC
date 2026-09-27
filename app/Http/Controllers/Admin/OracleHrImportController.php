<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Models\OraclePortal\PortalSetting;
use App\Services\Identity\OracleHrImportService;
use App\Services\OraclePortal\EmployeeSync;
use App\Services\OraclePortal\LeaverReview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin UI for importing the Oracle HRMS employee export into the NOC.
 * Routes live in the existing identity/ groups (view-identity / manage-identity).
 */
class OracleHrImportController extends Controller
{
    public function __construct(private OracleHrImportService $service) {}

    /**
     * Upload form + list of recent import batches.
     */
    public function index(LeaverReview $review)
    {
        $batches = HrImportBatch::with('uploader')->latest()->take(20)->get();
        $portal = PortalSetting::get();

        return view('admin.identity.hr-import', [
            'batches' => $batches,
            'batch' => null,
            'portalReady' => $portal->isConfigured() && $portal->sync_employees,
            'portalLastSync' => $portal->last_employees_sync_at,
            'leavers' => $review->leavers(),
            'ignoredLeavers' => $review->ignored(),
            'absent' => $review->absent(),
        ]);
    }

    /**
     * Stage Oracle's employee view now, rather than waiting for the nightly run.
     *
     * 617 people and about a second of HTTP, but the matcher runs a few queries
     * per row, so this is the one pull that is worth watching. Nothing is
     * written to employee records beyond what Oracle says about each person's
     * assignment — everything else waits for the review below.
     */
    public function pull(EmployeeSync $sync)
    {
        try {
            $result = $sync->sync(dryRun: false, userId: Auth::id());
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not pull from Oracle: '.$e->getMessage());
        }

        if ($result['unchanged']) {
            return back()->with('success', "Oracle is unchanged since the last pull ({$result['rows']} people) — nothing new to review.");
        }

        $message = "Staged {$result['rows']} people from Oracle — "
            ."{$result['batch']->matched_count} matched, {$result['batch']->unmatched_count} need a decision.";

        if ($result['leavers_refused']) {
            $message .= " Oracle called {$result['inactive']} matched people inactive, too many to believe, "
                .'so no assignment statuses were recorded.';
        } elseif ($result['inactive'] > 0) {
            $message .= " {$result['inactive']} are inactive in Oracle but still employed here — listed below. "
                .'Nothing was terminated.';
        }

        return redirect()
            ->route('admin.identity.hr-import.show', $result['batch'])
            ->with('success', $message);
    }

    /**
     * Accept Oracle's word that somebody has left.
     *
     * This is the only place in the whole integration that writes
     * `status = 'terminated'`, and it takes a person's click to get here — the
     * transition cascades into disabling their Microsoft account and flagging
     * every asset they hold.
     */
    public function terminateLeaver(Request $request, Employee $employee)
    {
        if ($employee->status === 'terminated') {
            return back()->with('error', "{$employee->name} is already recorded as terminated.");
        }

        $employee->update([
            'status' => 'terminated',
            'terminated_date' => now()->toDateString(),
        ]);

        ActivityLog::create([
            'model_type' => Employee::class,
            'model_id' => $employee->id,
            'model_label' => $employee->name,
            'action' => 'portal_leaver_terminated',
            'changes' => [
                'oracle_emp_no' => $employee->oracle_emp_no,
                'oracle_assignment_status' => $employee->oracle_assignment_status,
                'decided_by' => 'admin from the Oracle leaver list',
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "{$employee->name} is recorded as terminated. Their Microsoft account "
            .'has been disabled and any assets they hold are flagged for return.');
    }

    /**
     * Oracle is wrong about this one — mid-transfer, or running ahead of the
     * real last day. Remembered so the same row is not offered every night.
     */
    public function ignoreLeaver(Employee $employee)
    {
        $employee->forceFill(['oracle_leaver_ignored_at' => now()])->save();

        ActivityLog::create([
            'model_type' => Employee::class,
            'model_id' => $employee->id,
            'model_label' => $employee->name,
            'action' => 'portal_leaver_ignored',
            'changes' => ['oracle_emp_no' => $employee->oracle_emp_no],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', "{$employee->name} will not be listed again unless Oracle marks them "
            .'active and then inactive once more.');
    }

    /**
     * Parse an uploaded spreadsheet into a staged batch, then show its preview.
     */
    public function upload(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:10240'],
        ]);

        try {
            $batch = $this->service->parse($request->file('file'));
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not parse the file: '.$e->getMessage());
        }

        ActivityLog::log("Oracle HR import parsed: {$batch->filename} ({$batch->total_rows} rows, {$batch->matched_count} matched, {$batch->unmatched_count} unmatched).");

        return redirect()
            ->route('admin.identity.hr-import.show', $batch)
            ->with('success', "Parsed {$batch->total_rows} rows — {$batch->matched_count} matched, {$batch->unmatched_count} unmatched, {$batch->error_count} errors.");
    }

    /**
     * Preview a batch: matched diffs, unmatched rows to resolve, errors, and the
     * reconciliation lists (NOC employees not in HR / inactive accounts).
     */
    public function show(HrImportBatch $batch)
    {
        $batch->load('uploader');

        $matched = $batch->rows()
            ->whereIn('status', ['matched', 'applied', 'linked', 'created'])
            ->with(['matchedEmployee.branch', 'matchedEmployee.department', 'linkedEmployee', 'resolvedBranch'])
            ->orderBy('emp_name')
            ->get();

        $unmatched = $batch->rows()
            ->whereIn('status', ['unmatched', 'skipped'])
            ->with('resolvedBranch')
            ->orderBy('emp_name')
            ->get();

        $errorRows = $batch->rows()
            ->where('status', 'error')
            ->orderBy('row_number')
            ->get();

        $flagged = $this->service->flaggedEmployees();
        $employees = Employee::orderBy('name')->get(['id', 'name', 'email']);

        return view('admin.identity.hr-import', [
            'batches' => null,
            'batch' => $batch,
            'matched' => $matched,
            'unmatched' => $unmatched,
            'serviceCandidates' => $this->service->serviceCandidates($batch),
            'errorRows' => $errorRows,
            'flagged' => $flagged,
            'employees' => $employees,
        ]);
    }

    /**
     * Apply all matched rows in a batch onto their employees.
     */
    public function apply(HrImportBatch $batch)
    {
        $applied = $this->service->applyBatchMatched($batch);

        ActivityLog::log("Oracle HR import applied: {$batch->filename} — {$applied} employee(s) updated.");

        return back()->with('success', "Applied Oracle data to {$applied} employee(s).");
    }

    /**
     * Create an employee for every row describing someone with no mailbox of
     * their own — see OracleHrImportService::serviceCandidates().
     */
    public function createServiceEmployees(HrImportBatch $batch)
    {
        $result = $this->service->createServiceEmployees($batch);

        ActivityLog::log("Oracle HR import: {$result['created']} service employee(s) created from {$batch->filename}.");

        $message = "Created {$result['created']} service employee(s) — no mailbox, attendance and HR data only.";
        if ($result['skipped'] > 0) {
            $message .= " {$result['skipped']} row(s) were left for you: their Oracle number is already on an employee.";
        }

        return back()->with('success', $message);
    }

    /**
     * Resolve one unmatched row (create / skip / link to existing employee).
     */
    public function resolveRow(Request $request, HrImportRow $row)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:create,create_service,skip,link'],
            'link_employee_id' => ['required_if:decision,link', 'nullable', 'exists:employees,id'],
        ]);

        try {
            $this->service->resolveUnmatched(
                $row,
                $validated['decision'],
                $validated['link_employee_id'] ?? null
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not resolve row: '.$e->getMessage());
        }

        $row->batch->refreshCounts();

        return back()->with('success', "Row for {$row->emp_name} resolved ({$validated['decision']}).");
    }
}
