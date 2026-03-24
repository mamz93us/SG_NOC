<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertState;
use App\Models\Branch;
use App\Models\HostCheck;
use App\Models\Mib;
use App\Models\MonitoredHost;
use App\Models\SensorMetric;
use App\Models\SnmpSensor;
use App\Models\VpnTunnel;
use App\Services\Snmp\SnmpClient;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SnmpMonitoringController extends Controller
{
    public function index()
    {
        $hosts = MonitoredHost::with(['branch', 'vpnTunnel', 'mib'])->orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();
        $tunnels = VpnTunnel::orderBy('name')->get();
        $mibs = Mib::orderBy('name')->get();
        $snmpLoaded = SnmpClient::isSnmpExtensionLoaded();

        return view('admin.network.monitoring.index', compact('hosts', 'branches', 'tunnels', 'mibs', 'snmpLoaded'));
    }

    public function show(MonitoredHost $host)
    {
        $host->load([
            'branch',
            'vpnTunnel',
            'mib',
            // latestMetric uses a single subquery for all sensors (replaces N+1 pattern)
            'snmpSensors.latestMetric',
        ]);

        $snmpLoaded = SnmpClient::isSnmpExtensionLoaded();
        $mibs = Mib::orderBy('name')->get();

        return view('admin.network.monitoring.show', compact('host', 'snmpLoaded', 'mibs'));
    }

    public function settings(MonitoredHost $host, \App\Services\Snmp\MibParser $parser)
    {
        $host->load(['mib', 'snmpSensors']);

        $discoveredObjects = [];
        if ($host->mib) {
            $discoveredObjects = $parser->parseObjects($host->mib->file_path);
        }

        $mibs = Mib::orderBy('name')->get();

        return view('admin.network.monitoring.settings', compact('host', 'mibs', 'discoveredObjects'));
    }

    public function forcePoll(MonitoredHost $host)
    {
        // Cleanup old string-based sensors that were broken by the previous parser
        SnmpSensor::where('oid', 'like', '%::%')->delete();

        // Run synchronously so user sees results immediately (shared hosting — no queue worker)
        \App\Jobs\CollectSnmpMetricsJob::dispatchSync($host);

        return back()->with('success', 'Forced SNMP polling completed. Metrics should now be updated.');
    }

    public function metrics(MonitoredHost $host): JsonResponse
    {
        $host->load([
            'snmpSensors.sensorMetrics' => fn($q) => $q->where('recorded_at', '>=', now()->subHours(24))->orderBy('recorded_at'),
            'hostChecks' => fn($q) => $q->where('checked_at', '>=', now()->subHours(24))->orderBy('checked_at'),
        ]);

        $sensorData = [];
        foreach ($host->snmpSensors as $sensor) {
            $sensorData[] = [
                'id' => $sensor->id,
                'name' => $sensor->name,
                'oid' => $sensor->oid,
                'data_type' => $sensor->data_type,
                'unit' => $sensor->unit,
                'status' => $sensor->status,
                'sensor_group' => $sensor->sensor_group,
                'interface_index' => $sensor->interface_index,
                'warning_threshold' => $sensor->warning_threshold,
                'critical_threshold' => $sensor->critical_threshold,
                'metrics' => $sensor->sensorMetrics->map(fn($m) => [
                    'value' => $m->value,
                    'recorded_at' => $m->recorded_at->toIso8601String(),
                ]),
            ];
        }

        $pingData = $host->hostChecks->map(fn($c) => [
            'latency_ms' => $c->latency_ms,
            'packet_loss' => $c->packet_loss,
            'success' => $c->success,
            'checked_at' => $c->checked_at ?? $c->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'host' => [
                'id' => $host->id,
                'name' => $host->name,
                'ip' => $host->ip,
                'status' => $host->status,
                'type' => $host->type,
                'discovered_type' => $host->discovered_type,
                'last_snmp_at' => $host->last_snmp_at?->toIso8601String(),
                'last_ping_at' => $host->last_ping_at?->toIso8601String(),
            ],
            'sensors' => $sensorData,
            'ping' => $pingData,
        ]);
    }

    public function snmpHealth()
    {
        $snmpLoaded = SnmpClient::isSnmpExtensionLoaded();

        $totalHosts = MonitoredHost::where('snmp_enabled', true)->count();
        $unreachableHosts = MonitoredHost::where('snmp_enabled', true)
            ->whereIn('status', ['down', 'degraded'])
            ->count();

        $staleSensorMinutes = 10;
        $staleSensors = SnmpSensor::whereHas('host', fn($q) => $q->where('snmp_enabled', true))
            ->where(function ($q) use ($staleSensorMinutes) {
                $q->whereNull('last_recorded_at')
                  ->orWhere('last_recorded_at', '<', now()->subMinutes($staleSensorMinutes));
            })
            ->count();

        $unreachableSensors = SnmpSensor::where('status', '!=', 'active')->count();

        $hosts = MonitoredHost::with('snmpSensors')
            ->where('snmp_enabled', true)
            ->orderBy('status')
            ->orderBy('name')
            ->get();

        return view('admin.network.monitoring.health', compact(
            'snmpLoaded',
            'totalHosts',
            'unreachableHosts',
            'staleSensors',
            'unreachableSensors',
            'hosts'
        ));
    }

    public function pollAll()
    {
        $hosts = MonitoredHost::where('snmp_enabled', true)
            ->where('status', '!=', 'down')
            ->get();

        $polled = 0;
        $errors = 0;

        foreach ($hosts as $host) {
            try {
                \App\Jobs\CollectSnmpMetricsJob::dispatch($host);
                $polled++;
            } catch (\Throwable $e) {
                $errors++;
                \Log::error("Poll dispatch failed for {$host->name}: " . $e->getMessage());
            }
        }

        $msg = "Dispatched SNMP polling for {$polled} host(s).";
        if ($errors > 0) {
            $msg .= " {$errors} failed to dispatch.";
        }

        return back()->with('success', $msg);
    }

    public function pollAllSync()
    {
        $hosts = MonitoredHost::where('snmp_enabled', true)
            ->where('status', '!=', 'down')
            ->get();

        $polled = 0;
        $errors = 0;

        foreach ($hosts as $host) {
            try {
                \App\Jobs\CollectSnmpMetricsJob::dispatchSync($host);
                $polled++;
            } catch (\Throwable $e) {
                $errors++;
                \Log::error("Poll sync failed for {$host->name}: " . $e->getMessage());
            }
        }

        return back()->with('success', "Synchronous SNMP polling completed for {$polled} host(s). {$errors} error(s).");
    }

    public function storeMibSensors(Request $request, MonitoredHost $host)
    {
        $request->validate([
            'sensors' => 'required|array',
        ]);

        $selectedSensors = collect($request->sensors)->where('enabled', '1');

        if ($selectedSensors->isEmpty()) {
            return back()->with('error', 'No sensors selected.');
        }

        foreach ($selectedSensors as $s) {
            $oid = $s['oid'];
            
            // Smarter OID formatting: 
            // 1. If it's a numeric OID (starts with .) and is likely a scalar (doesn't end in .0 and isn't a table entry)
            // 2. We'll append .0 if it's numeric and doesn't have it.
            // 3. User can always override manually if needed, but for MIB imports, scalars usually need .0
            if (str_starts_with($oid, '.') && !str_ends_with($oid, '.0')) {
                // If the name doesn't contain "Table" or "Entry", it's likely a scalar or a leaf
                if (!str_contains($s['name'], 'Table') && !str_contains($s['name'], 'Entry')) {
                    $oid .= '.0';
                }
            }

            $host->snmpSensors()->firstOrCreate(
                ['oid' => $oid],
                [
                    'name' => $s['name'],
                    'data_type' => $s['data_type'] ?? 'gauge',
                    'unit' => $s['unit'] ?? null,
                    'poll_interval' => 60,
                    'graph_enabled' => true,
                ]
            );
        }

        return back()->with('success', $selectedSensors->count() . ' sensors added from MIB.');
    }

    public function storeHost(Request $request)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'ip'                  => 'required|string',
            'type'                => 'required|string',
            'branch_id'           => 'nullable|exists:branches,id',
            'vpn_id'              => 'nullable|exists:vpn_tunnels,id',
            'ping_enabled'        => 'boolean',
            'ping_interval_seconds' => 'nullable|integer|min:10',
            'ping_packet_count'   => 'nullable|integer|min:1|max:20',
            'alert_enabled'       => 'boolean',
            'snmp_enabled'        => 'boolean',
            'snmp_port'           => 'nullable|integer',
            'snmp_version'        => 'required_if:snmp_enabled,1|in:v1,v2c,v3',
            'snmp_community'      => 'nullable|string',
            'mib_id'              => 'nullable|exists:mibs,id',
            'snmp_auth_user'      => 'nullable|string|max:100',
            'snmp_auth_password'  => 'nullable|string|max:255',
            'snmp_auth_protocol'  => 'nullable|in:md5,sha,sha256',
            'snmp_priv_password'  => 'nullable|string|max:255',
            'snmp_priv_protocol'  => 'nullable|in:des,aes,aes256',
            'snmp_security_level' => 'nullable|in:noAuthNoPriv,authNoPriv,authPriv',
            'snmp_context_name'   => 'nullable|string|max:100',
        ]);

        $data = $request->except(['_token', '_method']);
        $data['alert_enabled'] = $request->boolean('alert_enabled', false);

        // Ensure snmp_community is always a string
        $data['snmp_community'] = $data['snmp_community'] ?? '';

        // Ensure snmp_port has a default
        $data['snmp_port'] = $data['snmp_port'] ?? 161;

        if (empty($data['ping_interval_seconds'])) {
            $data['ping_interval_seconds'] = 60;
        }
        if (empty($data['ping_packet_count'])) {
            $data['ping_packet_count'] = 3;
        }

        // Clear v3 fields when not using SNMPv3
        // snmp_auth_protocol (default 'sha'), snmp_priv_protocol (default 'aes'),
        // and snmp_security_level are NOT NULL — must supply a value, not null.
        if (($data['snmp_version'] ?? '') !== 'v3') {
            $data['snmp_auth_user']      = null;
            $data['snmp_auth_password']  = null;
            $data['snmp_auth_protocol']  = 'sha';          // NOT NULL, keep DB default
            $data['snmp_priv_password']  = null;
            $data['snmp_priv_protocol']  = 'aes';          // NOT NULL, keep DB default
            $data['snmp_security_level'] = 'noAuthNoPriv'; // NOT NULL
            $data['snmp_context_name']   = null;
        }

        MonitoredHost::create($data);

        return redirect()->route('admin.network.monitoring.index')
            ->with('success', 'Monitored host added successfully.');
    }

    public function updateHost(Request $request, MonitoredHost $host)
    {
        $request->validate([
            'name'                => 'required|string|max:255',
            'ip'                  => 'required|string',
            'type'                => 'required|string',
            'branch_id'           => 'nullable|exists:branches,id',
            'vpn_id'              => 'nullable|exists:vpn_tunnels,id',
            'ping_enabled'        => 'boolean',
            'ping_interval_seconds' => 'nullable|integer|min:10',
            'ping_packet_count'   => 'nullable|integer|min:1|max:20',
            'alert_enabled'       => 'boolean',
            'snmp_enabled'        => 'boolean',
            'snmp_port'           => 'nullable|integer',
            'snmp_version'        => 'required_if:snmp_enabled,1|in:v1,v2c,v3',
            'snmp_community'      => 'nullable|string',
            'mib_id'              => 'nullable|exists:mibs,id',
            'snmp_auth_user'      => 'nullable|string|max:100',
            'snmp_auth_password'  => 'nullable|string|max:255',
            'snmp_auth_protocol'  => 'nullable|in:md5,sha,sha256',
            'snmp_priv_password'  => 'nullable|string|max:255',
            'snmp_priv_protocol'  => 'nullable|in:des,aes,aes256',
            'snmp_security_level' => 'nullable|in:noAuthNoPriv,authNoPriv,authPriv',
            'snmp_context_name'   => 'nullable|string|max:100',
        ]);

        $data = $request->except(['_token', '_method']);
        $data['ping_enabled']  = $request->boolean('ping_enabled', false);
        $data['snmp_enabled']  = $request->boolean('snmp_enabled', false);
        $data['alert_enabled'] = $request->boolean('alert_enabled', false);

        // Ensure snmp_community is always a string
        if (!isset($data['snmp_community']) || $data['snmp_community'] === null) {
            $data['snmp_community'] = '';
        }

        // Ensure snmp_port has a default
        $data['snmp_port'] = $data['snmp_port'] ?? 161;

        if (empty($data['ping_interval_seconds'])) {
            $data['ping_interval_seconds'] = 60;
        }
        if (empty($data['ping_packet_count'])) {
            $data['ping_packet_count'] = 3;
        }

        // Clear v3 fields when not using SNMPv3
        // snmp_auth_protocol (default 'sha'), snmp_priv_protocol (default 'aes'),
        // and snmp_security_level are NOT NULL — must supply a value, not null.
        if (($data['snmp_version'] ?? '') !== 'v3') {
            $data['snmp_auth_user']      = null;
            $data['snmp_auth_password']  = null;
            $data['snmp_auth_protocol']  = 'sha';          // NOT NULL, keep DB default
            $data['snmp_priv_password']  = null;
            $data['snmp_priv_protocol']  = 'aes';          // NOT NULL, keep DB default
            $data['snmp_security_level'] = 'noAuthNoPriv'; // NOT NULL
            $data['snmp_context_name']   = null;
        }

        // If v3 passwords are left blank on edit, keep existing encrypted values
        if (($data['snmp_version'] ?? '') === 'v3') {
            if (empty($data['snmp_auth_password'])) {
                unset($data['snmp_auth_password']);
            }
            if (empty($data['snmp_priv_password'])) {
                unset($data['snmp_priv_password']);
            }
        }

        $host->update($data);

        return redirect()->route('admin.network.monitoring.index')
            ->with('success', 'Host updated successfully.');
    }

    public function destroyHost(MonitoredHost $host)
    {
        $host->delete();
        return redirect()->route('admin.network.monitoring.index')
            ->with('success', 'Host removed from monitoring.');
    }

    public function storeSensor(Request $request, MonitoredHost $host)
    {
        $request->validate([
            'name'          => 'required|string|max:255',
            'oid'           => 'required|string|max:255|regex:/^[0-9\.]+$/',
            'data_type'     => 'required|string|in:gauge,counter,rate,temperature,uptime,boolean',
            'unit'          => 'nullable|string|max:50',
            'poll_interval' => 'nullable|integer|min:10',
            'graph_enabled' => 'boolean',
        ]);

        $host->snmpSensors()->create([
            'name'          => $request->name,
            'oid'           => $request->oid,
            'data_type'     => $request->data_type,
            'unit'          => $request->unit,
            'poll_interval' => $request->poll_interval ?? 60,
            'graph_enabled' => $request->boolean('graph_enabled', false),
        ]);

        return redirect()->route('admin.network.monitoring.show', $host)
            ->with('success', 'SNMP Sensor added successfully.');
    }

    public function destroySensor(MonitoredHost $host, $sensorId)
    {
        $sensor = $host->snmpSensors()->findOrFail($sensorId);
        $sensor->delete();

        return redirect()->route('admin.network.monitoring.show', $host)
            ->with('success', 'SNMP Sensor removed.');
    }

    public function mibs()
    {
        $mibs = Mib::orderBy('name')->get();
        return view('admin.network.monitoring.mibs', compact('mibs'));
    }

    public function storeMib(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'file' => 'required|file',
        ]);

        $path = $request->file('file')->store('mibs');

        Mib::create([
            'name' => $request->name,
            'description' => $request->description,
            'file_path' => $path,
        ]);

        return redirect()->route('admin.network.monitoring.mibs')
            ->with('success', 'MIB file uploaded successfully.');
    }

    public function viewMib(Mib $mib)
    {
        if (!Storage::disk('local')->exists($mib->file_path)) {
            return back()->with('error', 'MIB file not found on disk. Tried path: ' . $mib->file_path);
        }

        $content = Storage::disk('local')->get($mib->file_path);
        return view('admin.network.monitoring.mib_view', compact('mib', 'content'));
    }

    public function updateMibAssignment(Request $request, MonitoredHost $host)
    {
        $request->validate(['mib_id' => 'nullable|exists:mibs,id']);
        $host->update(['mib_id' => $request->mib_id]);

        return back()->with('success', 'MIB assigned to host successfully.');
    }

    public function sensorHistory(Request $request, SnmpSensor $sensor): JsonResponse
    {
        $days = (int) $request->get('days', 7);
        $days = min($days, 90); // cap at 90

        $useHourly = class_exists(\App\Models\SensorMetricHourly::class);
        $useDaily  = class_exists(\App\Models\SensorMetricDaily::class);

        if ($days <= 3) {
            // Raw metrics (5-min resolution)
            $data = SensorMetric::where('sensor_id', $sensor->id)
                ->where('recorded_at', '>=', now()->subDays($days))
                ->orderBy('recorded_at')
                ->get(['recorded_at as ts', 'value'])
                ->map(fn ($r) => ['ts' => Carbon::parse($r->ts)->toIso8601String(), 'v' => round($r->value, 2)]);
        } elseif ($days <= 14 && $useHourly) {
            // Hourly rollups
            $data = \App\Models\SensorMetricHourly::where('sensor_id', $sensor->id)
                ->where('hour', '>=', now()->subDays($days))
                ->orderBy('hour')
                ->get(['hour as ts', 'value_avg as v'])
                ->map(fn ($r) => ['ts' => Carbon::parse($r->ts)->toIso8601String(), 'v' => round($r->v, 2)]);
        } elseif ($days > 14 && $useDaily) {
            // Daily rollups
            $data = \App\Models\SensorMetricDaily::where('sensor_id', $sensor->id)
                ->where('date', '>=', now()->subDays($days)->toDateString())
                ->orderBy('date')
                ->get(['date as ts', 'value_avg as v'])
                ->map(fn ($r) => ['ts' => $r->ts, 'v' => round($r->v, 2)]);
        } else {
            // Fallback: raw metrics for any range
            $data = SensorMetric::where('sensor_id', $sensor->id)
                ->where('recorded_at', '>=', now()->subDays($days))
                ->orderBy('recorded_at')
                ->get(['recorded_at as ts', 'value'])
                ->map(fn ($r) => ['ts' => Carbon::parse($r->ts)->toIso8601String(), 'v' => round($r->value, 2)]);
        }

        return response()->json([
            'sensor' => ['name' => $sensor->name, 'unit' => $sensor->unit],
            'data'   => $data,
        ]);
    }

    public function discoverDevice(MonitoredHost $host)
    {
        dispatch_sync(new \App\Jobs\DiscoverSnmpDeviceJob($host));
        return back()->with('success', 'Device discovery completed synchronously.');
    }

    public function discoverInterfaces(MonitoredHost $host)
    {
        dispatch_sync(new \App\Jobs\DiscoverSnmpInterfacesJob($host));
        return back()->with('success', 'Interface discovery completed synchronously.');
    }

    public function pingHost(MonitoredHost $host, \App\Services\PingService $pingService)
    {
        try {
            $pingCount = $host->ping_packet_count ?? 3;
            $pingResult = $pingService->ping($host->ip, $pingCount);

            \App\Models\HostCheck::create([
                'host_id' => $host->id,
                'check_type' => 'ping',
                'latency_ms' => $pingResult['latency'],
                'packet_loss' => $pingResult['packet_loss'],
                'success' => $pingResult['success'],
            ]);

            if ($pingResult['success']) {
                $host->last_ping_at = now();
                if ($host->snmp_enabled && $host->last_snmp_at && $host->last_snmp_at->diffInMinutes(now()) > 3) {
                    $host->status = 'degraded';
                } else {
                    $host->status = 'up';
                }
            } else {
                $host->status = 'down';
            }
            $host->last_checked_at = now();
            $host->save();

            $statusText = $pingResult['success'] ? "Host is Up ({$pingResult['latency']}ms)" : "Host is Down";
            return back()->with('success', "Manual Ping Completed: $statusText");

        } catch (\Exception $e) {
            return back()->with('error', 'Ping failed: ' . $e->getMessage());
        }
    }

    // ─── SNMP Hosts List (table view) ────────────────────────────

    public function hostsList(Request $request)
    {
        $query = MonitoredHost::with(['branch'])
            ->withCount('snmpSensors')
            ->orderBy('name');

        if ($search = $request->search) {
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                                      ->orWhere('ip',   'like', "%{$search}%"));
        }
        if ($branchId = $request->branch_id) {
            $query->where('branch_id', $branchId);
        }
        if ($status = $request->status) {
            $query->where('status', $status);
        }
        if ($type = $request->type) {
            $query->where('type', $type);
        }
        if ($request->boolean('has_alerts')) {
            $ids = AlertState::where('entity_type', 'MonitoredHost')
                ->where('state', 'alerted')
                ->pluck('entity_id');
            $query->whereIn('id', $ids);
        }

        $hosts = $query->paginate(25)->withQueryString();

        // Summary counts
        $totalHosts    = MonitoredHost::count();
        $upCount       = MonitoredHost::where('status', 'up')->count();
        $downCount     = MonitoredHost::where('status', 'down')->count();
        $alertHostCount = AlertState::where('entity_type', 'MonitoredHost')
            ->where('state', 'alerted')
            ->distinct('entity_id')
            ->count('entity_id');

        // Active alert counts keyed by host_id
        $activeAlerts = AlertState::where('entity_type', 'MonitoredHost')
            ->where('state', 'alerted')
            ->select('entity_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('entity_id')
            ->pluck('cnt', 'entity_id');

        // Latest ping check per host (within the current page)
        $pageIds = $hosts->pluck('id');
        $latestChecks = HostCheck::whereIn('host_id', $pageIds)
            ->where('check_type', 'ping')
            ->select('host_id',
                DB::raw('MAX(checked_at) as checked_at'),
                DB::raw('AVG(latency_ms) as latency_ms'),
                DB::raw('AVG(packet_loss) as packet_loss'))
            ->groupBy('host_id')
            ->get()
            ->keyBy('host_id');

        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $types    = MonitoredHost::distinct()->pluck('type')->filter()->sort()->values();

        return view('admin.network.monitoring.hosts', compact(
            'hosts', 'branches', 'types',
            'totalHosts', 'upCount', 'downCount', 'alertHostCount',
            'activeAlerts', 'latestChecks'
        ));
    }

    // ─── SNMP Monitoring Dashboard ───────────────────────────────

    public function monitoringDashboard()
    {
        $totalHosts     = MonitoredHost::count();
        $upCount        = MonitoredHost::where('status', 'up')->count();
        $downCount      = MonitoredHost::where('status', 'down')->count();
        $degradedCount  = MonitoredHost::where('status', 'degraded')->count();
        $unknownCount   = MonitoredHost::where('status', 'unknown')->orWhereNull('status')->count();
        $totalSensors   = SnmpSensor::count();
        $alertHostCount = AlertState::where('entity_type', 'MonitoredHost')
            ->where('state', 'alerted')
            ->distinct('entity_id')
            ->count('entity_id');

        // Branch breakdown
        $branchBreakdown = MonitoredHost::select(
                'branch_id',
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status='up'       THEN 1 ELSE 0 END) as up_count"),
                DB::raw("SUM(CASE WHEN status='down'     THEN 1 ELSE 0 END) as down_count"),
                DB::raw("SUM(CASE WHEN status='degraded' THEN 1 ELSE 0 END) as degraded_count")
            )
            ->with('branch:id,name')
            ->groupBy('branch_id')
            ->orderByDesc('total')
            ->get();

        // Recent down hosts
        $downHosts = MonitoredHost::with('branch:id,name')
            ->where('status', 'down')
            ->orderByDesc('last_checked_at')
            ->limit(10)
            ->get();

        // Recent active alert states
        $recentAlerts = AlertState::with('rule')
            ->whereIn('state', ['alerted', 'acknowledged'])
            ->orderByDesc('first_triggered_at')
            ->limit(15)
            ->get()
            ->map(function ($a) {
                $a->host = MonitoredHost::find($a->entity_id);
                return $a;
            });

        // Top problematic hosts (most alert_state rows in last 7 days)
        $topProblematic = AlertState::where('entity_type', 'MonitoredHost')
            ->where('first_triggered_at', '>=', now()->subDays(7))
            ->select('entity_id', DB::raw('COUNT(*) as rule_count'), DB::raw('SUM(alert_count) as total_fires'))
            ->groupBy('entity_id')
            ->orderByDesc('rule_count')
            ->limit(8)
            ->get()
            ->map(fn($a) => tap($a, fn($a) => $a->host = MonitoredHost::find($a->entity_id)))
            ->filter(fn($a) => $a->host !== null)
            ->values();

        return view('admin.network.monitoring.dashboard', compact(
            'totalHosts', 'upCount', 'downCount', 'degradedCount', 'unknownCount',
            'totalSensors', 'alertHostCount',
            'branchBreakdown', 'downHosts', 'recentAlerts', 'topProblematic'
        ));
    }
}
