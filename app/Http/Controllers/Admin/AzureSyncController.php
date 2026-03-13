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
            'az_manufacturer' => $raw['manufacturer'] ?? null,
            'az_model'        => $raw['model'] ?? null,
            'az_upn'          => $azureDevice->upn,
            'azure_sync_id'   => $azureDevice->id,
        ]);
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
