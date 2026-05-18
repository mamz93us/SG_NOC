<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IspConnection;
use App\Models\IspProvider;
use App\Models\IspProviderPackage;
use Illuminate\Http\Request;

class IspConnectionController extends Controller
{
    public function index(Request $request)
    {
        $query = IspConnection::with(['branch', 'routerDevice', 'ispProvider', 'ispProviderPackage'])
            ->orderBy('branch_id')
            ->orderBy('provider');

        if ($request->filled('branch')) {
            $query->where('branch_id', $request->branch);
        }
        if ($request->filled('provider')) {
            $query->where(function ($q) use ($request) {
                $q->whereHas('ispProvider', fn ($p) => $p->where('name', 'like', "%{$request->provider}%"))
                    ->orWhere('provider', 'like', "%{$request->provider}%");
            });
        }
        if ($request->filled('account_number')) {
            $query->where('account_number', 'like', "%{$request->account_number}%");
        }
        if ($request->filled('customer_type')) {
            $query->where('customer_type', $request->customer_type);
        }
        if ($request->filled('connection_type')) {
            $query->where('connection_type', $request->connection_type);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('provider', 'like', "%{$s}%")
                    ->orWhere('account_number', 'like', "%{$s}%")
                    ->orWhere('circuit_id', 'like', "%{$s}%")
                    ->orWhere('static_ip', 'like', "%{$s}%")
                    ->orWhere('gateway', 'like', "%{$s}%");
            });
        }

        $connections = $query->paginate(50)->withQueryString();
        $branches = Branch::orderBy('name')->get(['id', 'name']);

        return view('admin.network.isp.index', compact('connections', 'branches'));
    }

    public function create()
    {
        return view('admin.network.isp.form', $this->formData());
    }

    public function store(Request $request)
    {
        $data = $this->validateRequest($request);
        $data = $this->resolveProviderPackage($data);

        $isp = IspConnection::create($data);

        ActivityLog::log('Created ISP connection: '.$isp->provider.' for branch #'.$isp->branch_id);

        return redirect()->route('admin.network.isp.index')
            ->with('success', 'ISP connection created.');
    }

    public function edit(IspConnection $isp)
    {
        return view('admin.network.isp.form', array_merge($this->formData(), compact('isp')));
    }

    public function update(Request $request, IspConnection $isp)
    {
        $data = $this->validateRequest($request);
        $data = $this->resolveProviderPackage($data);

        $isp->update($data);

        ActivityLog::log('Updated ISP connection: '.$isp->provider.' (#'.$isp->id.')');

        return redirect()->route('admin.network.isp.index')
            ->with('success', 'ISP connection updated.');
    }

    public function destroy(IspConnection $isp)
    {
        $name = $isp->provider;
        $isp->delete();

        ActivityLog::log('Deleted ISP connection: '.$name);

        return redirect()->route('admin.network.isp.index')
            ->with('success', 'ISP connection deleted.');
    }

    // ─────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────

    private function formData(): array
    {
        return [
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'routers' => Device::whereIn('type', ['router', 'firewall'])->orderBy('name')->get(['id', 'name', 'type']),
            'providers' => IspProvider::with('packages')->orderBy('name')->get(),
        ];
    }

    private function validateRequest(Request $request): array
    {
        return $request->validate([
            'branch_id' => 'required|exists:branches,id',
            'isp_provider_id' => 'required|exists:isp_providers,id',
            'isp_provider_package_id' => 'nullable|exists:isp_provider_packages,id',
            'account_number' => 'nullable|string|max:64',
            'connection_type' => 'nullable|in:'.implode(',', \App\Models\IspConnection::CONNECTION_TYPES),
            'customer_type' => 'nullable|in:'.implode(',', \App\Models\IspConnection::CUSTOMER_TYPES),
            'payment_type' => 'nullable|in:'.implode(',', \App\Models\IspConnection::PAYMENT_TYPES),
            'billing_day' => 'nullable|integer|min:1|max:31',
            'circuit_id' => 'nullable|string|max:255',
            'speed_down' => 'nullable|integer|min:0',
            'speed_up' => 'nullable|integer|min:0',
            'static_ip' => 'nullable|string|max:45',
            'gateway' => 'nullable|string|max:45',
            'subnet' => 'nullable|string|max:45',
            'router_device_id' => 'nullable|exists:devices,id',
            'renewal_remind_days' => 'nullable|integer|min:1|max:90',
            'monthly_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);
    }

    /**
     * Copy provider name and package name into the denormalized string columns
     * so legacy code paths (and the index page, reports, notifications) keep
     * working. Also auto-fills speed_down/up and monthly_cost from the package
     * defaults if not explicitly set.
     */
    private function resolveProviderPackage(array $data): array
    {
        $provider = IspProvider::find($data['isp_provider_id'] ?? null);
        if ($provider) {
            $data['provider'] = $provider->name;
        }

        if (! empty($data['isp_provider_package_id'])) {
            $package = IspProviderPackage::find($data['isp_provider_package_id']);
            if ($package) {
                // Guard against cross-provider mismatch — drop the package if
                // it doesn't belong to the chosen provider.
                if ($provider && $package->isp_provider_id !== $provider->id) {
                    $data['isp_provider_package_id'] = null;
                } else {
                    $data['package'] = $package->name;
                    // Backfill speeds/cost from package defaults if user left blank
                    if (empty($data['speed_down']) && $package->speed_down) {
                        $data['speed_down'] = $package->speed_down;
                    }
                    if (empty($data['speed_up']) && $package->speed_up) {
                        $data['speed_up'] = $package->speed_up;
                    }
                    if (empty($data['monthly_cost']) && $package->monthly_cost) {
                        $data['monthly_cost'] = $package->monthly_cost;
                    }
                }
            }
        }

        return $data;
    }
}
