<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OnboardingManagerToken;
use App\Models\WorkflowRequest;
use App\Services\Signature\SignatureRenderService;
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
     * GET /portal/hr/requests/{workflow}
     *
     * Read-only detail of one request HR raised. Deliberately assembles its own
     * view model rather than dumping the payload: the payload holds the initial
     * password and the UCM extension secret, and neither belongs on an HR screen.
     */
    public function showRequest(int $id): View
    {
        $workflow = WorkflowRequest::with(['branch', 'steps', 'logs'])
            ->whereIn('type', self::HR_TYPES)
            ->findOrFail($id);

        // HR sees what HR raised. Admins can see any of them, since they can
        // already open the same request in /admin/workflows.
        $user = Auth::user();
        if ($workflow->requested_by !== $user->id && ! $user->hasPermission('view-workflows')) {
            abort(404);
        }

        $payload = $workflow->payload ?? [];

        $employee = ! empty($payload['employee_id'])
            ? Employee::with(['branch', 'department', 'manager', 'supervisor'])->find($payload['employee_id'])
            : null;

        // Manager form state — HR's most common question is "has the manager
        // replied yet, and how long has this been sitting with them?"
        $token = OnboardingManagerToken::where('workflow_id', $workflow->id)
            ->latest()
            ->first();

        $managerForm = [
            'sent_to' => $token?->manager_email ?? ($payload['manager_email'] ?? null),
            'sent_at' => $token?->created_at,
            'responded_at' => $token?->responded_at,
            'expires_at' => $token?->expires_at,
            'answered' => (bool) $token?->responded_at,
            // Only meaningful while we are actually waiting on them.
            'waiting_since' => $workflow->status === 'awaiting_manager_form' ? $token?->created_at : null,
        ];

        // Rendered email signature, so HR can see what the new hire will send as.
        $signatureHtml = null;
        $signatureError = null;
        if (! empty($payload['upn'])) {
            try {
                $signatureHtml = app(SignatureRenderService::class)
                    ->resolveAndRender($payload['upn'], 'new_email');
                if (! $signatureHtml) {
                    $signatureError = 'No signature template matches this mailbox’s domain yet.';
                }
            } catch (\Throwable $e) {
                $signatureError = $e->getMessage();
            }
        }

        return view('portal.hr.request_show', [
            'workflow' => $workflow,
            'payload' => $payload,
            'employee' => $employee,
            'managerForm' => $managerForm,
            'signatureHtml' => $signatureHtml,
            'signatureError' => $signatureError,
            'stages' => $this->stagesFor($workflow, $payload, $managerForm),
        ]);
    }

    /**
     * Progress rows for the request page — what has happened, what is next, and
     * (critically for HR) which step the request is actually sitting on.
     *
     * @return array<int, array{label:string, detail:string, state:string}>
     */
    private function stagesFor(WorkflowRequest $workflow, array $payload, array $managerForm): array
    {
        $done = fn (string $label, string $detail) => compact('label', 'detail') + ['state' => 'done'];
        $now = fn (string $label, string $detail) => compact('label', 'detail') + ['state' => 'current'];
        $todo = fn (string $label, string $detail) => compact('label', 'detail') + ['state' => 'pending'];

        if ($workflow->type !== 'create_user') {
            // Offboarding / data-change requests have their own shapes; the
            // status badge plus the log is enough for those.
            return [];
        }

        $approved = ! in_array($workflow->status, ['pending', 'draft'], true);
        $accountCreated = ! empty($payload['azure_id']);
        $completed = $workflow->status === 'completed';

        $stages = [];
        $stages[] = $done('Submitted', 'Raised by '.($payload['hr_submitter_name'] ?? 'HR'));

        $stages[] = $workflow->status === 'pending'
            ? $now('IT approval', 'Waiting for IT to review the request')
            : $done('IT approval', 'Approved by IT');

        $stages[] = match (true) {
            $accountCreated => $done('Account created', ($payload['upn'] ?? 'Mailbox').' — licences and groups assigned'),
            $approved => $now('Account created', 'Creating the Microsoft account, licences and groups'),
            default => $todo('Account created', 'Runs as soon as IT approves'),
        };

        $stages[] = match (true) {
            $managerForm['answered'] => $done('Manager setup form', 'Answered '.$managerForm['responded_at']?->diffForHumans()),
            $workflow->status === 'awaiting_manager_form' => $now('Manager setup form', 'Waiting on '.($managerForm['sent_to'] ?? 'the manager')),
            default => $todo('Manager setup form', 'Sent once the account exists'),
        };

        $stages[] = $completed
            ? $done('Setup complete', 'Extension, groups and tickets all done')
            : $todo('Setup complete', 'Phone extension, manager’s groups and IT tickets');

        return $stages;
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
