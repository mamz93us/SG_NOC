<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMerakiData;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\DhcpLease;
use App\Models\IpReservation;
use App\Models\NetworkClient;
use App\Models\NetworkEvent;
use App\Models\NetworkFloor;
use App\Models\NetworkOffice;
use App\Models\NetworkRack;
use App\Models\Device;
use App\Models\NetworkSwitch;
use App\Models\MonitoredHost;
use App\Models\NetworkSyncLog;
use App\Models\Setting;
use App\Models\SwitchQosStat;
use App\Models\SwitchRunningConfig;
use App\Services\Network\MerakiService;
use App\Services\Network\SnmpConfigExtractor;
use App\Services\Network\SwitchReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NetworkController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // Overview
    // ─────────────────────────────────────────────────────────────

    public function overview()
    {
        $totalSwitches    = NetworkSwitch::count();
        $onlineSwitches   = NetworkSwitch::where('status', 'online')->count();
        $offlineSwitches  = NetworkSwitch::where('status', 'offline')->count();
        $alertingSwitches = NetworkSwitch::where('status', 'alerting')->count();
        $totalClients     = NetworkClient::count();
        $onlineClients    = NetworkClient::where('status', 'Online')->count();
        $totalPorts       = \App\Models\NetworkPort::count();
        $connectedPorts   = \App\Models\NetworkPort::where('status', 'Connected')->count();

        $switches = NetworkSwitch::orderByRaw("
            CASE status
                WHEN 'online'   THEN 1
                WHEN 'alerting' THEN 2
                WHEN 'offline'  THEN 3
                ELSE 4
            END
        ")->orderBy('name')->get();

        $lastSync    = NetworkSwitch::max('updated_at');
        $lastSyncLog = NetworkSyncLog::latest()->first();

        $settings = Setting::get();

        return view('admin.network.overview', compact(
            'totalSwitches', 'onlineSwitches', 'offlineSwitches', 'alertingSwitches',
            'totalClients', 'onlineClients', 'totalPorts', 'connectedPorts',
            'switches', 'lastSync', 'lastSyncLog', 'settings'
        ));
    }

    // ─────────────────────────────────────────────────────────────
    // Switch list
    // ─────────────────────────────────────────────────────────────

    public function switches(Request $request, SwitchReconciler $reconciler)
    {
        // Link/auto-create across sources. Idempotent + lightweight after the
        // first run (most rows already FK'd). Opt out with ?skip_reconcile=1
        // for debugging if ever needed.
        if (!$request->boolean('skip_reconcile')) {
            try {
                $reconciler->reconcileAll();
            } catch (\Throwable $e) {
                \Log::warning('SwitchReconciler failed: ' . $e->getMessage());
            }
        }

        // Canonical query: every switch-class Device, eager-loading the
        // satellite rows from each source system.
        $query = Device::query()
            ->whereIn('type', ['switch', 'router', 'firewall'])
            ->with(['branch', 'floor', 'networkSwitch.branch', 'monitoredHost']);

        if ($request->filled('status')) {
            // Map status against the Meraki-sourced NetworkSwitch when present.
            $status = $request->status;
            $query->whereHas('networkSwitch', fn ($q) => $q->where('status', $status));
        }

        if ($request->filled('network')) {
            $networkId = $request->network;
            $query->whereHas('networkSwitch', fn ($q) => $q->where('network_id', $networkId));
        }

        if ($request->filled('source')) {
            $source = $request->source;
            match ($source) {
                'meraki'  => $query->whereHas('networkSwitch'),
                'snmp'    => $query->whereHas('monitoredHost', fn ($q) => $q->where('snmp_enabled', true)),
                'qos'     => $query->whereHas('qosStats'),
                'manual'  => $query->where('source', 'manual'),
                default   => null,
            };
        }

        $devices = $query->orderBy('name')->get();

        // Aggregate QoS presence for devices in one shot (avoid N+1 on hasMany).
        $qosDeviceIds = SwitchQosStat::whereIn('device_id', $devices->pluck('id'))
            ->pluck('device_id')
            ->unique()
            ->flip();

        // Shape a unified row for the view. Each row answers "is this switch
        // present in {Meraki, SNMP, QoS, Assets}?" and carries the join data
        // needed to render it.
        $rows = $devices->map(function (Device $d) use ($qosDeviceIds) {
            $meraki = $d->networkSwitch;
            $snmp   = $d->monitoredHost;

            // Roll up a single status the table can sort/badge on.
            $status = $meraki?->status
                ?? ($snmp?->status === 'up' ? 'online'
                    : ($snmp?->status === 'down' ? 'offline' : 'unknown'));

            return (object) [
                'device'       => $d,
                'id'           => $d->id,
                'name'         => $meraki?->name ?: $d->name,
                'model'        => $d->model ?: $meraki?->model,
                'serial'       => $d->serial_number ?: $meraki?->serial,
                'ip'           => $d->ip_address ?: $meraki?->lan_ip,
                'mac'          => $d->mac_address ?: $meraki?->mac,
                'branch'       => $d->branch,
                'floor'        => $d->floor,
                'rack'         => $meraki?->rack,
                'status'       => $status,
                'network_name' => $meraki?->network_name,
                'network_id'   => $meraki?->network_id,
                'port_count'   => $meraki?->port_count ?? 0,
                'clients'      => $meraki?->clients_count ?? 0,
                'last_seen'    => $meraki?->last_reported_at ?? $snmp?->last_checked_at,
                'in_meraki'    => (bool) $meraki,
                'in_snmp'      => (bool) $snmp,
                'snmp_ready'   => (bool) ($snmp?->snmp_enabled),
                'in_qos'       => $qosDeviceIds->has($d->id),
                'in_assets'    => true, // we're looking at devices, it's always true here
                'meraki_ref'   => $meraki,
                'snmp_ref'     => $snmp,
            ];
        })
        // Stable sort: online → alerting → offline → unknown, then name.
        ->sortBy(function ($r) {
            $order = ['online' => 1, 'alerting' => 2, 'offline' => 3];
            return [($order[$r->status] ?? 4), strtolower($r->name ?? '')];
        })->values();

        $networks = NetworkSwitch::select('network_id', 'network_name')
            ->distinct()->orderBy('network_name')->get();
        $lastSync = NetworkSwitch::max('updated_at');
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $floors   = NetworkFloor::with('branch')->orderBy('sort_order')->orderBy('name')->get();
        $racks    = NetworkRack::with('floor.branch')->orderBy('sort_order')->orderBy('name')->get();

        // Source totals for the top-of-page summary bar.
        $totals = [
            'all'    => $rows->count(),
            'meraki' => $rows->where('in_meraki', true)->count(),
            'snmp'   => $rows->where('in_snmp', true)->count(),
            'qos'    => $rows->where('in_qos', true)->count(),
            'gaps'   => $rows->filter(fn ($r) => !$r->in_meraki || !$r->snmp_ready)->count(),
        ];

        return view('admin.network.switches', [
            'rows'     => $rows,
            'networks' => $networks,
            'lastSync' => $lastSync,
            'branches' => $branches,
            'floors'   => $floors,
            'racks'    => $racks,
            'totals'   => $totals,
        ]);
    }

    // ─────────────────────────────────────────────────────────────
    // Add a switch-class Device to SNMP monitoring (creates a stub
    // MonitoredHost with polling disabled — user enables + configures it).
    // ─────────────────────────────────────────────────────────────

    public function addToSnmp(Device $device, SwitchReconciler $reconciler)
    {
        if (!in_array($device->type, ['switch', 'router', 'firewall'])) {
            return back()->with('error', 'Only switches, routers, and firewalls can be added to SNMP.');
        }

        if (!$device->ip_address) {
            return back()->with('error', "Device \"{$device->name}\" has no IP address — cannot create an SNMP host.");
        }

        $host = $reconciler->ensureMonitoredHostForDevice($device);

        if (!$host) {
            return back()->with('error', 'Could not create SNMP host.');
        }

        ActivityLog::create([
            'model_type' => \App\Models\MonitoredHost::class,
            'model_id'   => $host->id,
            'action'     => 'monitored_host_added_from_switches_page',
            'changes'    => ['device_id' => $device->id, 'device_name' => $device->name],
            'user_id'    => Auth::id(),
        ]);

        return redirect()
            ->route('admin.network.monitoring.show', $host->id)
            ->with('success', "SNMP host created for {$device->name}. Configure credentials and enable polling to begin monitoring.");
    }

    // ─────────────────────────────────────────────────────────────
    // Bulk: create MonitoredHost stubs for every switch-class Device
    // that doesn't already have one. Stubs start with snmp_enabled=false
    // so nothing polls until credentials are set (or until
    // syncSnmpFromConfigs() populates them from the running-config).
    // ─────────────────────────────────────────────────────────────

    public function bulkAddToSnmp(SwitchReconciler $reconciler)
    {
        $created  = 0;
        $skipped  = 0;
        $existing = 0;

        Device::whereIn('type', ['switch', 'router', 'firewall'])
            ->orderBy('id')
            ->chunkById(200, function ($devices) use ($reconciler, &$created, &$skipped, &$existing) {
                foreach ($devices as $device) {
                    if (!$device->ip_address) {
                        $skipped++;
                        continue;
                    }
                    $host = $reconciler->ensureMonitoredHostForDevice($device);
                    if (!$host) {
                        $skipped++;
                        continue;
                    }
                    $host->wasRecentlyCreated ? $created++ : $existing++;
                }
            });

        ActivityLog::create([
            'model_type' => MonitoredHost::class,
            'model_id'   => 0,
            'action'     => 'bulk_add_switches_to_snmp',
            'changes'    => ['created' => $created, 'existing' => $existing, 'skipped_no_ip' => $skipped],
            'user_id'    => Auth::id(),
        ]);

        return back()->with('success',
            "Added {$created} switches to SNMP monitoring ({$existing} already existed, {$skipped} skipped — no IP). "
            . "Configure credentials per host, or click 'Sync SNMP from Configs' to auto-fill from running-configs."
        );
    }

    // ─────────────────────────────────────────────────────────────
    // Bulk: walk every switch-class Device that has a saved running
    // config, parse its SNMP stanzas, and upsert the credentials onto
    // the matching MonitoredHost. Creates a host stub via the
    // reconciler when one doesn't exist yet.
    //
    // Polling is only enabled when usable creds were actually parsed
    // (i.e. at least a community string or a v3 user) so we never flip
    // snmp_enabled=true against an empty config section.
    // ─────────────────────────────────────────────────────────────

    public function syncSnmpFromConfigs(SnmpConfigExtractor $extractor, SwitchReconciler $reconciler)
    {
        $updated       = 0;
        $hostsCreated  = 0;
        $noConfig      = 0;
        $noCreds       = 0;
        $skipped       = 0;

        Device::whereIn('type', ['switch', 'router', 'firewall'])
            ->orderBy('id')
            ->chunkById(100, function ($devices) use ($extractor, $reconciler, &$updated, &$hostsCreated, &$noConfig, &$noCreds, &$skipped) {
                foreach ($devices as $device) {
                    if (!$device->ip_address) {
                        $skipped++;
                        continue;
                    }

                    $config = SwitchRunningConfig::where('device_id', $device->id)
                        ->latest('captured_at')
                        ->first();

                    if (!$config || !$config->config_text) {
                        $noConfig++;
                        continue;
                    }

                    $creds = $extractor->pickForMonitoredHost($config->config_text);
                    if (!$creds) {
                        $noCreds++;
                        continue;
                    }

                    $host = $device->monitoredHost;
                    if (!$host) {
                        $host = $reconciler->ensureMonitoredHostForDevice($device);
                        if (!$host) {
                            $skipped++;
                            continue;
                        }
                        if ($host->wasRecentlyCreated) {
                            $hostsCreated++;
                        }
                    }

                    // Mutators on MonitoredHost encrypt community/auth/priv
                    // automatically on save — assign plaintext here.
                    $host->fill($creds);
                    $host->snmp_enabled = true;
                    $host->save();

                    ActivityLog::create([
                        'model_type' => MonitoredHost::class,
                        'model_id'   => $host->id,
                        'action'     => 'snmp_creds_synced_from_config',
                        'changes'    => [
                            'device_id'    => $device->id,
                            'device_name'  => $device->name,
                            'snmp_version' => $creds['snmp_version'] ?? null,
                            'captured_at'  => optional($config->captured_at)->toIso8601String(),
                        ],
                        'user_id'    => Auth::id(),
                    ]);

                    $updated++;
                }
            });

        $msg = "SNMP creds synced from running-configs: {$updated} hosts updated";
        if ($hostsCreated) $msg .= " ({$hostsCreated} new host stubs created)";
        $msg .= ". Skipped: {$noConfig} no-config, {$noCreds} no-snmp-in-config, {$skipped} no-IP.";

        return back()->with('success', $msg);
    }

    // ─────────────────────────────────────────────────────────────
    // Switch detail (ports + clients)
    // ─────────────────────────────────────────────────────────────

    public function switchDetail(string $serial)
    {
        $switch  = NetworkSwitch::with(['branch', 'floor', 'rack'])
                        ->where('serial', $serial)->firstOrFail();
        $ports   = $switch->ports()
                        ->orderByRaw("CAST(port_id AS UNSIGNED) ASC, port_id ASC")
                        ->get();
        $clients = $switch->clients()
                        ->orderBy('status')->orderBy('hostname')
                        ->get();
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $floors   = NetworkFloor::with('branch')->orderBy('sort_order')->orderBy('name')->get();
        $racks    = NetworkRack::with('floor.branch')->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.network.switch-detail', compact('switch', 'ports', 'clients', 'branches', 'floors', 'racks'));
    }

    // ─────────────────────────────────────────────────────────────
    // Clients
    // ─────────────────────────────────────────────────────────────

    public function clients(Request $request)
    {
        $query = NetworkClient::with('networkSwitch')
                    ->orderByRaw("CASE status WHEN 'Online' THEN 1 ELSE 2 END")
                    ->orderBy('hostname');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('hostname',     'like', "%{$s}%")
                  ->orWhere('ip',         'like', "%{$s}%")
                  ->orWhere('mac',        'like', "%{$s}%")
                  ->orWhere('manufacturer','like', "%{$s}%")
                  ->orWhere('description','like', "%{$s}%");
            });
        }

        if ($request->filled('vlan')) {
            $query->where('vlan', (int) $request->vlan);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $clients  = $query->paginate(50)->withQueryString();
        $vlans    = NetworkClient::whereNotNull('vlan')
                        ->distinct()->orderBy('vlan')->pluck('vlan');

        return view('admin.network.clients', compact('clients', 'vlans'));
    }

    // ─────────────────────────────────────────────────────────────
    // Events / Change monitor
    // ─────────────────────────────────────────────────────────────

    public function events(Request $request)
    {
        $query = NetworkEvent::orderByDesc('occurred_at');

        if ($request->filled('serial')) {
            $query->where('switch_serial', $request->serial);
        }
        if ($request->filled('type')) {
            $query->where('event_type', $request->type);
        }
        if ($request->filled('network')) {
            $query->where('network_id', $request->network);
        }

        $events     = $query->paginate(50)->withQueryString();
        $switches   = NetworkSwitch::orderBy('name')->get(['serial', 'name']);
        $eventTypes = NetworkEvent::selectRaw('event_type')
                        ->distinct()->orderBy('event_type')->pluck('event_type');
        $networks   = NetworkSwitch::select('network_id', 'network_name')
                        ->distinct()->orderBy('network_name')->get();

        return view('admin.network.events', compact('events', 'switches', 'eventTypes', 'networks'));
    }

    // ─────────────────────────────────────────────────────────────
    // Sync trigger (runs synchronously)
    // ─────────────────────────────────────────────────────────────

    public function sync()
    {
        $settings = Setting::get();

        if (!$settings->meraki_enabled) {
            return back()->with('error', 'Meraki integration is disabled. Enable it in Settings.');
        }

        if (empty($settings->meraki_api_key) || empty($settings->meraki_org_id)) {
            return back()->with('error', 'Meraki API key or Org ID is not configured in Settings.');
        }

        set_time_limit(300);

        try {
            (new SyncMerakiData())->handle();

            $lastLog = NetworkSyncLog::where('status', 'completed')->latest()->first();
            $msg     = 'Meraki sync completed successfully.';
            if ($lastLog) {
                $msg .= " Switches: {$lastLog->switches_synced}, Ports: {$lastLog->ports_synced}, Clients: {$lastLog->clients_synced}.";
            }

            ActivityLog::create([
                'model_type' => 'Network',
                'model_id'   => 0,
                'action'     => 'synced',
                'changes'    => ['type' => 'meraki_sync_completed'],
                'user_id'    => Auth::id(),
            ]);

            return redirect()->route('admin.network.sync-logs')->with('success', $msg);
        } catch (\Exception $e) {
            ActivityLog::create([
                'model_type' => 'Network',
                'model_id'   => 0,
                'action'     => 'sync_failed',
                'changes'    => ['error' => $e->getMessage()],
                'user_id'    => Auth::id(),
            ]);

            return back()->with('error', 'Sync failed: ' . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // Sync logs
    // ─────────────────────────────────────────────────────────────

    public function syncLogs()
    {
        $logs = NetworkSyncLog::latest()->paginate(25);

        return view('admin.network.sync-logs', compact('logs'));
    }

    // ─────────────────────────────────────────────────────────────
    // Location Management (Floors + Racks)
    // ─────────────────────────────────────────────────────────────

    public function locations()
    {
        $branches = Branch::orderBy('name')
                        ->with(['networkFloors' => function ($q) {
                            $q->orderBy('sort_order')->orderBy('name')
                              ->withCount('switches')
                              ->with(['racks' => function ($q2) {
                                  $q2->withCount('switches');
                              }]);
                        }])
                        ->get();

        return view('admin.network.locations', compact('branches'));
    }

    // ── Floor CRUD ──────────────────────────────────────────────

    public function storeFloor(Request $request)
    {
        $request->validate([
            'branch_id'       => 'required|exists:branches,id',
            'name'            => 'required|string|max:100|unique:network_floors,name,NULL,id,branch_id,' . $request->branch_id,
            'description'     => 'nullable|string|max:255',
            'sort_order'      => 'nullable|integer|min:0',
            'ext_range_start' => 'nullable|integer|min:100|max:99999',
            'ext_range_end'   => 'nullable|integer|min:100|max:99999|gte:ext_range_start',
        ]);

        NetworkFloor::create([
            'branch_id'       => $request->branch_id,
            'name'            => $request->name,
            'description'     => $request->description,
            'sort_order'      => $request->sort_order ?? 0,
            'ext_range_start' => $request->ext_range_start ?: null,
            'ext_range_end'   => $request->ext_range_end   ?: null,
        ]);

        return back()->with('success', "Floor \"{$request->name}\" created.");
    }

    public function updateFloor(Request $request, NetworkFloor $floor)
    {
        $request->validate([
            'name'            => 'required|string|max:100',
            'description'     => 'nullable|string|max:255',
            'sort_order'      => 'nullable|integer|min:0',
            'ext_range_start' => 'nullable|integer|min:100|max:99999',
            'ext_range_end'   => 'nullable|integer|min:100|max:99999|gte:ext_range_start',
        ]);

        $floor->update([
            'name'            => $request->name,
            'description'     => $request->description,
            'sort_order'      => $request->sort_order ?? $floor->sort_order,
            'ext_range_start' => $request->ext_range_start ?: null,
            'ext_range_end'   => $request->ext_range_end   ?: null,
        ]);

        return back()->with('success', "Floor \"{$floor->name}\" updated.");
    }

    public function destroyFloor(NetworkFloor $floor)
    {
        $name = $floor->name;
        $floor->delete();

        return back()->with('success', "Floor \"{$name}\" deleted.");
    }

    // ── Rack CRUD ───────────────────────────────────────────────

    public function storeRack(Request $request)
    {
        $request->validate([
            'floor_id'    => 'required|exists:network_floors,id',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'capacity'    => 'nullable|integer|min:1|max:100',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        NetworkRack::create([
            'floor_id'    => $request->floor_id,
            'name'        => $request->name,
            'description' => $request->description,
            'capacity'    => $request->capacity,
            'sort_order'  => $request->sort_order ?? 0,
        ]);

        return back()->with('success', "Rack \"{$request->name}\" created.");
    }

    public function updateRack(Request $request, NetworkRack $rack)
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'capacity'    => 'nullable|integer|min:1|max:100',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $rack->update([
            'name'        => $request->name,
            'description' => $request->description,
            'capacity'    => $request->capacity,
            'sort_order'  => $request->sort_order ?? $rack->sort_order,
        ]);

        return back()->with('success', "Rack \"{$rack->name}\" updated.");
    }

    public function destroyRack(NetworkRack $rack)
    {
        $name = $rack->name;
        $rack->delete();

        return back()->with('success', "Rack \"{$name}\" deleted.");
    }

    // ── Assign location to a switch ─────────────────────────────

    public function assignLocation(Request $request, string $serial)
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'floor_id'  => 'nullable|exists:network_floors,id',
            'rack_id'   => 'nullable|exists:network_racks,id',
        ]);

        $switch = NetworkSwitch::where('serial', $serial)->firstOrFail();
        $switch->update([
            'branch_id' => $request->branch_id ?: null,
            'floor_id'  => $request->floor_id  ?: null,
            'rack_id'   => $request->rack_id   ?: null,
        ]);

        $switchName = $switch->name ?: $serial;
        return back()->with('success', "Location updated for switch {$switchName}.");
    }

    // ─────────────────────────────────────────────────────────────
    // Test connection (AJAX – called from Settings page)
    // ─────────────────────────────────────────────────────────────

    public function testConnection(Request $request)
    {
        $request->validate([
            'api_key' => 'nullable|string',
            'org_id'  => 'required|string',
        ]);

        try {
            // Use the form value; fall back to the saved API key when the field is left blank
            $apiKey = $request->filled('api_key')
                ? $request->api_key
                : (Setting::get()->meraki_api_key ?? '');

            $meraki  = new MerakiService($apiKey, $request->org_id);
            $orgName = $meraki->testConnection();

            return response()->json([
                'success' => true,
                'message' => "Connected to organisation: {$orgName}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────
    // MAC search (AJAX for asset/printer form autocomplete)
    // ─────────────────────────────────────────────────────────────

    public function macSearch(Request $request)
    {
        $q = trim($request->get('q', ''));

        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $results = collect();

        // ── 1. Meraki clients ─────────────────────────────────────────
        NetworkClient::where('mac', 'like', "%{$q}%")
            ->orWhere('ip', 'like', "%{$q}%")
            ->orWhere('hostname', 'like', "%{$q}%")
            ->orderBy('mac')
            ->limit(20)
            ->get(['mac', 'ip', 'hostname', 'manufacturer', 'switch_serial', 'port_id', 'vlan'])
            ->each(function ($c) use (&$results) {
                $results->push([
                    'mac'          => $c->mac,
                    'ip'           => $c->ip,
                    'hostname'     => $c->hostname,
                    'manufacturer' => $c->manufacturer,
                    'switch_serial'=> $c->switch_serial,
                    'port_id'      => $c->port_id,
                    'vlan'         => $c->vlan,
                    'source'       => 'meraki',
                ]);
            });

        // ── 2. DHCP Leases ────────────────────────────────────────────
        DhcpLease::where('mac_address', 'like', "%{$q}%")
            ->orWhere('ip_address', 'like', "%{$q}%")
            ->orWhere('hostname', 'like', "%{$q}%")
            ->orderBy('mac_address')
            ->limit(20)
            ->get(['mac_address', 'ip_address', 'hostname', 'vendor', 'switch_serial', 'port_id', 'vlan'])
            ->each(function ($l) use (&$results) {
                $results->push([
                    'mac'          => $l->mac_address,
                    'ip'           => $l->ip_address,
                    'hostname'     => $l->hostname,
                    'manufacturer' => $l->vendor,
                    'switch_serial'=> $l->switch_serial,
                    'port_id'      => $l->port_id,
                    'vlan'         => $l->vlan,
                    'source'       => 'dhcp',
                ]);
            });

        // ── 3. IP Reservations ────────────────────────────────────────
        IpReservation::where('mac_address', 'like', "%{$q}%")
            ->orWhere('ip_address', 'like', "%{$q}%")
            ->orWhere('device_name', 'like', "%{$q}%")
            ->orderBy('mac_address')
            ->limit(20)
            ->get(['mac_address', 'ip_address', 'device_name', 'vlan'])
            ->each(function ($r) use (&$results) {
                $results->push([
                    'mac'          => $r->mac_address,
                    'ip'           => $r->ip_address,
                    'hostname'     => $r->device_name,
                    'manufacturer' => null,
                    'switch_serial'=> null,
                    'port_id'      => null,
                    'vlan'         => $r->vlan,
                    'source'       => 'reservation',
                ]);
            });

        // ── Deduplicate by MAC (meraki > dhcp > reservation) ─────────
        $deduped = $results
            ->filter(fn($item) => !empty($item['mac']))
            ->unique('mac')
            ->values()
            ->take(20);

        return response()->json($deduped);
    }

    // ─────────────────────────────────────────────────────────────
    // AJAX helpers for cascading dropdowns in asset forms
    // ─────────────────────────────────────────────────────────────

    public function floorsByBranch(Request $request)
    {
        $branchId = $request->get('branch_id');
        if (!$branchId) {
            return response()->json([]);
        }
        $floors = NetworkFloor::where('branch_id', $branchId)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);
        return response()->json($floors);
    }

    public function officesByFloor(Request $request)
    {
        $floorId = $request->get('floor_id');
        if (!$floorId) {
            return response()->json([]);
        }
        $offices = NetworkOffice::where('floor_id', $floorId)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name']);
        return response()->json($offices);
    }

    // ─────────────────────────────────────────────────────────────
    // Uplink port toggle (AJAX PATCH)
    // ─────────────────────────────────────────────────────────────

    public function setUplinkPorts(Request $request, string $serial)
    {
        $switch = NetworkSwitch::where('serial', $serial)->firstOrFail();

        $request->validate([
            'port_id' => 'required|string',
            'checked' => 'required|boolean',
        ]);

        $portId   = (string) $request->port_id;
        $existing = array_map('strval', $switch->uplink_port_ids ?? []);

        if ($request->boolean('checked')) {
            if (!in_array($portId, $existing, true)) {
                $existing[] = $portId;
            }
        } else {
            $existing = array_values(array_filter($existing, fn($p) => $p !== $portId));
        }

        $switch->update(['uplink_port_ids' => $existing]);

        return response()->json(['success' => true, 'uplink_port_ids' => $existing]);
    }

    // ─────────────────────────────────────────────────────────────
    // Office CRUD (Settings › Locations)
    // ─────────────────────────────────────────────────────────────

    public function storeOffice(Request $request)
    {
        $data = $request->validate([
            'floor_id'    => 'required|exists:network_floors,id',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        NetworkOffice::create($data);
        return back()->with('success', "Office \"{$data['name']}\" added.");
    }

    public function updateOffice(Request $request, NetworkOffice $office)
    {
        $data = $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $office->update($data);
        return back()->with('success', "Office \"{$office->name}\" updated.");
    }

    public function destroyOffice(NetworkOffice $office)
    {
        $name = $office->name;
        $office->delete();
        return back()->with('success', "Office \"{$name}\" deleted.");
    }
}
