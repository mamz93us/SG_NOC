<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IspConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IspConnectionController extends Controller
{
    public function index(Request $request)
    {
        $query = IspConnection::with(['branch', 'routerDevice'])
            ->orderBy('branch_id')
            ->orderBy('provider');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('provider')) {
            $query->where('provider', 'like', "%{$request->provider}%");
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('provider',   'like', "%{$s}%")
                  ->orWhere('circuit_id','like', "%{$s}%")
                  ->orWhere('static_ip', 'like', "%{$s}%")
                  ->orWhere('gateway',   'like', "%{$s}%");
            });
        }

        $connections = $query->paginate(50)->withQueryString();
        $branches    = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.network.isp.index', compact('connections', 'branches'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $routers  = Device::whereIn('type', ['router', 'firewall'])->orderBy('name')->get(['id', 'name', 'type']);
        return view('admin.network.isp.form', compact('branches', 'routers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'provider'         => 'required|string|max:255',
            'circuit_id'       => 'nullable|string|max:255',
            'speed_down'       => 'nullable|integer|min:0',
            'speed_up'         => 'nullable|integer|min:0',
            'static_ip'        => 'nullable|string|max:45',
            'gateway'          => 'nullable|string|max:45',
            'subnet'           => 'nullable|string|max:45',
            'router_device_id' => 'nullable|exists:devices,id',
            'contract_start'   => 'nullable|date',
            'contract_end'        => 'nullable|date|after_or_equal:contract_start',
            'renewal_date'        => 'nullable|date',
            'renewal_remind_days' => 'nullable|integer|min:1|max:90',
            'monthly_cost'        => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string',
        ]);

        $isp = IspConnection::create($data);

        ActivityLog::log('Created ISP connection: ' . $isp->provider . ' for branch #' . $isp->branch_id);

        return redirect()->route('admin.network.isp.index')
            ->with('success', 'ISP connection created.');
    }

    public function edit(IspConnection $isp)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $routers  = Device::whereIn('type', ['router', 'firewall'])->orderBy('name')->get(['id', 'name', 'type']);
        return view('admin.network.isp.form', compact('isp', 'branches', 'routers'));
    }

    public function update(Request $request, IspConnection $isp)
    {
        $data = $request->validate([
            'branch_id'        => 'required|exists:branches,id',
            'provider'         => 'required|string|max:255',
            'circuit_id'       => 'nullable|string|max:255',
            'speed_down'       => 'nullable|integer|min:0',
            'speed_up'         => 'nullable|integer|min:0',
            'static_ip'        => 'nullable|string|max:45',
            'gateway'          => 'nullable|string|max:45',
            'subnet'           => 'nullable|string|max:45',
            'router_device_id' => 'nullable|exists:devices,id',
            'contract_start'   => 'nullable|date',
            'contract_end'        => 'nullable|date|after_or_equal:contract_start',
            'renewal_date'        => 'nullable|date',
            'renewal_remind_days' => 'nullable|integer|min:1|max:90',
            'monthly_cost'        => 'nullable|numeric|min:0',
            'notes'               => 'nullable|string',
        ]);

        $isp->update($data);

        ActivityLog::log('Updated ISP connection: ' . $isp->provider . ' (#' . $isp->id . ')');

        return redirect()->route('admin.network.isp.index')
            ->with('success', 'ISP connection updated.');
    }

    public function destroy(IspConnection $isp)
    {
        $name = $isp->provider;
        $isp->delete();

        ActivityLog::log('Deleted ISP connection: ' . $name);

        return redirect()->route('admin.network.isp.index')
            ->with('success', 'ISP connection deleted.');
    }
}
