<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\DhcpLease;
use Illuminate\Http\Request;

class DhcpLeaseController extends Controller
{
    public function index(Request $request)
    {
        $query = DhcpLease::with(['branch', 'device', 'networkSwitch']);

        // Filters
        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('source')) {
            $query->where('source', $request->source);
        }
        if ($request->filled('vlan')) {
            $query->where('vlan', $request->vlan);
        }
        if ($request->filled('conflicts') && $request->conflicts) {
            $query->where('is_conflict', true);
        }
        if ($request->filled('search')) {
            $term = '%' . $request->search . '%';
            $query->where(function ($q) use ($term) {
                $q->where('ip_address', 'like', $term)
                  ->orWhere('mac_address', 'like', $term)
                  ->orWhere('hostname', 'like', $term);
            });
        }

        $leases = $query->orderByDesc('last_seen')->paginate(50)->withQueryString();

        // Summary stats
        $totalLeases    = DhcpLease::count();
        $merakiLeases   = DhcpLease::where('source', 'meraki')->count();
        $snmpLeases     = DhcpLease::whereIn('source', ['sophos', 'snmp'])->count();
        $conflictCount  = DhcpLease::where('is_conflict', true)->count();

        $branches = Branch::orderBy('name')->get();

        return view('admin.network.dhcp.index', compact(
            'leases', 'branches', 'totalLeases', 'merakiLeases', 'snmpLeases', 'conflictCount'
        ));
    }

    public function show(DhcpLease $lease)
    {
        $lease->load(['branch', 'device', 'networkSwitch', 'subnet']);

        return view('admin.network.dhcp.show', compact('lease'));
    }

    public function widget()
    {
        return response()->json([
            'total'     => DhcpLease::count(),
            'meraki'    => DhcpLease::where('source', 'meraki')->count(),
            'snmp'      => DhcpLease::whereIn('source', ['sophos', 'snmp'])->count(),
            'conflicts' => DhcpLease::where('is_conflict', true)->count(),
        ]);
    }
}
