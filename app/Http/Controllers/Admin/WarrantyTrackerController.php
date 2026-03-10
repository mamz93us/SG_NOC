<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\Request;

class WarrantyTrackerController extends Controller
{
    public function index(Request $request)
    {
        $query = Device::with('branch')->whereNotNull('warranty_expiry');

        // Filters
        if ($request->filled('status')) {
            switch ($request->status) {
                case 'expired':
                    $query->where('warranty_expiry', '<', now());
                    break;
                case 'expiring':
                    $query->whereBetween('warranty_expiry', [now(), now()->addDays(90)]);
                    break;
                case 'valid':
                    $query->where('warranty_expiry', '>', now()->addDays(90));
                    break;
            }
        }

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('serial_number', 'like', "%{$search}%")
                  ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $devices = $query->orderBy('warranty_expiry')->paginate(25)->withQueryString();

        $branches = \App\Models\Branch::orderBy('name')->get();

        // Summary stats
        $allWithWarranty = Device::whereNotNull('warranty_expiry');
        $expiredCount  = (clone $allWithWarranty)->where('warranty_expiry', '<', now())->count();
        $expiringCount = (clone $allWithWarranty)->whereBetween('warranty_expiry', [now(), now()->addDays(90)])->count();
        $validCount    = (clone $allWithWarranty)->where('warranty_expiry', '>', now()->addDays(90))->count();

        return view('admin.devices.warranty', compact(
            'devices', 'branches', 'expiredCount', 'expiringCount', 'validCount'
        ));
    }
}
