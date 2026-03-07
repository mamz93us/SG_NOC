<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncMerakiData;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\NetworkClient;
use App\Models\NetworkEvent;
use App\Models\NetworkFloor;
use App\Models\NetworkOffice;
use App\Models\NetworkRack;
use App\Models\NetworkSwitch;
use App\Models\NetworkSyncLog;
use App\Models\Setting;
use App\Services\Network\MerakiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    public function switches(Request $request)
    {
        $query = NetworkSwitch::orderByRaw("
            CASE status
                WHEN 'online'   THEN 1
                WHEN 'alerting' THEN 2
                WHEN 'offline'  THEN 3
                ELSE 4
            END
        ")->orderBy('name');

        if ($request->filled('network')) {
            $query->where('network_id', $request->network);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $switches  = $query->with(['branch', 'floor', 'rack'])->get();
        $networks  = NetworkSwitch::select('network_id', 'network_name')
                        ->distinct()->orderBy('network_name')->get();
        $lastSync  = NetworkSwitch::max('updated_at');
        $branches  = Branch::orderBy('name')->get(['id', 'name']);
        $floors    = NetworkFloor::with('branch')->orderBy('sort_order')->orderBy('name')->get();
        $racks     = NetworkRack::with('floor.branch')->orderBy('sort_order')->orderBy('name')->get();

        return view('admin.network.switches', compact('switches', 'networks', 'lastSync', 'branches', 'floors', 'racks'));
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
            'branch_id'   => 'required|exists:branches,id',
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        NetworkFloor::create([
            'branch_id'   => $request->branch_id,
            'name'        => $request->name,
            'description' => $request->description,
            'sort_order'  => $request->sort_order ?? 0,
        ]);

        return back()->with('success', "Floor \"{$request->name}\" created.");
    }

    public function updateFloor(Request $request, NetworkFloor $floor)
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $floor->update([
            'name'        => $request->name,
            'description' => $request->description,
            'sort_order'  => $request->sort_order ?? $floor->sort_order,
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

        $clients = NetworkClient::where('mac', 'like', "%{$q}%")
            ->orWhere('ip', 'like', "%{$q}%")
            ->orWhere('hostname', 'like', "%{$q}%")
            ->orderBy('mac')
            ->limit(20)
            ->get(['mac', 'ip', 'hostname', 'manufacturer', 'switch_serial', 'port_id', 'vlan']);

        return response()->json($clients);
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
