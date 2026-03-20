<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WorkflowTemplate;
use Illuminate\Http\Request;

class WorkflowTriggerController extends Controller
{
    public function store(Request $request, WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        $request->validate([
            'trigger_event' => 'required|string|max:100',
        ]);

        // Clear any other template using this trigger (one template per event)
        WorkflowTemplate::where('trigger_event', $request->trigger_event)
            ->where('id', '!=', $workflowTemplate->id)
            ->update(['trigger_event' => null]);

        $workflowTemplate->update(['trigger_event' => $request->trigger_event]);

        return response()->json(['ok' => true, 'trigger_event' => $workflowTemplate->trigger_event]);
    }

    public function destroy(WorkflowTemplate $workflowTemplate)
    {
        $this->authorize('manage-workflow-templates');

        $workflowTemplate->update(['trigger_event' => null]);

        return response()->json(['ok' => true]);
    }
}
