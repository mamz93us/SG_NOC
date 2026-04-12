<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\InternetAccessLevel;
use App\Services\Identity\GraphService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InternetAccessLevelController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Settings page
    // ─────────────────────────────────────────────────────────────

    public function index()
    {
        $levels = InternetAccessLevel::ordered()->get();

        // Try to pull available Azure AD groups for the picker
        $azureGroups = [];
        $azureError  = null;
        try {
            $graph = new GraphService();
            $azureGroups = $graph->listGroups(); // returns [{id, displayName}]
        } catch (\Throwable $e) {
            $azureError = $e->getMessage();
        }

        return view('admin.settings.internet-access-levels', compact('levels', 'azureGroups', 'azureError'));
    }

    // ─────────────────────────────────────────────────────────────
    // Store new level
    // ─────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $data = $request->validate([
            'label'            => 'required|string|max:100|unique:internet_access_levels,label',
            'description'      => 'nullable|string|max:255',
            'azure_group_id'   => 'nullable|string|max:100',
            'azure_group_name' => 'nullable|string|max:255',
            'is_default'       => 'boolean',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        // Only one default allowed
        if (!empty($data['is_default'])) {
            InternetAccessLevel::query()->update(['is_default' => false]);
        }

        $level = InternetAccessLevel::create($data);

        ActivityLog::create([
            'model_type' => 'InternetAccessLevel',
            'model_id'   => $level->id,
            'action'     => 'created',
            'changes'    => ['label' => $level->label, 'azure_group_id' => $level->azure_group_id],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Access level \"".$level->label."\" created.");
    }

    // ─────────────────────────────────────────────────────────────
    // Update existing level
    // ─────────────────────────────────────────────────────────────

    public function update(Request $request, InternetAccessLevel $internetAccessLevel)
    {
        $data = $request->validate([
            'label'            => 'required|string|max:100|unique:internet_access_levels,label,'.$internetAccessLevel->id,
            'description'      => 'nullable|string|max:255',
            'azure_group_id'   => 'nullable|string|max:100',
            'azure_group_name' => 'nullable|string|max:255',
            'is_default'       => 'boolean',
            'sort_order'       => 'nullable|integer|min:0',
        ]);

        if (!empty($data['is_default'])) {
            InternetAccessLevel::query()->where('id', '!=', $internetAccessLevel->id)->update(['is_default' => false]);
        }

        $old = $internetAccessLevel->only(['label', 'azure_group_id']);
        $internetAccessLevel->update($data);

        ActivityLog::create([
            'model_type' => 'InternetAccessLevel',
            'model_id'   => $internetAccessLevel->id,
            'action'     => 'updated',
            'changes'    => ['old' => $old, 'new' => ['label' => $internetAccessLevel->label, 'azure_group_id' => $internetAccessLevel->azure_group_id]],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Access level \"".$internetAccessLevel->label."\" updated.");
    }

    // ─────────────────────────────────────────────────────────────
    // Delete level
    // ─────────────────────────────────────────────────────────────

    public function destroy(InternetAccessLevel $internetAccessLevel)
    {
        $label = $internetAccessLevel->label;
        $internetAccessLevel->delete();

        ActivityLog::create([
            'model_type' => 'InternetAccessLevel',
            'model_id'   => $internetAccessLevel->id,
            'action'     => 'deleted',
            'changes'    => ['label' => $label],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success', "Access level \"".$label."\" deleted.");
    }

    // ─────────────────────────────────────────────────────────────
    // AJAX: search Azure AD groups on-the-fly
    // ─────────────────────────────────────────────────────────────

    public function searchAzureGroups(Request $request)
    {
        $q = $request->input('q', '');
        try {
            $graph  = new GraphService();
            $groups = $graph->searchGroups($q); // [{id, displayName}]
            return response()->json(['groups' => $groups]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
