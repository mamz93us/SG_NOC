<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AssetType;
use App\Models\Device;
use App\Models\Setting;
use App\Services\AssetCodeService;
use Illuminate\Http\Request;

class AssetTypeController extends Controller
{
    public function index()
    {
        $this->authorize('manage-settings');

        $types    = AssetType::orderBy('sort_order')->get();
        $settings = Setting::get();

        // Count devices per type for the badge
        $deviceCounts = Device::selectRaw('type, COUNT(*) as cnt')
            ->groupBy('type')
            ->pluck('cnt', 'type');

        return view('admin.settings.asset-types', compact('types', 'settings', 'deviceCounts'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-settings');

        $request->validate([
            'slug'              => 'required|string|max:30|regex:/^[a-z0-9_]+$/|unique:asset_types,slug',
            'label'             => 'required|string|max:60',
            'icon'              => 'required|string|max:60',
            'badge_class'       => 'required|string|max:60',
            'category_code'     => 'required|string|max:5|regex:/^[A-Z0-9]+$/i',
            'is_user_equipment' => 'boolean',
            'group'             => 'required|in:infrastructure,user_equipment,other',
            'sort_order'        => 'required|integer|min:0|max:9999',
        ]);

        $type = AssetType::create([
            'slug'              => strtolower($request->slug),
            'label'             => $request->label,
            'icon'              => $request->icon,
            'badge_class'       => $request->badge_class,
            'category_code'     => strtoupper($request->category_code),
            'is_user_equipment' => (bool) $request->is_user_equipment,
            'group'             => $request->group,
            'sort_order'        => (int) $request->sort_order,
        ]);

        AssetType::clearCache();
        ActivityLog::log("Asset type created: {$type->label} ({$type->slug})");

        return back()->with('success', "Asset type \"{$type->label}\" created.");
    }

    public function update(Request $request, AssetType $assetType)
    {
        $this->authorize('manage-settings');

        $request->validate([
            'label'             => 'required|string|max:60',
            'icon'              => 'required|string|max:60',
            'badge_class'       => 'required|string|max:60',
            'category_code'     => 'required|string|max:5|regex:/^[A-Z0-9]+$/i',
            'is_user_equipment' => 'boolean',
            'group'             => 'required|in:infrastructure,user_equipment,other',
            'sort_order'        => 'required|integer|min:0|max:9999',
        ]);

        $assetType->update([
            'label'             => $request->label,
            'icon'              => $request->icon,
            'badge_class'       => $request->badge_class,
            'category_code'     => strtoupper($request->category_code),
            'is_user_equipment' => (bool) $request->is_user_equipment,
            'group'             => $request->group,
            'sort_order'        => (int) $request->sort_order,
        ]);

        AssetType::clearCache();
        ActivityLog::log("Asset type updated: {$assetType->label} ({$assetType->slug})");

        return back()->with('success', "Asset type \"{$assetType->label}\" updated.");
    }

    public function destroy(AssetType $assetType)
    {
        $this->authorize('manage-settings');

        // Don't allow deleting types that have devices
        $count = Device::where('type', $assetType->slug)->count();
        if ($count > 0) {
            return back()->with('error', "Cannot delete \"{$assetType->label}\" — {$count} device(s) are using this type. Reassign them first.");
        }

        $label = $assetType->label;
        $assetType->delete();

        AssetType::clearCache();
        ActivityLog::log("Asset type deleted: {$label}");

        return back()->with('success', "Asset type \"{$label}\" deleted.");
    }

    /**
     * Update ITAM asset code settings (prefix, padding, URL).
     */
    public function updateSettings(Request $request)
    {
        $this->authorize('manage-settings');

        $request->validate([
            'itam_asset_prefix' => 'required|string|max:10|regex:/^[A-Z0-9]+$/i',
            'itam_code_padding' => 'required|integer|min:1|max:10',
            'itam_company_url'  => 'nullable|url|max:255',
        ]);

        $settings = Setting::get();
        $settings->itam_asset_prefix = strtoupper($request->itam_asset_prefix);
        $settings->itam_code_padding = (int) $request->itam_code_padding;
        $settings->itam_company_url  = $request->itam_company_url;
        $settings->save();

        ActivityLog::log("ITAM settings updated: prefix={$settings->itam_asset_prefix}");

        return back()->with('success', 'Asset code settings saved.');
    }
}
