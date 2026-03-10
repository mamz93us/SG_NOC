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

    public function create()
    {
        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $deviceTypes = IpReservation::deviceTypes();
        return view('admin.network.ip-reservations.form', compact('branches', 'deviceTypes'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'ip_address'  => 'required|ip',
            'subnet'      => 'nullable|string|max:45',
            'device_type' => 'nullable|string|max:50',
            'device_name' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:20',
            'vlan'        => 'nullable|integer|min:0|max:4094',
            'assigned_to' => 'nullable|string|max:255',
            'notes'       => 'nullable|string',
        ]);

        $exists = IpReservation::where('branch_id', $data['branch_id'])
            ->where('ip_address', $data['ip_address'])->exists();
        if ($exists) {
            return back()->withInput()->with('error', 'This IP is already reserved in this branch.');
        }

        $reservation = IpReservation::create($data);

        ActivityLog::log('Created IP reservation: ' . $reservation->ip_address);

        return redirect()->route('admin.network.ip-reservations.index')
            ->with('success', 'IP reservation created.');
    }

    public function edit(IpReservation $reservation)
    {
        $branches    = Branch::orderBy('name')->get(['id', 'name']);
        $deviceTypes = IpReservation::deviceTypes();
        return view('admin.network.ip-reservations.form', compact('reservation', 'branches', 'deviceTypes'));
    }

    public function update(Request $request, IpReservation $reservation)
    {
        $data = $request->validate([
            'branch_id'   => 'required|exists:branches,id',
            'ip_address'  => 'required|ip',
            'subnet'      => 'nullable|string|max:45',
            'device_type' => 'nullable|string|max:50',
            'device_name' => 'nullable|string|max:255',
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
}
