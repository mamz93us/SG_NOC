<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssetHistory;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssetTransferController extends Controller
{
    public function index()
    {
        $employees = Employee::active()
            ->whereHas('activeAssets')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id']);

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.itam.transfer.index', compact('employees', 'branches'));
    }

    public function assetsForEmployee(Employee $employee)
    {
        $assets = EmployeeAsset::with('device')
            ->where('employee_id', $employee->id)
            ->whereNull('returned_date')
            ->orderByDesc('assigned_date')
            ->get()
            ->map(function ($a) {
                return [
                    'assignment_id' => $a->id,
                    'device_id'     => $a->device?->id,
                    'asset_code'    => $a->device?->asset_code,
                    'name'          => $a->device?->name,
                    'type'          => $a->device?->type,
                    'serial_number' => $a->device?->serial_number,
                    'condition'     => $a->condition,
                    'assigned_date' => $a->assigned_date?->format('Y-m-d'),
                ];
            });

        return response()->json([
            'employee' => [
                'id'    => $employee->id,
                'name'  => $employee->name,
                'email' => $employee->email,
            ],
            'assets' => $assets,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'from_employee_id'    => 'required|exists:employees,id',
            'asset_ids'           => 'required|array|min:1',
            'asset_ids.*'         => 'integer|exists:devices,id',
            'target_type'         => 'required|in:employee,branch_store',
            'to_employee_id'      => 'required_if:target_type,employee|nullable|exists:employees,id|different:from_employee_id',
            'to_branch_id'        => 'required_if:target_type,branch_store|nullable|exists:branches,id',
            'storage_location'    => 'required_if:target_type,branch_store|nullable|string|max:100',
            'transfer_date'       => 'required|date',
            'condition'           => 'required|in:good,fair,poor',
            'notes'               => 'nullable|string|max:1000',
        ]);

        $fromEmployee = Employee::findOrFail($validated['from_employee_id']);
        $toEmployee   = $validated['target_type'] === 'employee'
            ? Employee::findOrFail($validated['to_employee_id'])
            : null;
        $toBranch     = $validated['target_type'] === 'branch_store'
            ? Branch::findOrFail($validated['to_branch_id'])
            : null;

        $transferGroupId = (string) Str::uuid();

        DB::transaction(function () use ($validated, $fromEmployee, $toEmployee, $toBranch, $transferGroupId) {
            foreach ($validated['asset_ids'] as $deviceId) {
                $assignment = EmployeeAsset::where('asset_id', $deviceId)
                    ->where('employee_id', $fromEmployee->id)
                    ->whereNull('returned_date')
                    ->firstOrFail();

                $assignment->update([
                    'returned_date' => $validated['transfer_date'],
                    'condition'     => $validated['condition'],
                    'notes'         => $validated['notes'],
                ]);

                $device = Device::findOrFail($deviceId);

                if ($toEmployee) {
                    EmployeeAsset::create([
                        'employee_id'   => $toEmployee->id,
                        'asset_id'      => $deviceId,
                        'assigned_date' => $validated['transfer_date'],
                        'condition'     => $validated['condition'],
                        'notes'         => $validated['notes'],
                    ]);

                    $device->update([
                        'status'           => 'assigned',
                        'storage_location' => null,
                        'branch_id'        => $toEmployee->branch_id ?: $device->branch_id,
                    ]);

                    AssetHistory::record(
                        $device,
                        'transferred',
                        "Transferred from {$fromEmployee->name} to {$toEmployee->name}",
                        [
                            'from_employee_id'  => $fromEmployee->id,
                            'from_employee'     => $fromEmployee->name,
                            'to_employee_id'    => $toEmployee->id,
                            'to_employee'       => $toEmployee->name,
                            'branch_id'         => $toEmployee->branch_id,
                            'transfer_group_id' => $transferGroupId,
                            'condition'         => $validated['condition'],
                        ]
                    );
                } else {
                    $device->update([
                        'status'           => 'available',
                        'branch_id'        => $toBranch->id,
                        'storage_location' => $validated['storage_location'],
                    ]);

                    AssetHistory::record(
                        $device,
                        'moved_to_storage',
                        "Returned from {$fromEmployee->name} to {$toBranch->name} store ({$validated['storage_location']})",
                        [
                            'from_employee_id'  => $fromEmployee->id,
                            'from_employee'     => $fromEmployee->name,
                            'branch_id'         => $toBranch->id,
                            'branch_name'       => $toBranch->name,
                            'storage_location'  => $validated['storage_location'],
                            'transfer_group_id' => $transferGroupId,
                            'condition'         => $validated['condition'],
                        ]
                    );
                }
            }
        });

        return redirect()
            ->route('admin.itam.transfer.print', $transferGroupId)
            ->with('success', count($validated['asset_ids']) . ' asset(s) transferred successfully.');
    }

    public function print(string $group)
    {
        $events = AssetHistory::with(['device', 'user'])
            ->whereIn('event_type', ['transferred', 'moved_to_storage'])
            ->where('meta->transfer_group_id', $group)
            ->get();

        abort_if($events->isEmpty(), 404, 'Transfer not found.');

        $first  = $events->first();
        $isStore = $first->event_type === 'moved_to_storage';

        $fromEmployee = Employee::find($first->meta['from_employee_id'] ?? null);
        $toEmployee   = !$isStore ? Employee::find($first->meta['to_employee_id'] ?? null) : null;
        $toBranch     = $isStore  ? Branch::find($first->meta['branch_id'] ?? null) : null;

        return view('admin.itam.transfer.print', [
            'events'           => $events,
            'transferGroupId'  => $group,
            'fromEmployee'     => $fromEmployee,
            'toEmployee'       => $toEmployee,
            'toBranch'         => $toBranch,
            'storageLocation'  => $isStore ? ($first->meta['storage_location'] ?? null) : null,
            'isStore'          => $isStore,
            'transferDate'     => $first->created_at,
        ]);
    }
}
