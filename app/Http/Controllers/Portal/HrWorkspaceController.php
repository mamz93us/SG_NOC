<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\WorkflowRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * HR workspace hub — the landing page for the HR portal.
 *
 * Every HR action (onboard, terminate, update employee data) creates a
 * WorkflowRequest that IT reviews; nothing here writes to an employee record
 * directly. This page is the launcher plus a live view of what HR has raised.
 */
class HrWorkspaceController extends Controller
{
    /** Workflow types raised from the HR workspace, in display order. */
    public const HR_TYPES = ['create_user', 'employee_offboarding', 'employee_update'];

    public function index(): View
    {
        $userId = Auth::id();

        $recent = WorkflowRequest::with('branch')
            ->where('requested_by', $userId)
            ->whereIn('type', self::HR_TYPES)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        // Open = anything not in a terminal state, so HR can see what is still
        // waiting on IT or on a manager's form.
        $openCounts = WorkflowRequest::where('requested_by', $userId)
            ->whereIn('type', self::HR_TYPES)
            ->whereNotIn('status', ['completed', 'rejected', 'cancelled', 'failed'])
            ->selectRaw('type, COUNT(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $employeeCount = Employee::where('status', 'active')->count();

        return view('portal.hr.hub', compact('recent', 'openCounts', 'employeeCount'));
    }

    /**
     * Full request history for the HR workspace, filterable by type/status.
     */
    public function requests(Request $request): View
    {
        $query = WorkflowRequest::with('branch')
            ->where('requested_by', Auth::id())
            ->whereIn('type', self::HR_TYPES)
            ->orderByDesc('created_at');

        if ($request->filled('type') && in_array($request->input('type'), self::HR_TYPES, true)) {
            $query->where('type', $request->input('type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $requests = $query->paginate(25)->withQueryString();

        return view('portal.hr.requests', compact('requests'));
    }

    /**
     * Employee lookup for the termination / update pickers.
     * GET /portal/hr/employees/search?q=...
     */
    public function searchEmployees(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }

        $employees = Employee::with(['branch:id,name', 'department:id,name'])
            ->where('status', 'active')
            ->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('oracle_emp_no', 'like', "%{$term}%");
            })
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'job_title', 'branch_id', 'department_id', 'oracle_emp_no']);

        return response()->json($employees->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->name,
            'email' => $e->email,
            'job_title' => $e->job_title,
            'branch' => $e->branch?->name,
            'department' => $e->department?->name,
            'emp_no' => $e->oracle_emp_no,
        ]));
    }
}
