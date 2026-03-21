<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\BranchDepartmentGroupMapping;
use App\Models\Department;
use App\Models\IdentityGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BranchDepartmentGroupController extends Controller
{
    /**
     * GET /admin/identity/group-mappings
     */
    public function index(): View
    {
        $mappings = BranchDepartmentGroupMapping::with(['branch', 'department', 'identityGroup'])
            ->orderBy('id', 'desc')
            ->paginate(50);

        return view('admin.identity.group-mappings.index', compact('mappings'));
    }

    /**
     * GET /admin/identity/group-mappings/create
     */
    public function create(): View
    {
        $branches   = Branch::orderBy('name')->get();
        $departments = Department::orderBy('name')->get();
        $groups     = IdentityGroup::orderBy('display_name')->get();

        return view('admin.identity.group-mappings.create', compact('branches', 'departments', 'groups'));
    }

    /**
     * POST /admin/identity/group-mappings
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'branch_id'         => 'nullable|integer|exists:branches,id',
            'department_id'     => 'nullable|integer|exists:departments,id',
            'identity_group_id' => 'required|integer|exists:identity_groups,id',
            'is_active'         => 'boolean',
            'notes'             => 'nullable|string|max:500',
        ]);

        $data['is_active'] = $request->boolean('is_active', true);

        BranchDepartmentGroupMapping::create($data);

        return redirect('/admin/identity/group-mappings')
            ->with('success', 'Group mapping created.');
    }

    /**
     * DELETE /admin/identity/group-mappings/{mapping}
     */
    public function destroy(BranchDepartmentGroupMapping $groupMapping): RedirectResponse
    {
        $groupMapping->delete(); // route param: {groupMapping} → auto-resolves

        return redirect('/admin/identity/group-mappings')
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

        $groupIds = BranchDepartmentGroupMapping::getGroupsFor($branchId, $deptId);
        $groups   = IdentityGroup::whereIn('id', $groupIds)
            ->get(['id', 'display_name', 'group_type', 'security_enabled']);

        return response()->json($groups->map(fn($g) => [
            'id'          => $g->id,
            'name'        => $g->display_name,
            'type_label'  => $g->typeLabel(),
        ]));
    }
}
