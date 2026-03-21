<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchDepartmentGroupMapping;
use App\Models\Department;
use App\Services\Identity\GraphService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchDepartmentGroupController extends Controller
{
    /**
     * GET /admin/identity/group-mappings
     */
    public function index(): View
    {
        $mappings    = BranchDepartmentGroupMapping::with(['branch', 'department'])->orderBy('id', 'desc')->get();
        $branches    = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();

        return view('admin.identity.group-mappings.index', compact('mappings', 'branches', 'departments'));
    }

    /**
     * POST /admin/identity/group-mappings
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'branch_id'        => 'nullable|integer|exists:branches,id',
            'department_id'    => 'nullable|integer|exists:departments,id',
            'azure_group_id'   => 'required|string|max:100',
            'azure_group_name' => 'required|string|max:200',
            'notes'            => 'nullable|string|max:500',
        ]);

        BranchDepartmentGroupMapping::create($data);

        return redirect()->route('admin.identity.group-mappings.index')
            ->with('success', 'Group mapping created.');
    }

    /**
     * DELETE /admin/identity/group-mappings/{mapping}
     */
    public function destroy(BranchDepartmentGroupMapping $mapping): \Illuminate\Http\RedirectResponse
    {
        $mapping->delete();

        return redirect()->route('admin.identity.group-mappings.index')
            ->with('success', 'Group mapping deleted.');
    }

    /**
     * GET /admin/identity/group-mappings/preview?branch_id=1&department_id=3
     * Returns groups that would be assigned for a given branch+dept combo.
     */
    public function preview(Request $request): JsonResponse
    {
        $branchId = $request->integer('branch_id') ?: null;
        $deptId   = $request->integer('department_id') ?: null;

        $groups = BranchDepartmentGroupMapping::getGroupsFor($branchId, $deptId)
            ->map(fn($m) => [
                'id'          => $m->id,
                'group_id'    => $m->azure_group_id,
                'group_name'  => $m->azure_group_name,
                'branch_name' => $m->branch?->name ?? '(All Branches)',
                'dept_name'   => $m->department?->name ?? '(All Departments)',
            ]);

        return response()->json($groups);
    }

    /**
     * GET /admin/identity/group-mappings/search-azure?q=Sales
     * Search Azure groups for the autocomplete.
     */
    public function searchAzure(Request $request): JsonResponse
    {
        $q = $request->input('q', '');

        try {
            $graph  = new GraphService();
            $groups = $graph->searchGroups($q, 20);
            return response()->json($groups);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
