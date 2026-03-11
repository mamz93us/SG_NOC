<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\IpReservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IpReservationController extends Controller
{
    public function index(Request $request)
    {
        $query = IpReservation::with('branch')
            ->orderBy('branch_id')
            ->orderBy('ip_address');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('vlan')) {
            $query->where('vlan', $request->vlan);
        }
        if ($request->filled('device_type')) {
            $query->where('device_type', $request->device_type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('ip_address',   'like', "%{$s}%")
                  ->orWhere('device_name', 'like', "%{$s}%")
                  ->orWhere('mac_address', 'like', "%{$s}%")
                  ->orWhere('assigned_to', 'like', "%{$s}%");
            });
        }

        $reservations = $query->paginate(50)->withQueryString();
        $branches     = Branch::orderBy('name')->get(['id', 'name']);
        $deviceTypes  = IpReservation::deviceTypes();
        $vlans        = IpReservation::distinct()->whereNotNull('vlan')->orderBy('vlan')->pluck('vlan');

        return view('admin.network.ip-reservations.index', compact('reservations', 'branches', 'deviceTypes', 'vlans'));
    }

    public function create(Request $request)
    {
        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $deviceTypes = IpReservation::deviceTypes();
        $subnets     = \App\Models\IpamSubnet::with('branch')->orderBy('cidr')->get();
        $devices     = \App\Models\Device::orderBy('name')->get(['id', 'name', 'mac_address', 'type']);

        $reservation = new IpReservation();
        
        // Auto-fill from DHCP Lease
        if ($request->has('lease_id')) {
            $lease = \App\Models\DhcpLease::find($request->lease_id);
            if ($lease) {
                $reservation->branch_id = $lease->branch_id;
                $reservation->ip_address = $lease->ip_address;
                $reservation->mac_address = $lease->mac_address;
                $reservation->device_name = $lease->hostname;
            }
        }
        
        // Auto-fill from Network Client (Meraki)
        if ($request->has('client_id')) {
            $client = \App\Models\NetworkClient::find($request->client_id);
            if ($client) {
                $reservation->ip_address = $client->ip;
                $reservation->mac_address = $client->mac;
                $reservation->device_name = $client->description ?: $client->hostname;
            }
        }

        // Auto-fill for Device
        if ($request->has('device_id')) {
            $device = \App\Models\Device::find($request->device_id);
            if ($device) {
                $reservation->device_id = $device->id;
                $reservation->device_name = $device->name;
                $reservation->mac_address = $device->mac_address;
                $reservation->branch_id = $device->branch_id;
            }
        }

        // Auto-fill from raw GET parameters (e.g. from IP grid)
        if ($request->has('ip_address')) $reservation->ip_address = $request->ip_address;
        if ($request->has('mac_address')) $reservation->mac_address = $request->mac_address;
        if ($request->has('device_name')) $reservation->device_name = $request->device_name;
        if ($request->has('vlan')) $reservation->vlan = $request->vlan;
        if ($request->has('subnet_id')) $reservation->subnet_id = $request->subnet_id;
        if ($request->has('branch_id')) $reservation->branch_id = $request->branch_id;

        return view('admin.network.ip-reservations.form', compact('reservation', 'branches', 'deviceTypes', 'subnets', 'devices'));
    }

    public function store(Request $request, \App\Services\IpamService $ipamService)
    {
        $data = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'subnet_id'   => 'required|exists:ipam_subnets,id',
            'ip_address'  => 'nullable|ip', // nullable because of auto-assign
            'subnet'      => 'nullable|string|max:45',
            'device_type' => 'nullable|string|max:50',
            'device_name' => 'nullable|string|max:255',
            'device_id'   => 'nullable|exists:devices,id',
            'mac_address' => 'nullable|string|max:20',
            'vlan'        => 'nullable|integer|min:0|max:4094',
            'assigned_to' => 'nullable|string|max:255',
            'notes'       => 'nullable|string',
        ]);

        if (empty($data['ip_address']) && !empty($data['subnet_id'])) {
            $subnetModel = \App\Models\IpamSubnet::find($data['subnet_id']);
            $availableIp = $ipamService->autoAssignIp($subnetModel);
            if (!$availableIp) {
                return back()->withInput()->with('error', 'No available IPs in the selected subnet for auto-assignment.');
            }
            $data['ip_address'] = $availableIp;
            if (empty($data['vlan'])) {
                $data['vlan'] = $subnetModel->vlan;
            }
        } elseif (empty($data['ip_address'])) {
            return back()->withInput()->with('error', 'The IP address field is required when not auto-assigning from a subnet.');
        }

        $exists = IpReservation::where('branch_id', $data['branch_id'])
            ->where('ip_address', $data['ip_address'])->exists();
        if ($exists) {
            return back()->withInput()->with('error', 'This IP is already reserved in this branch.');
        }

        $reservation = IpReservation::create($data);

        // Update device IP if requested
        if (!empty($data['device_id'])) {
            \App\Models\Device::where('id', $data['device_id'])->update([
                'ip_address' => $data['ip_address'],
                'mac_address' => $data['mac_address']
            ]);
        }

        ActivityLog::log('Created IP reservation: ' . $reservation->ip_address);

        return redirect()->route('admin.network.ip-reservations.index')
            ->with('success', 'IP reservation created: ' . $reservation->ip_address);
    }

    public function edit(IpReservation $reservation)
    {
        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $deviceTypes = IpReservation::deviceTypes();
        $subnets     = \App\Models\IpamSubnet::with('branch')->orderBy('cidr')->get();
        $devices     = \App\Models\Device::orderBy('name')->get(['id', 'name', 'mac_address', 'type']);

        return view('admin.network.ip-reservations.form', compact('reservation', 'branches', 'deviceTypes', 'subnets', 'devices'));
    }

    public function update(Request $request, IpReservation $reservation)
    {
        $data = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'subnet_id'   => 'required|exists:ipam_subnets,id',
            'ip_address'  => 'required|ip',
            'subnet'      => 'nullable|string|max:45',
            'device_type' => 'nullable|string|max:50',
            'device_name' => 'nullable|string|max:255',
            'device_id'   => 'nullable|exists:devices,id',
            'mac_address' => 'nullable|string|max:20',
            'vlan'        => 'nullable|integer|min:0|max:4094',
            'assigned_to' => 'nullable|string|max:255',
            'notes'       => 'nullable|string',
        ]);

        $exists = IpReservation::where('branch_id', $data['branch_id'])
            ->where('ip_address', $data['ip_address'])
            ->where('id', '!=', $reservation->id)
            ->exists();
        if ($exists) {
            return back()->withInput()->with('error', 'This IP is already reserved in this branch.');
        }

        $reservation->update($data);

        // Update device IP if requested
        if (!empty($data['device_id'])) {
            \App\Models\Device::where('id', $data['device_id'])->update([
                'ip_address' => $data['ip_address'],
                'mac_address' => $data['mac_address']
            ]);
        }

        ActivityLog::log('Updated IP reservation: ' . $reservation->ip_address);

        return redirect()->route('admin.network.ip-reservations.index')
            ->with('success', 'IP reservation updated.');
    }

    public function destroy(IpReservation $reservation)
    {
        $ip = $reservation->ip_address;
        $reservation->delete();

        ActivityLog::log('Deleted IP reservation: ' . $ip);

        return redirect()->route('admin.network.ip-reservations.index')
            ->with('success', 'IP reservation deleted.');
    }
    
    public function getAvailableIp(Request $request, \App\Services\IpamService $ipamService)
    {
        $request->validate(['subnet_id' => 'required|exists:ipam_subnets,id']);
        $subnet = \App\Models\IpamSubnet::find($request->subnet_id);
        
        $ip = $ipamService->autoAssignIp($subnet);
        return response()->json([
            'ip_address' => $ip,
            'vlan' => $subnet->vlan,
            'subnet_str' => clone $subnet->cidr // could just format it
        ]);
    }
}
