<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\License;
use App\Models\AssetHistory;
use App\Models\Supplier;
use Illuminate\Http\Request;

class ItamController extends Controller
{
    public function index()
    {
        // Stats
        $stats = [
            'total'       => Device::count(),
            'assigned'    => Device::whereHas('currentAssignment')->count(),
            'available'   => Device::doesntHave('currentAssignment')->where('status', 'active')->count(),
            'maintenance' => Device::where('status', 'maintenance')->count(),
            'retired'     => Device::where('status', 'retired')->count(),
        ];

        // Financial
        $totalCost         = Device::whereNotNull('purchase_cost')->sum('purchase_cost');
        $totalCurrentValue = Device::whereNotNull('current_value')->sum('current_value');

        // Warranty expiring within 30 days
        $warrantyExpiring = Device::whereNotNull('warranty_expiry')
            ->where('warranty_expiry', '>', now())
            ->where('warranty_expiry', '<=', now()->addDays(30))
            ->orderBy('warranty_expiry')
            ->with('branch')
            ->limit(10)
            ->get();

        // License expiring within 30 days
        $licensesExpiring = License::whereNotNull('expiry_date')
            ->where('expiry_date', '>', now())
            ->where('expiry_date', '<=', now()->addDays(30))
            ->orderBy('expiry_date')
            ->limit(10)
            ->get();

        // Chart data - devices by type
        $byType = Device::selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->orderByDesc('count')
            ->pluck('count', 'type');

        // Chart data - devices by branch (top 10)
        $byBranch = Device::selectRaw('branch_id, count(*) as count')
            ->whereNotNull('branch_id')
            ->with('branch')
            ->groupBy('branch_id')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // Recent activity
        $recentActivity = AssetHistory::with(['device', 'user'])
            ->latest('created_at')
            ->limit(10)
            ->get();

        // Supplier count
        $supplierCount = Supplier::count();

        return view('admin.itam.dashboard', compact(
            'stats', 'totalCost', 'totalCurrentValue',
            'warrantyExpiring', 'licensesExpiring',
            'byType', 'byBranch', 'recentActivity', 'supplierCount'
        ));
    }
}
