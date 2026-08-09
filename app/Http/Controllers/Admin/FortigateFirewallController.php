<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncFortiGateDhcpJob;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\DhcpLease;
use App\Models\FortigateFirewall;
use App\Models\MonitoredHost;
use App\Services\FortiGate\FortiGateApiService;
use Illuminate\Http\Request;

class FortigateFirewallController extends Controller
{
    public function index()
    {
        $firewalls = FortigateFirewall::with('branch')->orderBy('name')->get();

        // Live lease counts, keyed by firewall IP (leases store source_device = ip).
        $leaseCounts = DhcpLease::where('source', 'fortigate')
            ->selectRaw('source_device, COUNT(*) as total')
            ->groupBy('source_device')
            ->pluck('total', 'source_device');

        return view('admin.network.fortigate.index', compact('firewalls', 'leaseCounts'));
    }

    public function create()
    {
        return view('admin.network.fortigate.form', [
            'firewall' => null,
            'branches' => Branch::orderBy('name')->get(),
            'monitoredHosts' => MonitoredHost::where('discovered_type', 'fortigate')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(true));

        $validated['port'] = $validated['port'] ?? 443;
        $validated['vdom'] = $validated['vdom'] ?: 'root';
        $validated['sync_enabled'] = $request->boolean('sync_enabled', true);
        $validated['label_wifi_only'] = $request->boolean('label_wifi_only');

        $firewall = FortigateFirewall::create($validated);

        ActivityLog::log('created', $firewall, ['name' => $firewall->name, 'ip' => $firewall->ip]);

        return redirect()->route('admin.network.fortigate.show', $firewall)
            ->with('success', "FortiGate '{$firewall->name}' added. Run a sync to pull its DHCP leases.");
    }

    public function show(FortigateFirewall $fortigate)
    {
        $fortigate->load(['branch', 'monitoredHost']);

        $leases = DhcpLease::where('source', 'fortigate')
            ->where('source_device', $fortigate->ip)
            ->with('device')
            ->orderByDesc('last_seen')
            ->paginate(50);

        return view('admin.network.fortigate.show', [
            'firewall' => $fortigate,
            'leases' => $leases,
        ]);
    }

    public function edit(FortigateFirewall $fortigate)
    {
        return view('admin.network.fortigate.form', [
            'firewall' => $fortigate,
            'branches' => Branch::orderBy('name')->get(),
            'monitoredHosts' => MonitoredHost::where('discovered_type', 'fortigate')->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, FortigateFirewall $fortigate)
    {
        $validated = $request->validate($this->rules(false, $fortigate->id));

        $validated['port'] = $validated['port'] ?? 443;
        $validated['vdom'] = $validated['vdom'] ?: 'root';
        $validated['sync_enabled'] = $request->boolean('sync_enabled', true);
        $validated['label_wifi_only'] = $request->boolean('label_wifi_only');

        // Blank token means "keep the existing key".
        if (empty($validated['api_token'])) {
            unset($validated['api_token']);
        }

        $fortigate->update($validated);

        ActivityLog::log('updated', $fortigate, ['name' => $fortigate->name, 'ip' => $fortigate->ip]);

        return redirect()->route('admin.network.fortigate.show', $fortigate)
            ->with('success', "FortiGate '{$fortigate->name}' updated.");
    }

    public function destroy(FortigateFirewall $fortigate)
    {
        $name = $fortigate->name;
        ActivityLog::log('deleted', $fortigate);
        $fortigate->delete();

        return redirect()->route('admin.network.fortigate.index')
            ->with('success', "FortiGate '{$name}' deleted. Its DHCP leases were left in place.");
    }

    public function sync(FortigateFirewall $fortigate)
    {
        try {
            $count = (new SyncFortiGateDhcpJob($fortigate))->handle();

            return back()->with('success', "Synced {$count} DHCP leases from '{$fortigate->name}'.");
        } catch (\Throwable $e) {
            return back()->with('error', "Sync failed: {$e->getMessage()}");
        }
    }

    public function testConnection(FortigateFirewall $fortigate)
    {
        $result = (new FortiGateApiService($fortigate))->testConnection();

        return response()->json($result);
    }

    // ─── Helpers ──────────────────────────────────────────────────

    private function rules(bool $creating, ?int $ignoreId = null): array
    {
        return [
            'name' => 'required|string|max:255',
            'ip' => 'required|ip|unique:fortigate_firewalls,ip'.($ignoreId ? ",{$ignoreId}" : ''),
            'port' => 'nullable|integer|min:1|max:65535',
            'vdom' => 'nullable|string|max:64',
            'branch_id' => 'nullable|exists:branches,id',
            'monitored_host_id' => 'nullable|exists:monitored_hosts,id',
            'network_label' => 'nullable|string|max:255',
            'label_wifi_only' => 'nullable|boolean',
            'model' => 'nullable|string|max:255',
            'api_token' => ($creating ? 'required' : 'nullable').'|string|max:255',
            'sync_enabled' => 'nullable|boolean',
        ];
    }
}
