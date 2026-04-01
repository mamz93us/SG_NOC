<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
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

    /**
     * AJAX: search devices by name/MAC/IP for the "Link to Asset" modal.
     * GET /admin/network/dhcp/device-search?q=...
     */
    public function deviceSearch(Request $request)
    {
        $q = $request->get('q', '');
        if (strlen($q) < 2) return response()->json([]);

        $devices = Device::where(function ($query) use ($q) {
                $query->where('name',         'like', "%{$q}%")
                      ->orWhere('mac_address', 'like', "%{$q}%")
                      ->orWhere('asset_code',  'like', "%{$q}%")
                      ->orWhere('ip_address',  'like', "%{$q}%");
            })
            ->select('id', 'name', 'type', 'asset_code', 'ip_address', 'mac_address')
            ->limit(15)
            ->get()
            ->map(fn($d) => [
                'id'         => $d->id,
                'name'       => $d->name,
                'type'       => $d->type,
                'asset_code' => $d->asset_code,
                'ip'         => $d->ip_address,
                'mac'        => $d->mac_address,
            ]);

        return response()->json($devices);
    }

    /**
     * POST /admin/network/dhcp/{lease}/link-asset
     * Link or unlink a DHCP lease to a device asset.
     */
    public function linkAsset(Request $request, DhcpLease $lease)
    {
        $deviceId = $request->input('device_id');

        if ($deviceId === 'unlink' || ! $deviceId) {
            $lease->update(['device_id' => null]);
            return back()->with('success', 'Asset link removed from lease ' . $lease->ip_address);
        }

        $device = Device::findOrFail($deviceId);
        $lease->update(['device_id' => $device->id]);

        // Also update device IP if it doesn't have one
        if (! $device->ip_address && $lease->ip_address) {
            $device->update(['ip_address' => $lease->ip_address]);
        }

        return back()->with('success', "Lease {$lease->ip_address} linked to asset {$device->name}.");
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
