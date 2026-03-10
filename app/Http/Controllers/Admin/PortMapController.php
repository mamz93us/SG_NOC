<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\NetworkSwitch;
use Illuminate\Http\Request;

class PortMapController extends Controller
{
    public function index(Request $request)
    {
        $branches = Branch::orderBy('name')->get();

        $switchQuery = NetworkSwitch::with(['ports', 'branch'])->orderBy('name');

        if ($request->filled('branch')) {
            $switchQuery->where('branch_id', $request->branch);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $switchQuery->where(function ($q) use ($s) {
                $q->where('name', 'like', "%{$s}%")
                  ->orWhere('serial', 'like', "%{$s}%")
                  ->orWhere('model', 'like', "%{$s}%");
            });
        }

        $switches = $switchQuery->get();

        // Collect unique VLANs for legend
        $allVlans = collect();
        foreach ($switches as $sw) {
            foreach ($sw->ports as $port) {
                if ($port->vlan) {
                    $allVlans->push($port->vlan);
                }
            }
        }
        $vlans = $allVlans->unique()->sort()->values();

        return view('admin.network.port-map', compact('switches', 'branches', 'vlans'));
    }
}
