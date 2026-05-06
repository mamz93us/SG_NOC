<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AzureDevice;
use App\Models\Branch;
use App\Models\Device;
use Illuminate\Http\Request;

class BranchStoreController extends Controller
{
    public function index()
    {
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        $countsByBranch = Device::inStorage()
            ->whereNotNull('branch_id')
            ->selectRaw('branch_id, count(*) as c, max(updated_at) as last_activity')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id');

        $universalCount = Device::inUniversalStore()->count();
        $unlinkedIntuneCount = AzureDevice::whereNull('device_id')->count();

        $stats = [
            'total_in_storage'     => Device::inStorage()->count(),
            'branches_with_stock'  => $countsByBranch->count(),
            'universal_count'      => $universalCount,
            'unlinked_intune'      => $unlinkedIntuneCount,
        ];

        return view('admin.itam.stores.index', compact(
            'branches', 'countsByBranch', 'stats', 'universalCount', 'unlinkedIntuneCount'
        ));
    }

    public function show(Branch $branch, Request $request)
    {
        $devices = $this->buildStoreQuery(Device::inBranchStore($branch->id), $request);

        $types = Device::inBranchStore($branch->id)->distinct()->pluck('type')->filter()->values();

        return view('admin.itam.stores.show', [
            'branch'    => $branch,
            'devices'   => $devices,
            'types'     => $types,
            'isUniversal' => false,
        ]);
    }

    public function showUniversal(Request $request)
    {
        $devices = $this->buildStoreQuery(Device::inUniversalStore(), $request);

        $types = Device::inUniversalStore()->distinct()->pluck('type')->filter()->values();

        $unlinkedIntune = AzureDevice::whereNull('device_id')
            ->orderByDesc('last_sync_at')
            ->limit(20)
            ->get();
        $unlinkedIntuneCount = AzureDevice::whereNull('device_id')->count();

        return view('admin.itam.stores.universal', [
            'devices'              => $devices,
            'types'                => $types,
            'unlinkedIntune'       => $unlinkedIntune,
            'unlinkedIntuneCount'  => $unlinkedIntuneCount,
        ]);
    }

    private function buildStoreQuery($query, Request $request)
    {
        $query->with(['supplier', 'branch']);

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

        return $query->orderBy('storage_location')
            ->orderBy('asset_code')
            ->paginate(50)
            ->withQueryString();
    }
}
