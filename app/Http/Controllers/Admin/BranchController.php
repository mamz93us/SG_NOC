<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\UcmServer;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    private function ucmServers()
    {
        return UcmServer::active()->orderBy('name')->get();
    }

    public function index()
    {
        $branches = Branch::with('ucmServer')->orderBy('id')->paginate(10);
        return view('admin.branches.index', compact('branches'));
    }

    public function create()
    {
        $ucmServers = $this->ucmServers();
        return view('admin.branches.create', compact('ucmServers'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'id'                      => 'required|integer|unique:branches,id',
            'name'                    => 'required|string|max:255',
            'phone_number'            => 'nullable|string|max:50',
            'ucm_server_id'           => 'nullable|exists:ucm_servers,id',
            'ext_range_start'         => 'nullable|integer|min:1',
            'ext_range_end'           => 'nullable|integer|min:1',
            'profile_office_template' => 'nullable|string|max:255',
            'profile_phone_template'  => 'nullable|string|max:255',
        ]);

        Branch::create($request->only([
            'id', 'name', 'phone_number',
            'ucm_server_id', 'ext_range_start', 'ext_range_end',
            'profile_office_template', 'profile_phone_template',
        ]));

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch created successfully.');
    }

    public function edit(Branch $branch)
    {
        $ucmServers = $this->ucmServers();
        return view('admin.branches.edit', compact('branch', 'ucmServers'));
    }

    public function update(Request $request, Branch $branch)
    {
        $request->validate([
            'id'                      => 'required|integer|unique:branches,id,' . $branch->id,
            'name'                    => 'required|string|max:255',
            'phone_number'            => 'nullable|string|max:50',
            'ucm_server_id'           => 'nullable|exists:ucm_servers,id',
            'ext_range_start'         => 'nullable|integer|min:1',
            'ext_range_end'           => 'nullable|integer|min:1',
            'profile_office_template' => 'nullable|string|max:255',
            'profile_phone_template'  => 'nullable|string|max:255',
        ]);

        $branch->update($request->only([
            'id', 'name', 'phone_number',
            'ucm_server_id', 'ext_range_start', 'ext_range_end',
            'profile_office_template', 'profile_phone_template',
        ]));

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch updated successfully.');
    }

    public function destroy(Branch $branch)
    {
        $branch->delete();

        return redirect()
            ->route('admin.branches.index')
            ->with('success', 'Branch deleted successfully.');
    }
}
