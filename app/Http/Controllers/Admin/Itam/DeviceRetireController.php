<?php

namespace App\Http\Controllers\Admin\Itam;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Services\Itam\AssetReasons;
use App\Services\Itam\AssetRetirement;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Retires an asset from the device page, the employee page or the Oracle
 * register. See AssetRetirement for what that does and what it refuses.
 */
class DeviceRetireController extends Controller
{
    public function store(Request $request, Device $device, AssetRetirement $retirement): RedirectResponse
    {
        $data = $request->validate([
            'reason_code' => ['required', 'in:'.implode(',', array_keys(AssetReasons::RETIRE))],
            'reason' => ['nullable', 'string', 'max:1000'],
            'retired_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'reason_code.required' => 'Choose why the asset is retired.',
            'retired_on.before_or_equal' => 'An asset cannot be retired on a day that has not come yet.',
        ]);

        $reason = AssetReasons::compose(AssetReasons::retireLabel($data['reason_code']), $data['reason'] ?? null);

        try {
            $retirement->retire($device, $reason, CarbonImmutable::parse($data['retired_on']), $data['reason_code']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', ($device->asset_code ?: $device->name).' retired.'
            .($device->azureDevice ? ' It is still enrolled in Intune — remove it there too if it is gone.' : ''));
    }
}
