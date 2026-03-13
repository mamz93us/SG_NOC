<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Models\AssetHistory;
use App\Models\ActivityLog;
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

        $query = AzureDevice::with('device')->orderBy('display_name');

        if ($request->filled('status')) {
            $query->where('link_status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('display_name', 'like', "%{$s}%")
                  ->orWhere('serial_number', 'like', "%{$s}%")
                  ->orWhere('upn', 'like', "%{$s}%");
            });
        }

        $azureDevices = $query->paginate(30)->withQueryString();
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

                // 1. Create or Find the Device Model (so it shows in dropdowns/inventory)
                $deviceModel = \App\Models\DeviceModel::firstOrCreate(
                    [
                        'manufacturer' => $azureDevice->manufacturer ?? 'Unknown', 
                        'name'         => $azureDevice->model ?? 'Common Model'
                    ],
                    [
                        'device_type'  => $request->type
                    ]
                );

                // 2. Create the Device
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
                    'purchase_date'       => $enrollDate,
                    'warranty_expiry'     => (clone $enrollDate)->addYear(), // 1 Year Default
                    'depreciation_years'  => 3,                               // 3 Years Default
                    'depreciation_method' => 'straight_line',
                    'notes'               => "Imported from Azure/Intune sync on " . now()->toDateTimeString(),
                ]);

                // 3. Link AzureDevice to this record
                $azureDevice->update([
                    'device_id'   => $device->id,
                    'link_status' => 'linked',
                ]);

                // 4. Assign to Employee if UPN matches
                $employee = \App\Models\Employee::where('email', $azureDevice->upn)->first();
                if ($employee) {
                    \App\Models\EmployeeAsset::create([
                        'employee_id'   => $employee->id,
                        'asset_id'      => $device->id,
                        'assigned_date' => now(),
                        'condition'     => 'used',
                        'notes'         => 'Auto-assigned during Azure import.',
                    ]);
                    
                    $device->update(['status' => 'assigned']);
                }

                AssetHistory::record($device, 'assigned', "Imported from Azure Sync. Assigned to user: " . ($employee->name ?? 'None'));
                ActivityLog::log("Imported Azure device {$azureDevice->display_name} as asset {$device->asset_code}");
            });

            return back()->with('success', "Device successfully imported as asset: {$request->asset_code}");
        } catch (\Throwable $e) {
            return back()->with('error', 'Import failed: ' . $e->getMessage());
        }
    }

    private function guessDeviceType(AzureDevice $azureDevice): string
    {
        $os = strtolower($azureDevice->os ?? '');
        if (str_contains($os, 'windows')) return 'laptop';
        if (str_contains($os, 'mac'))     return 'laptop';
        if (str_contains($os, 'android')) return 'tablet';
        if (str_contains($os, 'ios'))     return 'tablet';
        if (str_contains($os, 'linux'))   return 'server';
        return 'other';
    }
}
