<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\IpamSubnet;
use App\Models\ActivityLog;
use App\Services\IpamService;
use Illuminate\Http\Request;

class IpamController extends Controller
{
    public function __construct(protected IpamService $ipam) {}

    public function index(Request $request)
    {
        $branchId   = $request->input('branch');
        $subnetTree = $this->ipam->getSubnetTree($branchId ? (int) $branchId : null);
        $branches   = Branch::orderBy('name')->get();

        return view('admin.network.ipam.index', compact('subnetTree', 'branches'));
    }

    public function show(IpamSubnet $subnet)
    {
        $subnet->load('branch');
        $grid = $this->ipam->getIpGrid($subnet);

        return view('admin.network.ipam.show', compact('subnet', 'grid'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'cidr'        => [
                'required', 
                'string', 
                'regex:/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\/\d{1,2}$/',
                'unique:ipam_subnets,cidr,NULL,id,branch_id,' . $request->branch_id
            ],
            'vlan'        => 'nullable|integer|min:1|max:4094',
            'gateway'     => 'nullable|ip',
            'description' => 'nullable|string|max:255',
        ]);

        $validated['source'] = 'manual';

        $subnet = IpamSubnet::create($validated);

        ActivityLog::log('created', $subnet, $validated);

        return redirect()->route('admin.network.ipam.show', $subnet)
            ->with('success', "Subnet {$subnet->cidr} created.");
    }

    public function search(Request $request)
    {
        $query   = $request->input('q', '');
        $results = $query ? $this->ipam->searchGlobal($query) : ['reservations' => collect(), 'leases' => collect(), 'clients' => collect()];

        return view('admin.network.ipam.search', compact('query', 'results'));
    }
}
