<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AccessoryAssignment;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\EmployeeItem;
use App\Models\LicenseAssignment;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class MyAssetsController extends Controller
{
    public function index(): View
    {
        $user     = Auth::user();
        $employee = Employee::where('email', $user->email)->first();

        $itAssets    = collect();
        $items       = collect();
        $accessories = collect();
        $licenses    = collect();

        if ($employee) {
            $itAssets = EmployeeAsset::with('device')
                ->where('employee_id', $employee->id)
                ->orderByDesc('assigned_date')
                ->get();

            $items = EmployeeItem::where('employee_id', $employee->id)
                ->orderByDesc('assigned_date')
                ->get();

            $accessories = AccessoryAssignment::with('accessory')
                ->where('employee_id', $employee->id)
                ->orderByDesc('assigned_date')
                ->get();

            $licenses = LicenseAssignment::with('license')
                ->where('assignable_type', Employee::class)
                ->where('assignable_id', $employee->id)
                ->orderByDesc('assigned_date')
                ->get();
        }

        $activeCounts = [
            'it_assets'   => $itAssets->whereNull('returned_date')->count(),
            'items'       => $items->whereNull('returned_date')->count(),
            'accessories' => $accessories->whereNull('returned_date')->count(),
            'licenses'    => $licenses->count(),
        ];

        return view('portal.assets', compact(
            'user', 'employee', 'itAssets', 'items', 'accessories', 'licenses', 'activeCounts'
        ));
    }
}
