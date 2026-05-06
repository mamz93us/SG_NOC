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
        $employeesWithAssets = Employee::active()
            ->whereHas('activeAssets')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id']);

        $allEmployees = Employee::active()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'branch_id']);

        $branches = Branch::orderBy('name')->get(['id', 'name']);

        // Counts so the user can see at a glance whether each source has anything
        $universalCount = Device::inUniversalStore()->count();
        $branchCounts   = Device::inStorage()
            ->whereNotNull('branch_id')
            ->selectRaw('branch_id, count(*) as c')
            ->groupBy('branch_id')
            ->pluck('c', 'branch_id');

        return view('admin.itam.transfer.index', compact(
            'employeesWithAssets', 'allEmployees', 'branches', 'universalCount', 'branchCounts'
        ));
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
                    'device_id'        => $a->device?->id,
                    'asset_code'       => $a->device?->asset_code,
                    'name'             => $a->device?->name,
                    'type'             => $a->device?->type,
                    'serial_number'    => $a->device?->serial_number,
                    'condition'        => $a->condition,
                    'assigned_date'    => $a->assigned_date?->format('Y-m-d'),
                    'storage_location' => $a->device?->storage_location,
                ];
            });

        return response()->json(['assets' => $assets]);
    }

    public function assetsForBranchStore(Branch $branch)
    {
        $assets = Device::inBranchStore($branch->id)
            ->orderBy('asset_code')
            ->get()
            ->map(fn ($d) => $this->mapStoreDevice($d));

        return response()->json(['assets' => $assets]);
    }

    public function assetsForUniversalStore()
    {
        $assets = Device::inUniversalStore()
            ->orderBy('asset_code')
            ->get()
            ->map(fn ($d) => $this->mapStoreDevice($d));

        return response()->json(['assets' => $assets]);
    }

    private function mapStoreDevice(Device $d): array
    {
        return [
            'device_id'        => $d->id,
            'asset_code'       => $d->asset_code,
            'name'             => $d->name,
            'type'             => $d->type,
            'serial_number'    => $d->serial_number,
            'condition'        => $d->condition,
            'storage_location' => $d->storage_location,
        ];
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'source_type'      => 'required|in:employee,branch_store,universal_store',
            'from_employee_id' => 'required_if:source_type,employee|nullable|exists:employees,id',
            'from_branch_id'   => 'required_if:source_type,branch_store|nullable|exists:branches,id',
            'asset_ids'        => 'required|array|min:1',
            'asset_ids.*'      => 'integer|exists:devices,id',
            'target_type'      => 'required|in:employee,branch_store,universal_store',
            'to_employee_id'   => 'required_if:target_type,employee|nullable|exists:employees,id',
            'to_branch_id'     => 'required_if:target_type,branch_store|nullable|exists:branches,id',
            'storage_location' => 'nullable|string|max:100',
            'transfer_date'    => 'required|date',
            'condition'        => 'required|in:good,fair,poor',
            'notes'            => 'nullable|string|max:1000',
        ]);

        if ($validated['source_type'] === 'universal_store' && $validated['target_type'] === 'universal_store') {
            return back()->withInput()->with('error', 'Cannot transfer within the universal store.');
        }

        $fromEmployee = $validated['source_type'] === 'employee'
            ? Employee::findOrFail($validated['from_employee_id']) : null;
        $fromBranch   = $validated['source_type'] === 'branch_store'
            ? Branch::findOrFail($validated['from_branch_id']) : null;
        $toEmployee   = $validated['target_type'] === 'employee'
            ? Employee::findOrFail($validated['to_employee_id']) : null;
        $toBranch     = $validated['target_type'] === 'branch_store'
            ? Branch::findOrFail($validated['to_branch_id']) : null;

        if ($fromEmployee && $toEmployee && $fromEmployee->id === $toEmployee->id) {
            return back()->withInput()->with('error', 'Source and target employee must be different.');
        }

        $transferGroupId = (string) Str::uuid();

        try {
            DB::transaction(function () use (
                $validated, $fromEmployee, $fromBranch, $toEmployee, $toBranch, $transferGroupId
            ) {
                foreach ($validated['asset_ids'] as $deviceId) {
                    $device = Device::findOrFail($deviceId);

                    // Validate the asset is actually at the claimed source.
                    if ($fromEmployee) {
                        $assignment = EmployeeAsset::where('asset_id', $deviceId)
                            ->where('employee_id', $fromEmployee->id)
                            ->whereNull('returned_date')
                            ->first();
                        if (!$assignment) {
                            throw new \RuntimeException("Asset {$device->asset_code} is not assigned to {$fromEmployee->name}.");
                        }
                        $assignment->update([
                            'returned_date' => $validated['transfer_date'],
                            'condition'     => $validated['condition'],
                            'notes'         => $validated['notes'],
                        ]);
                    } else {
                        if ($device->currentAssignment) {
                            throw new \RuntimeException("Asset {$device->asset_code} has an active employee assignment — return it first.");
                        }
                        if (in_array($device->status, ['scrapped', 'retired'])) {
                            throw new \RuntimeException("Asset {$device->asset_code} is {$device->status}.");
                        }
                        if ($validated['source_type'] === 'branch_store' && $device->branch_id !== $fromBranch->id) {
                            throw new \RuntimeException("Asset {$device->asset_code} is not in {$fromBranch->name} store.");
                        }
                        if ($validated['source_type'] === 'universal_store' && $device->branch_id !== null) {
                            throw new \RuntimeException("Asset {$device->asset_code} is not in the universal store.");
                        }
                    }

                    // Capture the device's pre-move location for the history meta.
                    $fromStorageLocation = $device->storage_location;

                    // Apply target.
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
                        $eventType = 'transferred';
                    } elseif ($toBranch) {
                        $device->update([
                            'status'           => 'available',
                            'branch_id'        => $toBranch->id,
                            'storage_location' => $validated['storage_location'] ?? null,
                        ]);
                        $eventType = 'moved_to_storage';
                    } else { // universal_store target
                        $device->update([
                            'status'           => 'available',
                            'branch_id'        => null,
                            'storage_location' => $validated['storage_location'] ?? null,
                        ]);
                        $eventType = 'moved_to_storage';
                    }

                    $meta = $this->buildMeta(
                        $validated, $fromEmployee, $fromBranch, $toEmployee, $toBranch,
                        $transferGroupId, $fromStorageLocation
                    );

                    AssetHistory::record(
                        $device,
                        $eventType,
                        $this->buildDescription($validated, $fromEmployee, $fromBranch, $toEmployee, $toBranch),
                        $meta
                    );
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.itam.transfer.print', $transferGroupId)
            ->with('success', count($validated['asset_ids']) . ' asset(s) transferred successfully.');
    }

    private function buildMeta(
        array $validated,
        ?Employee $fromEmployee,
        ?Branch $fromBranch,
        ?Employee $toEmployee,
        ?Branch $toBranch,
        string $transferGroupId,
        ?string $fromStorageLocation
    ): array {
        $meta = [
            'transfer_group_id' => $transferGroupId,
            'condition'         => $validated['condition'],
            'source_type'       => $validated['source_type'],
            'target_type'       => $validated['target_type'],
        ];

        if ($fromEmployee) {
            $meta['from_employee_id'] = $fromEmployee->id;
            $meta['from_employee']    = $fromEmployee->name;
        }
        if ($fromBranch) {
            $meta['from_branch_id']        = $fromBranch->id;
            $meta['from_branch_name']      = $fromBranch->name;
            $meta['from_storage_location'] = $fromStorageLocation;
        }
        if ($validated['source_type'] === 'universal_store') {
            $meta['from_branch_name']      = 'Universal Store';
            $meta['from_storage_location'] = $fromStorageLocation;
        }

        if ($toEmployee) {
            $meta['to_employee_id'] = $toEmployee->id;
            $meta['to_employee']    = $toEmployee->name;
            $meta['branch_id']      = $toEmployee->branch_id;
        }
        if ($toBranch) {
            $meta['to_branch_id']     = $toBranch->id;
            $meta['to_branch_name']   = $toBranch->name;
            $meta['branch_id']        = $toBranch->id;
            $meta['storage_location'] = $validated['storage_location'] ?? null;
        }
        if ($validated['target_type'] === 'universal_store') {
            $meta['to_branch_name']   = 'Universal Store';
            $meta['storage_location'] = $validated['storage_location'] ?? null;
        }

        return $meta;
    }

    private function buildDescription(
        array $validated,
        ?Employee $fe,
        ?Branch $fb,
        ?Employee $te,
        ?Branch $tb
    ): string {
        $from = match ($validated['source_type']) {
            'employee'        => $fe?->name ?? 'Employee',
            'branch_store'    => "{$fb?->name} store",
            'universal_store' => 'Universal Store',
        };
        $to = match ($validated['target_type']) {
            'employee'        => $te?->name ?? 'Employee',
            'branch_store'    => "{$tb?->name} store",
            'universal_store' => 'Universal Store',
        };
        return "Transferred from {$from} to {$to}";
    }

    public function print(string $group)
    {
        $events = AssetHistory::with(['device', 'user'])
            ->whereIn('event_type', ['transferred', 'moved_to_storage'])
            ->where('meta->transfer_group_id', $group)
            ->get();

        abort_if($events->isEmpty(), 404, 'Transfer not found.');

        $first = $events->first();
        $meta  = $first->meta ?? [];

        $sourceType = $meta['source_type'] ?? ($first->event_type === 'transferred' ? 'employee' : 'employee');
        $targetType = $meta['target_type'] ?? ($first->event_type === 'transferred' ? 'employee' : 'branch_store');

        $fromEmployee = !empty($meta['from_employee_id']) ? Employee::find($meta['from_employee_id']) : null;
        $toEmployee   = !empty($meta['to_employee_id']) ? Employee::find($meta['to_employee_id']) : null;
        $fromBranch   = !empty($meta['from_branch_id']) ? Branch::find($meta['from_branch_id']) : null;
        $toBranch     = !empty($meta['to_branch_id']) ? Branch::find($meta['to_branch_id'])
            : (!empty($meta['branch_id']) && empty($meta['to_employee_id']) ? Branch::find($meta['branch_id']) : null);

        return view('admin.itam.transfer.print', [
            'events'                => $events,
            'transferGroupId'       => $group,
            'sourceType'            => $sourceType,
            'targetType'            => $targetType,
            'fromEmployee'          => $fromEmployee,
            'toEmployee'            => $toEmployee,
            'fromBranch'            => $fromBranch,
            'toBranch'              => $toBranch,
            'fromBranchName'        => $meta['from_branch_name'] ?? null,
            'toBranchName'          => $meta['to_branch_name'] ?? null,
            'fromStorageLocation'   => $meta['from_storage_location'] ?? null,
            'toStorageLocation'     => $meta['storage_location'] ?? null,
            'transferDate'          => $first->created_at,
        ]);
    }
}
