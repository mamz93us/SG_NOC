<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetHistory;
use App\Models\Device;
use App\Models\Employee;
use App\Models\EmployeeAsset;
use App\Services\PhoneDeviceLookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhoneAutoAssignController extends Controller
{
    /**
     * Show review table of employees matched to phone devices via extension.
     */
    public function index()
    {
        $this->authorize('manage-assets');

        $employees = Employee::whereNotNull('extension_number')
            ->where('extension_number', '!=', '')
            ->where('status', 'active')
            ->with(['branch.ucmServer', 'ucmServer', 'activeAssets.device'])
            ->orderBy('name')
            ->get();

        $results = [];

        foreach ($employees as $emp) {
            $ucmServerId = $emp->ucm_server_id ?? $emp->branch?->ucmServer?->id;
            $lookup      = PhoneDeviceLookup::findByExtension($emp->extension_number, $ucmServerId);
            $device      = $lookup['device'] ?? null;
            $status      = 'not_found';

            if ($device) {
                $currentAssignment = $device->currentAssignment;
                if ($currentAssignment && $currentAssignment->employee_id === $emp->id) {
                    $status = 'already_assigned';
                } elseif ($currentAssignment) {
                    $status = 'assigned_elsewhere';
                } else {
                    $status = 'available';
                }
            }

            $results[] = [
                'employee' => $emp,
                'device'   => $device,
                'mac'      => $lookup['mac'] ?? null,
                'ip'       => $lookup['ip'] ?? null,
                'model'    => $lookup['model'] ?? null,
                'source'   => $lookup['source'] ?? null,
                'status'   => $status,
            ];
        }

        // Sort: available first, then already_assigned, then assigned_elsewhere, then not_found
        $order = ['available' => 0, 'already_assigned' => 1, 'assigned_elsewhere' => 2, 'not_found' => 3];
        usort($results, fn ($a, $b) => ($order[$a['status']] ?? 9) <=> ($order[$b['status']] ?? 9));

        return view('admin.devices.phone-auto-assign', compact('results'));
    }

    /**
     * Process confirmed bulk phone-device assignments.
     */
    public function store(Request $request)
    {
        $this->authorize('manage-assets');

        $request->validate([
            'assignments'   => 'required|array|min:1',
            'assignments.*' => 'required|string', // format: "employeeId:deviceId"
        ]);

        $count  = 0;
        $errors = [];

        DB::transaction(function () use ($request, &$count, &$errors) {
            foreach ($request->assignments as $pair) {
                [$employeeId, $deviceId] = explode(':', $pair);

                $device   = Device::find($deviceId);
                $employee = Employee::find($employeeId);

                if (!$device || !$employee) {
                    $errors[] = "Invalid pair: {$pair}";
                    continue;
                }

                // Skip if already assigned
                if (EmployeeAsset::where('asset_id', $deviceId)->whereNull('returned_date')->exists()) {
                    $errors[] = "\"{$device->name}\" is already assigned — skipped.";
                    continue;
                }

                EmployeeAsset::create([
                    'employee_id'   => $employeeId,
                    'asset_id'      => $deviceId,
                    'assigned_date' => now()->toDateString(),
                    'condition'     => 'good',
                    'notes'         => 'Auto-assigned via phone extension matching',
                ]);

                $device->update(['status' => 'assigned']);
                AssetHistory::record($device, 'assigned',
                    "Auto-assigned to {$employee->name} via extension {$employee->extension_number}");
                $count++;
            }
        });

        ActivityLog::log("Auto-assigned {$count} phone device(s) to employees");

        $msg = "Successfully assigned {$count} device(s).";
        if (!empty($errors)) {
            $msg .= ' ' . implode(' ', $errors);
        }

        return redirect()->route('admin.devices.phone-auto-assign')
            ->with($count > 0 ? 'success' : 'error', $msg);
    }
}
