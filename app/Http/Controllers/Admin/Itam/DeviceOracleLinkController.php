<?php

namespace App\Http\Controllers\Admin\Itam;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Itam\OracleAsset;
use App\Services\Itam\Oracle\OracleAssetDevices;
use App\Services\Itam\Oracle\OracleLinkOptions;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * "Link Oracle asset" on an asset with no Oracle number — typically an Intune
 * laptop already assigned to someone that the import could not match. The
 * dialog loads its choices from options() when it opens, so the employee page
 * does not carry the whole register. See OracleAssetDevices::linkOracle.
 */
class DeviceOracleLinkController extends Controller
{
    public function options(Device $device, OracleLinkOptions $options): JsonResponse
    {
        return response()->json(['groups' => $options->forDevice($device)]);
    }

    public function store(Request $request, Device $device, OracleAssetDevices $devices): RedirectResponse
    {
        $data = $request->validate([
            'oracle_asset_id' => ['required', 'integer', 'exists:oracle_assets,id'],
        ], [
            'oracle_asset_id.required' => 'Choose the Oracle asset.',
        ]);

        $unit = OracleAsset::with('device')->findOrFail($data['oracle_asset_id']);
        $merged = $unit->device?->source === 'oracle' ? $unit->device : null;
        $mergedCode = $merged ? ($merged->asset_code ?: $merged->name) : null;

        try {
            DB::transaction(fn () => $devices->linkOracle($device, $unit, Auth::id()));
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        ActivityLog::create([
            'model_type' => Device::class,
            'model_id' => $device->id,
            'model_label' => $device->asset_code ?: $device->name,
            'action' => 'oracle_asset_linked',
            'changes' => [
                'oracle_asset_id' => $unit->id,
                'asset_number' => $unit->asset_number,
                'oracle_holder' => trim("#{$unit->emp_no} {$unit->emp_name}"),
                'merged_from' => $mergedCode,
            ],
            'user_id' => Auth::id(),
        ]);

        return back()->with('success', ($device->asset_code ?: $device->name)." is now Oracle asset {$unit->asset_number}"
            .($mergedCode ? "; {$mergedCode}, the asset the import had created for it, was deleted." : '.'));
    }
}
