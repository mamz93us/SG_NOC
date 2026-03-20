<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkflowTemplate;
use App\Services\Workflow\WorkflowStepRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WorkflowTemplateController extends Controller
{
    public function index()
    {
        $this->authorize('manage-workflow-templates');
        $templates = WorkflowTemplate::orderBy('is_system', 'desc')->orderBy('display_name')->get();
        return view('admin.workflow-templates.index', compact('templates'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-workflow-templates');

        $validated = $request->validate([
            'type_slug'     => 'required|string|max:50|unique:workflow_templates,type_slug',
            'display_name'  => 'required|string|max:100',
            'description'   => 'nullable|string|max:500',
            'approval_chain' => 'required|array|min:1',
            'approval_chain.*' => 'required|string|in:hr,it_manager,manager,security,super_admin',
            'is_active'     => 'boolean',
        ]);

        $template = WorkflowTemplate::create([
            'type_slug'      => $validated['type_slug'],
            'display_name'   => $validated['display_name'],
            'description'    => $validated['description'] ?? null,
            'approval_chain' => $validated['approval_chain'],
            'is_system'      => 0,
            'is_active'      => $request->boolean('is_active', true),
        ]);

        return back()->with('success', "Workflow template \"{$template->display_name}\" created.");
    }

    public function update(Request $request, WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        $validated = $request->validate([
            'display_name'  => 'required|string|max:100',
            'description'   => 'nullable|string|max:500',
            'approval_chain' => 'required|array|min:1',
            'approval_chain.*' => 'required|string|in:hr,it_manager,manager,security,super_admin',
            'is_active'     => 'boolean',
        ]);

        // System types: can edit chain/name but not slug
        $workflowTemplate->update([
            'display_name'   => $validated['display_name'],
            'description'    => $validated['description'] ?? null,
            'approval_chain' => $validated['approval_chain'],
            'is_active'      => $request->boolean('is_active', true),
        ]);

        return back()->with('success', "Workflow template \"{$workflowTemplate->display_name}\" updated.");
    }

    public function destroy(WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        if ($workflowTemplate->is_system) {
            return back()->with('error', 'System workflow templates cannot be deleted.');
        }

        $name = $workflowTemplate->display_name;
        $workflowTemplate->delete();

        return back()->with('success', "Workflow template \"{$name}\" deleted.");
    }

    // ─────────────────────────────────────────────────────────────
    // Visual Builder
    // ─────────────────────────────────────────────────────────────

    public function builder(WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        $jobRegistry = WorkflowStepRegistry::grouped();
        $triggerEvents = [
            'employee.created' => 'New Employee Created',
            'host.down'        => 'Host Goes Down',
            'device.assigned'  => 'Device Assigned to Employee',
        ];

        return view('admin.workflow-templates.builder', compact('workflowTemplate', 'jobRegistry', 'triggerEvents'));
    }

    public function saveDefinition(Request $request, WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        $request->validate([
            'definition'    => 'required|array',
            'trigger_event' => 'nullable|string|max:100',
        ]);

        // Save version snapshot before overwriting
        $workflowTemplate->createVersion(Auth::id());

        // Extract approval chain from graph for legacy engine compatibility
        $workflowTemplate->definition    = $request->input('definition');
        $workflowTemplate->trigger_event = $request->input('trigger_event') ?: null;
        $workflowTemplate->approval_chain = $workflowTemplate->extractApprovalChain();
        $workflowTemplate->save();

        return response()->json([
            'ok'      => true,
            'version' => $workflowTemplate->version,
            'message' => 'Definition saved.',
        ]);
    }

    public function versions(WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        $versions = $workflowTemplate->versions()
            ->with('editor:id,name')
            ->get(['id', 'version', 'changed_by', 'created_at']);

        return response()->json($versions);
    }

    public function restoreVersion(WorkflowTemplate $workflowTemplate, int $version)
    {
        $this->authorize('manage-workflow-templates');

        $snap = $workflowTemplate->versions()->where('version', $version)->firstOrFail();

        // Save current state first
        $workflowTemplate->createVersion(Auth::id());

        $workflowTemplate->update([
            'definition'     => $snap->definition,
            'approval_chain' => $snap->approval_chain ?? $workflowTemplate->extractApprovalChain(),
        ]);

        return response()->json([
            'ok'         => true,
            'version'    => $workflowTemplate->version,
            'definition' => $workflowTemplate->definition,
        ]);
    }
}
