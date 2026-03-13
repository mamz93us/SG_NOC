<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\AssetHistory;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\AzureBranchMapping;
use App\Services\AzureDeviceService;
use Illuminate\Http\Request;

class AzureSyncController extends Controller
{
    public function index(Request $request)
    {
        $pending = AzureDevice::where('link_status', 'pending')
            ->with('device')
            ->orderBy('display_name')
            ->get();

        $query = AzureDevice::with('device');

        // Search (Global)
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('display_name', 'like', "%{$s}%")
                  ->orWhere('serial_number', 'like', "%{$s}%")
                  ->orWhere('upn', 'like', "%{$s}%");
            });
        }

        // Specific Filters
        if ($request->filled('status')) {
            $query->where('link_status', $request->status);
        }
        if ($request->filled('upn')) {
            $query->where('upn', 'like', "%{$request->upn}%");
        }

        // Sorting
        $sort      = $request->get('sort', 'display_name');
        $direction = $request->get('direction', 'asc');
        $allowed   = ['display_name', 'os', 'serial_number', 'upn', 'link_status', 'last_sync_at'];
        
        if (in_array($sort, $allowed)) {
            $query->orderBy($sort, $direction === 'desc' ? 'desc' : 'asc');
        } else {
            $query->orderBy('display_name', 'asc');
        }

        $azureDevices = $query->paginate(50)->withQueryString();
        $lastSync     = AzureDevice::max('last_sync_at');
        $statuses     = AzureDevice::LINK_STATUSES;

        return view('admin.itam.azure.index', compact('pending', 'azureDevices', 'lastSync', 'statuses'));
    }

    public function sync(Request $request)
    {
        try {
            $service = new AzureDeviceService();
            $result  = $service->syncDevices();
            ActivityLog::log("Azure device sync completed: {$result['synced']} synced, {$result['new']} new, {$result['auto_linked']} auto-linked");

            return back()->with('success', "Sync complete: {$result['synced']} devices synced, {$result['new']} new, {$result['auto_linked']} auto-linked.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    public function approve(Request $request, AzureDevice $azureDevice)
    {
        if (!$azureDevice->device_id) {
            return back()->with('error', 'No device linked to approve.');
        }

        $azureDevice->update(['link_status' => 'linked']);

        // Record in asset history
        $device = Device::find($azureDevice->device_id);
        if ($device) {
            AssetHistory::record($device, 'note_added', "Azure device link approved: {$azureDevice->display_name} ({$azureDevice->azure_device_id})");
        }

        ActivityLog::log("Approved Azure device link: {$azureDevice->display_name}");

        return back()->with('success', "Device '{$azureDevice->display_name}' linked successfully.");
    }

    public function reject(Request $request, AzureDevice $azureDevice)
    {
        $azureDevice->update(['link_status' => 'rejected', 'device_id' => null]);
        ActivityLog::log("Rejected Azure device link: {$azureDevice->display_name}");

        return back()->with('success', "Device link rejected.");
    }

    public function linkDevice(Request $request, AzureDevice $azureDevice)
    {
        $request->validate(['device_id' => 'required|exists:devices,id']);
        $azureDevice->update([
            'device_id'   => $request->device_id,
            'link_status' => 'pending',
        ]);
        $device = Device::find($request->device_id);
        if ($device) {
            AssetHistory::record($device, 'note_added', "Azure device linked (pending approval): {$azureDevice->display_name}");
        }
        ActivityLog::log("Linked Azure device {$azureDevice->display_name} to device ID {$request->device_id} (pending)");
        return response()->json(['ok' => true, 'message' => 'Linked — pending approval.']);
    }

    public function show(AzureDevice $azureDevice)
    {
        $azureDevice->load('device.branch');
        return response()->json([
            'id'           => $azureDevice->id,
            'display_name' => $azureDevice->display_name,
            'azure_id'     => $azureDevice->azure_device_id,
            'device_type'  => $azureDevice->device_type,
            'os'           => $azureDevice->os,
            'os_version'   => $azureDevice->os_version,
            'serial'       => $azureDevice->serial_number,
            'manufacturer' => $azureDevice->manufacturer,
            'model'        => $azureDevice->model,
            'upn'          => $azureDevice->upn,
            'enrolled_at'  => $azureDevice->enrolled_date?->format('d M Y'),
            'last_sync'    => $azureDevice->last_sync_at?->format('d M Y H:i'),
            'link_status'  => $azureDevice->link_status,
            'raw_data'     => $azureDevice->raw_data,
            'linked_device' => $azureDevice->device ? [
                'id'   => $azureDevice->device->id,
                'name' => $azureDevice->device->name,
                'type' => $azureDevice->device->type,
                'serial'     => $azureDevice->device->serial_number,
                'model'      => $azureDevice->device->deviceModel?->name,
                'branch'     => $azureDevice->device->branch?->name,
                'asset_code' => $azureDevice->device->asset_code,
                'url'        => route('admin.devices.show', $azureDevice->device),
            ] : null,
        ]);
    }

    public function createDevice(AzureDevice $azureDevice)
    {
        $raw = $azureDevice->raw_data ?? [];

        return redirect()->route('admin.devices.create', [
            'name'            => $azureDevice->display_name,
            'serial_number'   => $azureDevice->serial_number,
            'type'            => $this->guessDeviceType($azureDevice),
            'az_manufacturer' => $azureDevice->manufacturer,
            'az_model'        => $azureDevice->model,
            'az_upn'          => $azureDevice->upn,
            'azure_sync_id'   => $azureDevice->id,
        ]);
    }

    /**
     * Preview the asset code that would be generated.
     */
    public function previewImport(AzureDevice $azureDevice)
    {
        $codeService = new \App\Services\AssetCodeService();
        $type        = $this->guessDeviceType($azureDevice);
        $code        = $codeService->generate($type); // Use global sequence (SG-LAP-XXXX)
        $employee    = \App\Models\Employee::where('email', $azureDevice->upn)->first();

        return response()->json([
            'proposed_code' => $code,
            'proposed_user' => $employee ? [
                'id' => $employee->id,
                'name' => $employee->name,
                'email' => $employee->email,
            ] : null,
            'device_type' => $type,
        ]);
    }

    /**
     * Final approval: Create the asset and link the user.
     */
    public function importToItam(Request $request, AzureDevice $azureDevice)
    {
        if ($azureDevice->link_status === 'linked') {
            return back()->with('error', 'Device is already imported/linked.');
        }

        $request->validate([
            'type'       => 'required|string',
            'asset_code' => 'required|string|unique:devices,asset_code',
        ]);

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($azureDevice, $request) {
                // Correctly map from enrolled_date (vps data)
                $enrollDate = $azureDevice->enrolled_date ? \Carbon\Carbon::parse($azureDevice->enrolled_date) : now();

                // 1. Check if an asset with this Azure ID already exists (maybe created manually)
                $device = Device::where('source', 'azure')
                                ->where('source_id', $azureDevice->azure_device_id)
                                ->first();

                if ($device) {
                    // Just link it and we're done
                    $azureDevice->update([
                        'device_id'   => $device->id,
                        'link_status' => 'linked',
                    ]);
                } else {
                    // 2. Create or Find the Device Model
                    $deviceModel = \App\Models\DeviceModel::firstOrCreate(
                        [
                            'manufacturer' => $azureDevice->manufacturer ?? 'Unknown', 
                            'name'         => $azureDevice->model ?? 'Common Model'
                        ],
                        [
                            'device_type'  => $request->type
                        ]
                    );

                    // 3. Create the Device
                    $device = Device::create([
                        'type'                => $request->type,
                        'name'                => $azureDevice->display_name,
                        'manufacturer'        => $azureDevice->manufacturer,
                        'model'               => $azureDevice->model,
                        'device_model_id'     => $deviceModel->id,
                        'serial_number'       => $azureDevice->serial_number,
                        'asset_code'          => $request->asset_code,
                        'status'              => 'active',
                        'source'              => 'azure',
                        'source_id'           => $azureDevice->azure_device_id,
                        'branch_id'           => $this->detectBranchId($azureDevice),
                        'purchase_date'       => $enrollDate,
                        'warranty_expiry'     => (clone $enrollDate)->addYear(),
                        'depreciation_years'  => 3,
                        'depreciation_method' => 'straight_line',
                        'notes'               => "Imported from Azure/Intune sync on " . now()->toDateTimeString(),
                    ]);

                    // 4. Link AzureDevice
                    $azureDevice->update([
                        'device_id'   => $device->id,
                        'link_status' => 'linked',
                    ]);
                }

                // 5. Assign to Employee if UPN matches
                $employee = \App\Models\Employee::where('email', $azureDevice->upn)->first();
                if ($employee) {
                    \App\Models\EmployeeAsset::updateOrCreate(
                        ['employee_id' => $employee->id, 'asset_id' => $device->id],
                        [
                            'assigned_date' => now(),
                            'condition'     => 'used',
                            'notes'         => 'Assigned during Azure import.',
                        ]
                    );
                    
                    $device->update(['status' => 'assigned']);
                }

                AssetHistory::record($device, 'assigned', "Imported from Azure Sync. Assigned to user: " . ($employee->name ?? 'None'));
                if (class_exists('App\Models\ActivityLog')) {
                    \App\Models\ActivityLog::log("Imported Azure device {$azureDevice->display_name} as asset {$device->asset_code}");
                }
            });

            return back()->with('success', "Device successfully imported/linked.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    /**
     * Batch import multiple devices at once.
     */
    public function batchImport(Request $request)
    {
        $ids = $request->input('ids');
        if (empty($ids) || !is_array($ids)) {
            return back()->with('error', 'No devices selected for batch import.');
        }

        $successCount = 0;
        $errorCount   = 0;
        $codeService  = new \App\Services\AssetCodeService();

        foreach ($ids as $id) {
            try {
                $azureDevice = AzureDevice::find($id);
                if (!$azureDevice || $azureDevice->link_status === 'linked') continue;

                \Illuminate\Support\Facades\DB::transaction(function () use ($azureDevice, $codeService, &$successCount) {
                    $type = $this->guessDeviceType($azureDevice);
                    
                    // Enrollment date is treated as purchase date
                    $enrollDate = $azureDevice->enrolled_date ? \Carbon\Carbon::parse($azureDevice->enrolled_date) : now();

                    // 1. Check if already exists in devices table
                    $device = Device::where('source', 'azure')
                                    ->where('source_id', $azureDevice->azure_device_id)
                                    ->first();

                    if ($device) {
                        $azureDevice->update([
                            'device_id'   => $device->id,
                            'link_status' => 'linked',
                        ]);
                    } else {
                        $code = $codeService->generate($type);

                        // 2. Create or Find the Device Model
                        $deviceModel = \App\Models\DeviceModel::firstOrCreate(
                            [
                                'manufacturer' => $azureDevice->manufacturer ?? 'Unknown', 
                                'name'         => $azureDevice->model ?? 'Common Model'
                            ],
                            [
                                'device_type'  => $type
                            ]
                        );

                        // 3. Create the Device
                        $device = Device::create([
                            'type'                => $type,
                            'name'                => $azureDevice->display_name,
                            'manufacturer'        => $azureDevice->manufacturer,
                            'model'               => $azureDevice->model,
                            'device_model_id'     => $deviceModel->id,
                            'serial_number'       => $azureDevice->serial_number,
                            'asset_code'          => $code,
                            'status'              => 'active',
                            'source'              => 'azure',
                            'source_id'           => $azureDevice->azure_device_id,
                            'branch_id'           => $this->detectBranchId($azureDevice),
                            'purchase_date'       => $enrollDate,
                            'warranty_expiry'     => (clone $enrollDate)->addYear(),
                            'depreciation_years'  => 3,
                            'depreciation_method' => 'straight_line',
                            'notes'               => "Batch imported from Azure/Intune sync on " . now()->toDateTimeString(),
                        ]);

                        // 4. Link AzureDevice
                        $azureDevice->update([
                            'device_id'   => $device->id,
                            'link_status' => 'linked',
                        ]);
                    }

                    // 5. Assign to Employee
                    $employee = \App\Models\Employee::where('email', $azureDevice->upn)->first();
                    if ($employee) {
                        \App\Models\EmployeeAsset::updateOrCreate(
                            ['employee_id' => $employee->id, 'asset_id' => $device->id],
                            [
                                'assigned_date' => now(),
                                'condition'     => 'used',
                                'notes'         => 'Assigned during batch import.',
                            ]
                        );
                        $device->update(['status' => 'assigned']);
                    }

                    AssetHistory::record($device, 'assigned', "Imported from Azure Sync. Assigned to user: " . ($employee->name ?? 'None'));
                    if (class_exists('App\Models\ActivityLog')) {
                        \App\Models\ActivityLog::log("Imported Azure device {$azureDevice->display_name} as asset {$device->asset_code}");
                    }
                    $successCount++;
                });
            } catch (\Throwable $e) {
                \Log::error("Batch import failed for Azure Device ID {$id}: " . $e->getMessage());
                $errorCount++;
            }
        }

        ActivityLog::log("Performed batch import of {$successCount} Azure devices to itam.");

        $msg = "Successfully imported {$successCount} devices.";
        if ($errorCount > 0) $msg .= " Failed to import {$errorCount} devices. Check logs for details.";

        return back()->with($errorCount > 0 ? 'warning' : 'success', $msg);
    }

    private function guessDeviceType(AzureDevice $az): string
    {
        return 'laptop';
    }

    // --- Branch Mapping Management ---

    public function mappings()
    {
        $mappings = AzureBranchMapping::with('branch')->orderBy('keyword')->get();
        $branches = Branch::orderBy('name')->get();
        return view('admin.itam.azure.mappings', compact('mappings', 'branches'));
    }

    public function storeMapping(Request $request)
    {
        $request->validate([
            'keyword'   => 'required|string|max:100',
            'branch_id' => 'required|exists:branches,id',
        ]);

        AzureBranchMapping::updateOrCreate(
            ['keyword' => $request->keyword],
            ['branch_id' => $request->branch_id]
        );

        return back()->with('success', 'Mapping saved.');
    }

    public function deleteMapping(AzureBranchMapping $mapping)
    {
        $mapping->delete();
        return back()->with('success', 'Mapping removed.');
    }

    private function detectBranchId(AzureDevice $az): ?int
    {
        // 1. Try mapping keywords against office/location from Azure
        // AzureDevice might have these in raw_data
        $office   = $az->raw_data['officeLocation'] ?? null;
        $location = $az->raw_data['location'] ?? null; // custom field or metadata
        
        $searchStrings = array_filter([$office, $location, $az->display_name]);

        foreach ($searchStrings as $str) {
            if (!$str) continue;
            
            $mappings = AzureBranchMapping::all();
            foreach ($mappings as $m) {
                if (stripos($str, $m->keyword) !== false) {
                    return $m->branch_id;
                }
            }
        }

        return null;
    }
}
