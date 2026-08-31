<?php

namespace App\Http\Controllers\Home;

use App\Http\Controllers\Controller;
use App\Models\AccessoryAssignment;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Models\EmployeeItem;
use App\Models\LicenseAssignment;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "My Assets" on the employee home portal — read-only, and only ever the
 * signed-in person's own.
 *
 * The same shape as Portal\MyAssetsController, re-homed rather than reused
 * because host isolation admits only the `home.*` namespace and `/portal`
 * 404s here. Every query is scoped by the employee resolved from the session,
 * so there is no id in the URL to tamper with.
 */
class HomeAssetsController extends Controller
{
    public function index(Request $request): View
    {
        $employee = Employee::with(['branch', 'department'])
            ->where('email', $request->user()->email)
            ->first();

        return view('home.assets', array_merge(
            ['user' => $request->user(), 'employee' => $employee],
            $this->assetsFor($employee),
        ));
    }

    /**
     * Everything currently or previously assigned to this person.
     *
     * Returns empty collections when there is no HR record, so the page renders
     * an explanation rather than failing — identity sync not having matched
     * someone is a normal state, not an error.
     */
    public function assetsFor(?Employee $employee): array
    {
        $itAssets = collect();
        $items = collect();
        $accessories = collect();
        $licenses = collect();

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

        return [
            'itAssets' => $itAssets,
            'items' => $items,
            'accessories' => $accessories,
            'licenses' => $licenses,
            // "Active" means not yet returned — that is what the tile counts.
            'activeCounts' => [
                'it_assets' => $itAssets->whereNull('returned_date')->count(),
                'items' => $items->whereNull('returned_date')->count(),
                'accessories' => $accessories->whereNull('returned_date')->count(),
                'licenses' => $licenses->count(),
            ],
        ];
    }

    /** Total active assignments, for the Quick access tile. */
    public static function activeCountFor(?Employee $employee): int
    {
        if (! $employee) {
            return 0;
        }

        try {
            return EmployeeAsset::where('employee_id', $employee->id)->whereNull('returned_date')->count()
                + EmployeeItem::where('employee_id', $employee->id)->whereNull('returned_date')->count()
                + AccessoryAssignment::where('employee_id', $employee->id)->whereNull('returned_date')->count();
        } catch (\Throwable) {
            // A missing table must not blank the portal.
            return 0;
        }
    }
}
