<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Landline;
use App\Models\UcmServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LandlineController extends Controller
{
    public function index(Request $request)
    {
        $query = Landline::with(['branch', 'gateway'])
            ->orderBy('branch_id')
            ->orderBy('phone_number');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('phone_number', 'like', "%{$s}%")
                  ->orWhere('provider',   'like', "%{$s}%")
                  ->orWhere('notes',      'like', "%{$s}%");
            });
        }

        $landlines = $query->paginate(50)->withQueryString();
        $branches  = Branch::orderBy('name')->get(['id', 'name']);
        $statuses  = Landline::statuses();

        return view('admin.telecom.landlines.index', compact('landlines', 'branches', 'statuses'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $gateways = UcmServer::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $statuses = Landline::statuses();
        return view('admin.telecom.landlines.form', compact('branches', 'gateways', 'statuses'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id'    => 'required|exists:branches,id',
            'phone_number' => 'required|string|max:20',
            'provider'     => 'nullable|string|max:255',
            'fxo_port'     => 'nullable|string|max:20',
            'gateway_id'   => 'nullable|exists:ucm_servers,id',
            'status'       => 'required|in:active,disconnected,spare',
            'notes'        => 'nullable|string',
        ]);

        $landline = Landline::create($data);

        ActivityLog::log('Created landline: ' . $landline->phone_number);

        return redirect()->route('admin.telecom.landlines.index')
            ->with('success', 'Landline created.');
    }

    public function edit(Landline $landline)
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $gateways = UcmServer::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $statuses = Landline::statuses();
        return view('admin.telecom.landlines.form', compact('landline', 'branches', 'gateways', 'statuses'));
    }

    public function update(Request $request, Landline $landline)
    {
        $data = $request->validate([
            'branch_id'    => 'required|exists:branches,id',
            'phone_number' => 'required|string|max:20',
            'provider'     => 'nullable|string|max:255',
            'fxo_port'     => 'nullable|string|max:20',
            'gateway_id'   => 'nullable|exists:ucm_servers,id',
            'status'       => 'required|in:active,disconnected,spare',
            'notes'        => 'nullable|string',
        ]);

        $landline->update($data);

        ActivityLog::log('Updated landline: ' . $landline->phone_number);

        return redirect()->route('admin.telecom.landlines.index')
            ->with('success', 'Landline updated.');
    }

    public function destroy(Landline $landline)
    {
        $number = $landline->phone_number;
        $landline->delete();

        ActivityLog::log('Deleted landline: ' . $number);

        return redirect()->route('admin.telecom.landlines.index')
            ->with('success', 'Landline deleted.');
    }
}
