<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\TopologyService;
use Illuminate\Http\Request;

class TopologyController extends Controller
{
    public function __construct(private TopologyService $topology) {}

    public function index()
    {
        $branches = Branch::orderBy('name')->get();

        return view('admin.network.topology.index', compact('branches'));
    }

    public function data(Request $request)
    {
        $branchId = $request->input('branch_id');

        $graph = $this->topology->buildGraph($branchId ?: null);

        return response()->json($graph);
    }
}
