<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncSophosDataJob;
use App\Models\Branch;
use App\Models\MonitoredHost;
use App\Models\SophosFirewall;
use App\Services\Sophos\SophosApiService;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class SophosFirewallController extends Controller
{
    public function index()
    {
        $firewalls = SophosFirewall::with('branch')
            ->withCount(['interfaces', 'networkObjects', 'vpnTunnels', 'firewallRules'])
            ->orderBy('name')
            ->get();

        return view('admin.network.sophos.index', compact('firewalls'));
    }

    public function create()
    {
        $branches       = Branch::orderBy('name')->get();
        $monitoredHosts = MonitoredHost::where('discovered_type', 'sophos')->orderBy('name')->get();

        return view('admin.network.sophos.form', [
            'firewall'       => null,
            'branches'       => $branches,
            'monitoredHosts' => $monitoredHosts,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'ip'                => 'required|ip',
            'port'              => 'nullable|integer|min:1|max:65535',
            'branch_id'         => 'nullable|exists:branches,id',
            'monitored_host_id' => 'nullable|exists:monitored_hosts,id',
            'api_username'      => 'required|string|max:255',
            'api_password'      => 'required|string|max:255',
            'sync_enabled'      => 'nullable|boolean',
        ]);

        $validated['port']         = $validated['port'] ?? 4444;
        $validated['sync_enabled'] = $request->boolean('sync_enabled', true);

        $firewall = SophosFirewall::create($validated);

        ActivityLog::log('created', $firewall, $validated);

        return redirect()->route('admin.network.sophos.show', $firewall)
            ->with('success', "Sophos firewall '{$firewall->name}' created.");
    }

    public function show(SophosFirewall $firewall)
    {
        $firewall->load([
            'branch',
            'monitoredHost',
            'interfaces',
            'networkObjects',
            'vpnTunnels',
            'firewallRules',
        ]);

        return view('admin.network.sophos.show', compact('firewall'));
    }

    public function edit(SophosFirewall $firewall)
    {
        $branches       = Branch::orderBy('name')->get();
        $monitoredHosts = MonitoredHost::where('discovered_type', 'sophos')->orderBy('name')->get();

        return view('admin.network.sophos.form', compact('firewall', 'branches', 'monitoredHosts'));
    }

    public function update(Request $request, SophosFirewall $firewall)
    {
        $validated = $request->validate([
            'name'              => 'required|string|max:255',
            'ip'                => 'required|ip',
            'port'              => 'nullable|integer|min:1|max:65535',
            'branch_id'         => 'nullable|exists:branches,id',
            'monitored_host_id' => 'nullable|exists:monitored_hosts,id',
            'api_username'      => 'nullable|string|max:255',
            'api_password'      => 'nullable|string|max:255',
            'sync_enabled'      => 'nullable|boolean',
        ]);

        $validated['port']         = $validated['port'] ?? 4444;
        $validated['sync_enabled'] = $request->boolean('sync_enabled', true);

        // Don't overwrite credentials with empty values
        if (empty($validated['api_username'])) unset($validated['api_username']);
        if (empty($validated['api_password'])) unset($validated['api_password']);

        $firewall->update($validated);

        ActivityLog::log('updated', $firewall, $validated);

        return redirect()->route('admin.network.sophos.show', $firewall)
            ->with('success', "Sophos firewall '{$firewall->name}' updated.");
    }

    public function destroy(SophosFirewall $firewall)
    {
        $name = $firewall->name;
        ActivityLog::log('deleted', $firewall);
        $firewall->delete();

        return redirect()->route('admin.network.sophos.index')
            ->with('success', "Sophos firewall '{$name}' deleted.");
    }

    public function sync(SophosFirewall $firewall)
    {
        try {
            (new SyncSophosDataJob($firewall))->handle();
            return back()->with('success', "Sync completed for '{$firewall->name}'.");
        } catch (\Throwable $e) {
            return back()->with('error', "Sync failed: {$e->getMessage()}");
        }
    }

    public function testConnection(SophosFirewall $firewall)
    {
        $api     = new SophosApiService($firewall);
        $success = $api->testConnection();

        return response()->json([
            'success' => $success,
            'message' => $success ? 'Connection successful' : 'Connection failed',
        ]);
    }
}
