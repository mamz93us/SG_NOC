<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IdentityLicense;
use App\Models\LicenseMonitor;
use Illuminate\Http\Request;

class LicenseMonitorController extends Controller
{
    public function index()
    {
        $this->authorize('manage-license-monitors');

        $monitors = LicenseMonitor::with('identityLicense')
            ->orderBy('display_name')
            ->get();

        $licenses = IdentityLicense::orderBy('display_name')->get();

        return view('admin.license-monitors.index', compact('monitors', 'licenses'));
    }

    public function store(Request $request)
    {
        $this->authorize('manage-license-monitors');

        $validated = $request->validate([
            'sku_id'             => 'required|string|max:36',
            'display_name'       => 'nullable|string|max:100',
            'critical_threshold' => 'required|integer|min:0',
        ]);

        // Auto-fill display_name from identity_licenses if not provided
        if (empty($validated['display_name'])) {
            $license = IdentityLicense::where('sku_id', $validated['sku_id'])->first();
            $validated['display_name'] = $license?->display_name ?? $validated['sku_id'];
        }

        LicenseMonitor::create([
            'sku_id'             => $validated['sku_id'],
            'display_name'       => $validated['display_name'],
            'critical_threshold' => $validated['critical_threshold'],
            'is_active'          => 1,
        ]);

        return back()->with('success', "License monitor for \"{$validated['display_name']}\" created.");
    }

    public function update(Request $request, LicenseMonitor $licenseMonitor)
    {
        $this->authorize('manage-license-monitors');

        $validated = $request->validate([
            'display_name'       => 'required|string|max:100',
            'critical_threshold' => 'required|integer|min:0',
        ]);

        $licenseMonitor->update($validated);

        return back()->with('success', "License monitor updated.");
    }

    public function toggleActive(LicenseMonitor $licenseMonitor)
    {
        $this->authorize('manage-license-monitors');

        $licenseMonitor->update(['is_active' => ! $licenseMonitor->is_active]);

        $state = $licenseMonitor->is_active ? 'enabled' : 'disabled';
        return back()->with('success', "Monitor {$state}.");
    }

    public function destroy(LicenseMonitor $licenseMonitor)
    {
        $this->authorize('manage-license-monitors');
        $name = $licenseMonitor->display_name;
        $licenseMonitor->delete();
        return back()->with('success', "License monitor \"{$name}\" deleted.");
    }
}
