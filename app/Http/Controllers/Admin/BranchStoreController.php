<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use Illuminate\Http\Request;

class BranchStoreController extends Controller
{
    public function index()
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        $countsByBranch = Device::inStorage()
            ->selectRaw('branch_id, count(*) as c, max(updated_at) as last_activity')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $stats = [
            'total_in_storage' => Device::inStorage()->count(),
            'branches_with_stock' => $countsByBranch->count(),
        ];

        return view('admin.itam.stores.index', compact('branches', 'countsByBranch', 'stats'));
    }

    public function show(Branch $branch, Request $request)
    {
        $query = Device::inStorage()
            ->where('branch_id', $branch->id)
            ->with(['supplier', 'branch']);

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }
        if ($request->filled('condition')) {
            $query->where('condition', $request->condition);
        }
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(function ($w) use ($q) {
                $w->where('asset_code', 'like', "%{$q}%")
                  ->orWhere('name', 'like', "%{$q}%")
                  ->orWhere('serial_number', 'like', "%{$q}%")
                  ->orWhere('storage_location', 'like', "%{$q}%");
            });
        }

        $devices = $query->orderBy('storage_location')->orderBy('asset_code')->paginate(50)->withQueryString();

        $types = Device::inStorage()
            ->where('branch_id', $branch->id)
            ->distinct()
            ->pluck('type')
            ->filter()
            ->values();

        return view('admin.itam.stores.show', compact('branch', 'devices', 'types'));
    }
}
