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

    public function createDevice(AzureDevice $azureDevice)
    {
        // Redirect to device create form with pre-filled params from Azure device data
        return redirect()->route('admin.devices.create', [
            'name'          => $azureDevice->display_name,
            'serial_number' => $azureDevice->serial_number,
            'type'          => $this->guessDeviceType($azureDevice),
            'azure_sync_id' => $azureDevice->id,
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
