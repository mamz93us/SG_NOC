<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceMac;
use App\Models\RadiusMacOverride;
use Illuminate\Http\Request;

/**
 * Per-MAC RADIUS allow/deny + VLAN override.
 * Called from the modal on /admin/itam/mac-address.
 *
 * If both fields would result in defaults (radius_enabled=true, no VLAN),
 * the override row is deleted to keep the table tidy.
 */
class RadiusMacOverrideController extends Controller
{
    public function upsert(Request $request, DeviceMac $deviceMac)
    {
        $data = $request->validate([
            'radius_enabled' => 'required|boolean',
            'vlan_override'  => 'nullable|integer|min:1|max:4094',
            'notes'          => 'nullable|string|max:255',
        ]);

        $isDefault = $data['radius_enabled'] === true
            && empty($data['vlan_override'])
            && empty($data['notes']);

        $existing = $deviceMac->radiusOverride;

        if ($isDefault) {
            $existing?->delete();

            return back()->with('success', "Override cleared for {$deviceMac->mac_address}.");
        }

        RadiusMacOverride::updateOrCreate(
            ['device_mac_id' => $deviceMac->id],
            [
                'radius_enabled' => $data['radius_enabled'],
                'vlan_override'  => $data['vlan_override'] ?? null,
                'notes'          => $data['notes'] ?? null,
                'created_by'     => auth()->id(),
            ]
        );

        return back()->with('success', "Override saved for {$deviceMac->mac_address}.");
    }
}
