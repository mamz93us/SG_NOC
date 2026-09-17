<?php

namespace App\Http\Controllers\Admin\Itam;

use App\Http\Controllers\Controller;
use App\Models\AzureDevice;
use App\Models\Device;
use App\Services\Itam\Oracle\OracleAssetDevices;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A person says which Intune device an asset is. For an asset the Oracle
 * import created, an Intune device that already has an asset takes the Oracle
 * unit over and the import's asset is deleted (OracleAssetDevices::linkIntune).
 */
class DeviceIntuneLinkController extends Controller
{
    public function store(Request $request, Device $device, OracleAssetDevices $devices): RedirectResponse
    {
        $data = $request->validate([
            'azure_device_id' => ['required', 'integer', 'exists:azure_devices,id'],
        ], [
            'azure_device_id.required' => 'Choose the Intune device.',
        ]);

        $intune = AzureDevice::findOrFail($data['azure_device_id']);

        try {
            $result = DB::transaction(fn () => $devices->linkIntune($device, $intune, Auth::id()));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = (int) $result->id === (int) $device->id
            ? ($device->asset_code ?: $device->name)." is now linked to the Intune device {$intune->display_name}."
            : "Oracle asset {$result->oracle_asset_number} is now on ".($result->asset_code ?: $result->name)
                ." (Intune {$intune->display_name}); the asset the import had created for it was deleted.";

        // The asset the request came from may be gone, so never go back to its page.
        if ((int) $result->id !== (int) $device->id && str_contains((string) url()->previous(), '/devices/'.$device->id)) {
            return redirect()->route('admin.devices.show', $result)->with('success', $message);
        }

        return back()->with('success', $message);
    }
}
