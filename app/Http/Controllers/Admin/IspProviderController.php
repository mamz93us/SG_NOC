<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\IspProvider;
use App\Models\IspProviderPackage;
use Illuminate\Http\Request;

class IspProviderController extends Controller
{
    public function index()
    {
        $providers = IspProvider::with('packages')->withCount('connections')->orderBy('name')->get();

        return view('admin.network.isp-providers.index', compact('providers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:isp_providers,name',
            'default_currency' => 'nullable|in:'.implode(',', IspProvider::CURRENCIES),
            'notes' => 'nullable|string',
        ]);

        $provider = IspProvider::create($data);
        ActivityLog::log("Created ISP provider: {$provider->name}");

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['id' => $provider->id, 'name' => $provider->name], 201);
        }

        return back()->with('success', "Provider '{$provider->name}' created.");
    }

    public function update(Request $request, IspProvider $ispProvider)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:isp_providers,name,'.$ispProvider->id,
            'default_currency' => 'nullable|in:'.implode(',', IspProvider::CURRENCIES),
            'notes' => 'nullable|string',
        ]);

        $ispProvider->update($data);
        ActivityLog::log("Updated ISP provider: {$ispProvider->name}");

        return back()->with('success', "Provider '{$ispProvider->name}' updated.");
    }

    public function destroy(IspProvider $ispProvider)
    {
        if ($ispProvider->connections()->exists()) {
            return back()->with('error', "Cannot delete '{$ispProvider->name}' — it has ISP connections linked to it.");
        }
        $name = $ispProvider->name;
        $ispProvider->delete();
        ActivityLog::log("Deleted ISP provider: {$name}");

        return back()->with('success', "Provider '{$name}' deleted.");
    }

    // ─── Packages (nested under provider) ───────────────────────────

    public function storePackage(Request $request, IspProvider $ispProvider)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'speed_down' => 'nullable|integer|min:0',
            'speed_up' => 'nullable|integer|min:0',
            'monthly_cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|in:'.implode(',', IspProviderPackage::CURRENCIES),
            'notes' => 'nullable|string',
        ]);

        if (empty($data['currency']) && $ispProvider->default_currency) {
            $data['currency'] = $ispProvider->default_currency;
        }

        $package = $ispProvider->packages()->create($data);
        ActivityLog::log("Added package '{$package->name}' to provider '{$ispProvider->name}'.");

        return back()->with('success', "Package '{$package->name}' added.");
    }

    public function updatePackage(Request $request, IspProvider $ispProvider, IspProviderPackage $package)
    {
        abort_unless($package->isp_provider_id === $ispProvider->id, 404);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'speed_down' => 'nullable|integer|min:0',
            'speed_up' => 'nullable|integer|min:0',
            'monthly_cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|in:'.implode(',', IspProviderPackage::CURRENCIES),
            'notes' => 'nullable|string',
        ]);

        $package->update($data);
        ActivityLog::log("Updated package '{$package->name}' under '{$ispProvider->name}'.");

        return back()->with('success', "Package '{$package->name}' updated.");
    }

    public function destroyPackage(IspProvider $ispProvider, IspProviderPackage $package)
    {
        abort_unless($package->isp_provider_id === $ispProvider->id, 404);

        if ($package->connections()->exists()) {
            return back()->with('error', "Cannot delete package '{$package->name}' — it is in use by ISP connections.");
        }

        $name = $package->name;
        $package->delete();
        ActivityLog::log("Deleted package '{$name}' from '{$ispProvider->name}'.");

        return back()->with('success', "Package '{$name}' deleted.");
    }
}
