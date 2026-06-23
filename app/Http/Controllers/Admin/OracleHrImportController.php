<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\HrImportBatch;
use App\Models\HrImportRow;
use App\Services\Identity\OracleHrImportService;
use Illuminate\Http\Request;

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
    public function index()
    {
        $batches = HrImportBatch::with('uploader')->latest()->take(20)->get();

        return view('admin.identity.hr-import', [
            'batches' => $batches,
            'batch' => null,
        ]);
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
     * Resolve one unmatched row (create / skip / link to existing employee).
     */
    public function resolveRow(Request $request, HrImportRow $row)
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:create,skip,link'],
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
