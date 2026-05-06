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
    public function dashboard()
    {
        // Stats
        $stats = [
            'total'       => Device::count(),
            'assigned'    => Device::whereHas('currentAssignment')->count(),
            'available'   => Device::doesntHave('currentAssignment')->where('status', 'active')->count(),
            'maintenance' => Device::where('status', 'maintenance')->count(),
            'retired'     => Device::where('status', 'retired')->count(),
        ];

        // Financial — grouped by currency since costs are stored without conversion
        $totalCostByCurrency = Device::whereNotNull('purchase_cost')
            ->selectRaw("COALESCE(currency, 'USD') as currency, SUM(purchase_cost) as total")
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->toArray();

        $totalCurrentValueByCurrency = Device::whereNotNull('current_value')
            ->selectRaw("COALESCE(currency, 'USD') as currency, SUM(current_value) as total")
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->toArray();

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
            'stats', 'totalCostByCurrency', 'totalCurrentValueByCurrency',
            'warrantyExpiring', 'licensesExpiring',
            'byType', 'byBranch', 'recentActivity', 'supplierCount'
        ));
    }
}
