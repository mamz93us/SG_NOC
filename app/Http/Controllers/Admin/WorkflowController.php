<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AllowedDomain;
use App\Models\Branch;
use App\Models\Department;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Models\WorkflowRequest;
use App\Models\WorkflowStep;
use App\Services\Identity\GraphService;
use App\Services\Workflow\WorkflowEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkflowController extends Controller
{
    public function __construct(private WorkflowEngine $engine) {}

    // All workflows (admin)
    public function index(Request $request)
    {
        $query = WorkflowRequest::with('requester', 'branch')
            ->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $workflows = $query->paginate(20)->withQueryString();

        return view('admin.workflows.index', compact('workflows'));
    }

    // My submitted requests
    public function myRequests()
    {
        $workflows = WorkflowRequest::where('requested_by', Auth::id())
            ->with('branch')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.workflows.my-requests', compact('workflows'));
    }

    // Pending my approval
    public function pending()
    {
        $user      = Auth::user();
        $workflows = WorkflowRequest::where('status', 'pending')
            ->with('steps', 'requester', 'branch')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn ($w) => $w->isAwaitingMyApproval($user->id));

        return view('admin.workflows.pending', compact('workflows'));
    }

    // Detail view
    public function show(int $id)
    {
        $workflow = WorkflowRequest::with('steps.actor', 'steps.approver', 'logs', 'requester', 'branch')
            ->findOrFail($id);

        $canApprove = $workflow->isAwaitingMyApproval(Auth::id());

        return view('admin.workflows.show', compact('workflow', 'canApprove'));
    }

    // Create form
    public function create(Request $request)
    {
        $type        = $request->query('type');
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get(['id', 'name']);
        $settings    = Setting::get();
        // UPN domains for the email domain picker (primary first, then alphabetical)
        $upnDomains  = AllowedDomain::orderByDesc('is_primary')->orderBy('domain')->get();
        $types       = [
            'create_user'      => 'Create New User',
            'delete_user'      => 'Deactivate User',
            'license_change'   => 'License Change',
            'asset_assign'     => 'Assign Asset',
            'asset_return'     => 'Return Asset',
            'extension_create' => 'Create Extension',
            'extension_delete' => 'Delete Extension',
            'other'            => 'Other Request',
        ];

        return view('admin.workflows.create', compact('type', 'types', 'branches', 'departments', 'settings', 'upnDomains'));
    }

    // AJAX: preview provisioning data for create_user form
    public function previewUser(Request $request): \Illuminate\Http\JsonResponse
    {
        $settings  = Setting::get();
        $firstName = trim($request->query('first_name', ''));
        $lastName  = trim($request->query('last_name', ''));
        $branchId  = $request->query('branch_id');
        // Use domain selected in the form, fall back to global default
        $domain    = trim($request->query('domain', $settings->upn_domain ?? 'example.com')) ?: 'example.com';

        // Build preview UPN (no collision check needed — just a preview)
        $sanitize = function (string $s): string {
            $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
            return strtolower(preg_replace('/[^a-z0-9]/', '', $s));
        };
        $upn = ($firstName && $lastName)
            ? $sanitize($firstName) . '.' . $sanitize($lastName) . '@' . $domain
            : null;

        // Extension range (branch-aware)
        $branch = $branchId ? Branch::find($branchId) : null;
        $range  = $branch
            ? $branch->effectiveExtRange($settings)
            : ['start' => (int) ($settings->ext_range_start ?? 1000), 'end' => (int) ($settings->ext_range_end ?? 1999)];

        // UCM server name
        $ucmName = null;
        if ($branch) {
            $ucmName = $branch->effectiveUcmServer($settings)?->name;
        } elseif ($settings->default_ucm_id) {
            $ucmName = UcmServer::find($settings->default_ucm_id)?->name;
        }

        // Multi-license: return array of {sku, name} objects for display
        $licenseSkus = $settings->graph_default_license_skus ?? [];
        if (empty($licenseSkus) && $settings->graph_default_license_sku) {
            $licenseSkus = [$settings->graph_default_license_sku];
        }

        // Resolve friendly names via cached Azure SKU map
        $licenseData = [];
        if (!empty($licenseSkus)) {
            try {
                $skuMap = (new GraphService())->getSkuNameMap();
                foreach ($licenseSkus as $sku) {
                    $licenseData[] = ['sku' => $sku, 'name' => $skuMap[$sku] ?? $sku];
                }
            } catch (\Throwable) {
                // Azure unreachable — fall back to raw SKU IDs
                foreach ($licenseSkus as $sku) {
                    $licenseData[] = ['sku' => $sku, 'name' => $sku];
                }
            }
        }

        return response()->json(compact('upn', 'range', 'ucmName', 'licenseData'));
    }

    // Submit new request
    public function store(Request $request)
    {
        $validated = $request->validate([
            'type'        => 'required|in:create_user,delete_user,license_change,asset_assign,asset_return,extension_create,extension_delete,other',
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'branch_id'   => 'nullable|exists:branches,id',
        ]);

        $payload = $request->except(['type', 'title', 'description', 'branch_id', '_token']);

        $workflow = $this->engine->createRequest(
            type:        $validated['type'],
            payload:     $payload,
            branchId:    $validated['branch_id'] ?? null,
            requestedBy: Auth::id(),
            title:       $validated['title'],
            description: $validated['description'] ?? null,
        );

        return redirect()
            ->route('admin.workflows.show', $workflow->id)
            ->with('success', 'Workflow request submitted. Awaiting approval.');
    }

    // Approve current step
    public function approve(Request $request, int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);
        $user     = Auth::user();

        if (!$workflow->isAwaitingMyApproval($user->id)) {
            return back()->with('error', 'You are not authorized to approve this step.');
        }

        $comments = $request->input('comments');

        $step = $workflow->currentStepRecord();
        $this->engine->approveStep($workflow, $user, $comments);

        return redirect()
            ->route('admin.workflows.show', $id)
            ->with('success', 'Step approved successfully.');
    }

    // Reject current step
    public function reject(Request $request, int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);
        $user     = Auth::user();

        if (!$workflow->isAwaitingMyApproval($user->id)) {
            return back()->with('error', 'You are not authorized to reject this step.');
        }

        $request->validate(['comments' => 'nullable|string|max:1000']);

        $this->engine->rejectStep($workflow, $user, $request->input('comments'));

        return redirect()
            ->route('admin.workflows.show', $id)
            ->with('success', 'Request rejected.');
    }

    // Cancel draft
    public function cancel(int $id)
    {
        $workflow = WorkflowRequest::where('id', $id)
            ->where('requested_by', Auth::id())
            ->where('status', 'draft')
            ->firstOrFail();

        $workflow->update(['status' => 'rejected']);

        return redirect()
            ->route('admin.workflows.my-requests')
            ->with('success', 'Request cancelled.');
    }

    // Retry a failed workflow execution (skips approval — already approved)
    public function retry(int $id)
    {
        $workflow = WorkflowRequest::findOrFail($id);

        if (!in_array($workflow->status, ['failed', 'completed'])) {
            return back()->with('error', 'Only failed or completed workflows can be retried.');
        }

        try {
            $this->engine->logEvent($workflow, 'info', 'Manual retry triggered by ' . Auth::user()->name . '.');
            $this->engine->executeWorkflow($workflow);
        } catch (\Throwable $e) {
            $this->engine->markFailed($workflow, $e->getMessage());
            return redirect()
                ->route('admin.workflows.show', $id)
                ->with('error', 'Retry failed: ' . $e->getMessage());
        }

        return redirect()
            ->route('admin.workflows.show', $id)
            ->with('success', 'Workflow retried successfully.');
    }
}
