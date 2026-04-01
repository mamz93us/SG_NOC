<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IdentityUser;
use App\Models\NetworkSwitch;
use App\Models\NocEvent;
use App\Models\PhonePortMap;
use App\Models\Printer;
use App\Models\UcmActiveCall;
use App\Models\UcmExtensionCache;
use App\Models\UcmTrunkCache;
use App\Services\HealthScoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NocController extends Controller
{
    public function __construct(private HealthScoringService $health) {}

    public function dashboard()
    {
        // ── FAST QUERIES ONLY ──────────────────────────────────────────
        // Heavy sections (UCM, Branch Health, VPN details, Sophos VPN)
        // are loaded via AJAX from dashboardHeavyData() to speed up initial paint.

        // Global Identity summary (3 fast COUNT queries)
        $identityQuery = IdentityUser::query();
        $allowedDomains = \App\Models\AllowedDomain::getList();
        if (!empty($allowedDomains)) {
            $identityQuery->where(function ($q) use ($allowedDomains) {
                foreach ($allowedDomains as $domain) {
                    $q->orWhere('user_principal_name', 'like', "%@{$domain}");
                }
            });
        }
        $totalUsers      = (clone $identityQuery)->count();
        $licensedUsers   = (clone $identityQuery)->whereNotNull('assigned_licenses')->where('assigned_licenses', '!=', '[]')->where('assigned_licenses', '!=', 'null')->count();
        $licensedPercent = $totalUsers > 0 ? (int) round($licensedUsers / $totalUsers * 100) : 0;

        // Network summary (2 COUNT queries)
        $totalSwitches  = NetworkSwitch::count();
        $onlineSwitches = NetworkSwitch::where('status', 'online')->count();
        $onlinePercent  = $totalSwitches > 0 ? (int) round($onlineSwitches / $totalSwitches * 100) : 0;

        // Assets summary (2 COUNT queries + 1 subquery)
        $totalDevices    = Device::count();
        $missingCreds    = Device::whereDoesntHave('credentials')->count();
        $printersOverdue = Printer::whereNotNull('service_interval_days')
            ->where('service_interval_days', '>', 0)
            ->whereRaw("DATE_ADD(COALESCE(last_service_date, created_at), INTERVAL service_interval_days DAY) < NOW()")
            ->count();

        // Phones & Contacts (3 COUNT queries)
        $contactCount      = \App\Models\Contact::count();
        $phoneRequestCount = \App\Models\PhoneRequestLog::distinct('mac')->whereNotNull('mac')->count();
        $totalXmlRequests  = \App\Models\PhoneRequestLog::count();

        // VoIP quick counts from cache tables (fast COUNTs — no API calls)
        $totalExt   = UcmExtensionCache::count();
        $totalIdle  = UcmExtensionCache::where('status', 'idle')->count();
        $totalInUse = UcmExtensionCache::whereIn('status', ['inuse', 'busy', 'ringing'])->count();

        // VPN / Hosts summary (4 COUNT queries — no ::all())
        $vpnTotal  = \App\Models\VpnTunnel::count();
        $vpnOnline = \App\Models\VpnTunnel::where('status', 'up')->count();
        $hostsUp   = \App\Models\MonitoredHost::where('status', 'up')->count();
        $hostsDown = \App\Models\MonitoredHost::where('status', 'down')->count();

        // DHCP (3 COUNT queries)
        $dhcpTotal     = \App\Models\DhcpLease::count();
        $dhcpConflicts = \App\Models\DhcpLease::where('is_conflict', true)->count();
        $dhcpBySource  = \App\Models\DhcpLease::selectRaw("source, count(*) as cnt")->groupBy('source')->pluck('cnt', 'source')->toArray();

        // Sophos summary (COUNT queries only)
        $sophosTotal    = \App\Models\SophosFirewall::count();
        $sophosSynced   = \App\Models\SophosFirewall::whereNotNull('last_synced_at')->count();
        // S2S VPN total tunnels from SNMP sensors (fast COUNT — detailed up/down loaded via AJAX)
        $sophosVpnTotal = \App\Models\SnmpSensor::where('sensor_group', 'VPN')
            ->where('name', 'like', 'VPN:%- Connection')->count();

        // Top Subnets (1 query, limit 5)
        $topSubnets = \App\Models\IpamSubnet::withCount(['ipReservations', 'dhcpLeases'])
            ->orderByDesc('ip_reservations_count')->limit(5)->get();

        // Open events (1 query, limit 10)
        $openEvents = NocEvent::open()->orderByDesc('severity')->orderByDesc('last_seen')->limit(10)->get();

        return view('admin.noc.dashboard', compact(
            'totalUsers', 'licensedUsers', 'licensedPercent',
            'totalSwitches', 'onlineSwitches', 'onlinePercent',
            'totalDevices', 'missingCreds', 'printersOverdue',
            'openEvents',
            'contactCount', 'phoneRequestCount', 'totalXmlRequests',
            'totalExt', 'totalIdle', 'totalInUse',
            'vpnTotal', 'vpnOnline',
            'hostsUp', 'hostsDown',
            'dhcpTotal', 'dhcpConflicts', 'dhcpBySource',
            'sophosTotal', 'sophosSynced', 'sophosVpnTotal',
            'topSubnets'
        ));
    }

    // ── AJAX Heavy Data ──────────────────────────────────────────────

    public function dashboardHeavyData()
    {
        // UCM PBX Stats (the N+1 loop that was slowing the page)
        $ucmServers = \App\Models\UcmServer::orderBy('name')->get();
        $ucmStats = $ucmServers->map(function ($server) {
            $stats = \App\Services\IppbxApiService::getCachedStats($server);
            return [
                'name'     => $server->name,
                'host'     => parse_url($server->url, PHP_URL_HOST) ?? $server->url,
                'online'   => $stats['online'] ?? false,
                'uptime'   => $stats['uptime'] ?? null,
                'error'    => $stats['error'] ?? null,
                'model'    => $stats['model'] ?? null,
                'firmware' => $stats['firmware'] ?? null,
                'ext'      => $stats['extensions'] ?? [],
                'trunks'   => $stats['trunk_counts'] ?? [],
            ];
        });

        // Branch Health (heaviest — calls HealthScoringService for each branch)
        $branches = $this->health->allBranches()->map(fn ($b) => [
            'id'     => $b->id,
            'name'   => $b->name,
            'health' => $b->health,
            'color'  => [
                'total'    => HealthScoringService::healthColorStatic($b->health['total'] ?? 0),
                'identity' => HealthScoringService::healthColorStatic($b->health['identity'] ?? 0),
                'network'  => HealthScoringService::healthColorStatic($b->health['network'] ?? 0),
                'asset'    => HealthScoringService::healthColorStatic($b->health['asset'] ?? 0),
            ],
        ]);

        // VPN Tunnel Details (was loading ::all() for the detail grid)
        $vpnTunnels = \App\Models\VpnTunnel::with('branch')->orderBy('status')->get()->map(fn ($t) => [
            'name'   => $t->name,
            'status' => $t->status,
            'branch' => $t->branch?->name ?: 'No branch',
        ]);

        // Sophos S2S VPN Tunnels — from SNMP sensors (sensor_group='VPN')
        // Each tunnel has 2 sensors: "VPN: {name} - Active" and "VPN: {name} - Connection"
        // Connection sensor value: 1.0 = connected, 0.0 = disconnected
        $vpnSensors = \App\Models\SnmpSensor::with(['host.branch'])
            ->where('sensor_group', 'VPN')
            ->where('name', 'like', 'VPN:%- Connection')
            ->get();

        $sophosVpnTunnels = $vpnSensors->map(function ($sensor) {
            // Extract tunnel name from "VPN: TunnelName - Connection"
            $tunnelName = trim(str_replace(['VPN:', '- Connection'], '', $sensor->name));

            // Get latest metric value for connection status
            $latestMetric = $sensor->sensorMetrics()
                ->orderByDesc('recorded_at')
                ->first();

            $isConnected = $latestMetric && $latestMetric->value >= 1.0;

            return [
                'name'         => $tunnelName,
                'status'       => $isConnected ? 'up' : 'down',
                'firewall'     => $sensor->host?->name ?: '-',
                'firewall_ip'  => $sensor->host?->ip ?: '-',
                'branch'       => $sensor->host?->branch?->name ?: 'No branch',
                'last_checked' => $latestMetric?->recorded_at?->diffForHumans() ?: ($sensor->last_recorded_at?->diffForHumans() ?: '-'),
            ];
        })->sortBy('status')->values();

        return response()->json([
            'ucm_stats'          => $ucmStats,
            'branches'           => $branches,
            'vpn_tunnels'        => $vpnTunnels,
            'vpn_summary'        => [
                'up'         => $vpnTunnels->where('status', 'up')->count(),
                'connecting' => $vpnTunnels->where('status', 'connecting')->count(),
                'down'       => $vpnTunnels->where('status', 'down')->count(),
            ],
            'sophos_vpn_tunnels' => $sophosVpnTunnels,
            'sophos_vpn_summary' => [
                'up'   => $sophosVpnTunnels->where('status', 'up')->count(),
                'down' => $sophosVpnTunnels->where('status', 'down')->count(),
            ],
        ]);
    }

    public function branch(Branch $branch)
    {
        $score    = $this->health->scoreForBranch($branch->id);
        $switches = NetworkSwitch::where('branch_id', $branch->id)->get();
        $devices  = Device::where('branch_id', $branch->id)->with('credentials')->get();
        $printers = Printer::where('branch_id', $branch->id)->get();

        // Phase 4A: additional data for single pane of glass
        $vpnTunnels   = \App\Models\VpnTunnel::where('branch_id', $branch->id)->get();
        $ispConns     = \App\Models\IspConnection::where('branch_id', $branch->id)->get();
        $monitorHosts = \App\Models\MonitoredHost::where('branch_id', $branch->id)->get();
        $landlines    = \App\Models\Landline::where('branch_id', $branch->id)->get();
        $ipCount      = \App\Models\IpReservation::where('branch_id', $branch->id)->count();
        $employees    = \App\Models\Employee::where('branch_id', $branch->id)->get();
        $openAlerts   = NocEvent::whereIn('status', ['open', 'acknowledged'])
            ->where(function ($q) use ($branch) {
                $q->whereHas('branch', fn($bq) => $bq->where('id', $branch->id));
            })
            ->orderByDesc('last_seen')->limit(5)->get();
        $openIncidents = \App\Models\Incident::where('branch_id', $branch->id)
            ->whereIn('status', ['open', 'investigating'])
            ->latest()->limit(5)->get();

        // DHCP + IPAM + Sophos for this branch
        $dhcpLeases       = \App\Models\DhcpLease::where('branch_id', $branch->id)->latest('last_seen')->limit(10)->get();
        $subnets          = \App\Models\IpamSubnet::where('branch_id', $branch->id)->get();
        $sophosFirewalls  = \App\Models\SophosFirewall::where('branch_id', $branch->id)->get();
        $sophosVpnTunnels = \App\Models\SophosVpnTunnel::whereIn('firewall_id', $sophosFirewalls->pluck('id'))->get();

        return view('admin.noc.branch', compact(
            'branch', 'score', 'switches', 'devices', 'printers',
            'vpnTunnels', 'ispConns', 'monitorHosts', 'landlines',
            'ipCount', 'employees', 'openAlerts', 'openIncidents',
            'dhcpLeases', 'subnets', 'sophosFirewalls', 'sophosVpnTunnels'
        ));
    }

    public function events(Request $request)
    {
        $query = NocEvent::orderByDesc('last_seen');

        if ($request->filled('module')) {
            $query->where('module', $request->module);
        }
        if ($request->filled('severity')) {
            $query->where('severity', $request->severity);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            $query->whereIn('status', ['open', 'acknowledged']);
        }

        $events = $query->paginate(25)->withQueryString();

        return view('admin.noc.events', compact('events'));
    }

    public function acknowledge(NocEvent $event)
    {
        $event->update([
            'status'         => 'acknowledged',
            'acknowledged_by' => Auth::id(),
        ]);

        return back()->with('success', 'Event acknowledged.');
    }

    public function resolve(NocEvent $event)
    {
        $event->update([
            'status'      => 'resolved',
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Event resolved.');
    }

    // ── Extensions Page ─────────────────────────────────────────────

    public function extensionsPage()
    {
        return view('admin.noc.extensions');
    }

    // ── Extension Grid (AJAX) ────────────────────────────────────────

    public function extensionGrid()
    {
        $extensions = UcmExtensionCache::with('ucmServer')
            ->orderBy('extension')
            ->get();

        $portMaps = PhonePortMap::all()->keyBy(fn ($m) => $m->ucm_server_id . '-' . $m->extension);

        // Build a normalized set of known WiFi MACs for phones (for WiFi detection)
        $wifiMacs = \App\Models\Device::where('type', 'phone')
            ->whereNotNull('wifi_mac')
            ->pluck('wifi_mac')
            ->map(fn ($m) => strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m)))
            ->flip()
            ->toArray();

        $data = $extensions->map(function ($ext) use ($portMaps, $wifiMacs) {
            $key = $ext->ucm_id . '-' . $ext->extension;
            $map = $portMaps[$key] ?? null;

            $phoneMac = $map?->phone_mac ?: '-';
            $macClean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $phoneMac));
            $isWifi   = ($macClean && isset($wifiMacs[$macClean]));

            return [
                'extension'    => $ext->extension,
                'name'         => $ext->name ?: '-',
                'status'       => $ext->status,
                'status_badge' => $ext->statusBadgeClass(),
                'ip'           => $ext->ip_address ?: '-',
                'switch_name'  => $map?->switch_name ?: '-',
                'switch_port'  => $map?->switch_port ? 'Port ' . $map->switch_port : '-',
                'location'     => $map?->locationLabel() ?: '-',
                'vlan'         => $map?->vlan ?: '-',
                'mac'          => $phoneMac,
                'wifi'         => $isWifi,
                'server'       => $ext->ucmServer?->name ?: '-',
            ];
        });

        $activeCalls = UcmActiveCall::with('ucmServer')->orderByDesc('start_time')->get()->map(fn ($c) => [
            'caller'   => $c->caller,
            'callee'   => $c->callee,
            'duration' => $c->durationFormatted(),
            'server'   => $c->ucmServer?->name ?: '-',
        ]);

        return response()->json([
            'extensions'   => $data,
            'active_calls' => $activeCalls,
        ]);
    }

    // ── Wallboard ────────────────────────────────────────────────────

    public function wallboard()
    {
        return view('admin.noc.wallboard', $this->getWallboardData());
    }

    public function wallboardData()
    {
        return response()->json($this->getWallboardData());
    }

    private function getWallboardData(): array
    {
        // Global stats
        $totalSwitches   = NetworkSwitch::count();
        $onlineSwitches  = NetworkSwitch::where('status', 'online')->count();
        $totalExtensions = UcmExtensionCache::count();
        $registeredExt   = UcmExtensionCache::whereIn('status', ['idle', 'inuse', 'busy', 'ringing'])->count();
        $activeCalls     = UcmActiveCall::count();
        $vpnUp           = \App\Models\VpnTunnel::where('status', 'up')->count();
        $vpnTotal        = \App\Models\VpnTunnel::count();
        $openAlerts      = NocEvent::open()->count();
        $criticalAlerts  = NocEvent::open()->where('severity', 'critical')->count();

        // Extensions with port mapping
        $extensions = UcmExtensionCache::orderBy('extension')->get();
        $portMaps = PhonePortMap::all()->keyBy(fn ($m) => $m->ucm_server_id . '-' . $m->extension);

        $extensionGrid = $extensions->map(function ($ext) use ($portMaps) {
            $key = $ext->ucm_id . '-' . $ext->extension;
            $map = $portMaps[$key] ?? null;
            return [
                'extension'    => $ext->extension,
                'name'         => $ext->name ?: '-',
                'status'       => $ext->status,
                'status_badge' => $ext->statusBadgeClass(),
                'location'     => $map?->locationLabel() ?: '-',
            ];
        });

        // Active calls
        $calls = UcmActiveCall::orderByDesc('start_time')->get()->map(fn ($c) => [
            'caller'   => $c->caller,
            'callee'   => $c->callee,
            'duration' => $c->durationFormatted(),
        ]);

        // Trunks
        $trunks = UcmTrunkCache::orderBy('trunk_name')->get()->map(fn ($t) => [
            'name'         => $t->trunk_name,
            'host'         => $t->host ?: '-',
            'status'       => $t->status,
            'status_badge' => $t->statusBadgeClass(),
        ]);

        // Switches
        $switches = NetworkSwitch::orderBy('name')->get()->map(fn ($s) => [
            'name'   => $s->name,
            'status' => $s->status,
            'ip'     => $s->lan_ip ?: '-',
        ]);

        // Recent alerts
        $alerts = NocEvent::open()->orderByDesc('severity')->orderByDesc('last_seen')->limit(10)->get()->map(fn ($e) => [
            'title'    => $e->title,
            'severity' => $e->severity,
            'module'   => $e->module,
            'time'     => $e->last_seen?->diffForHumans() ?: '-',
        ]);

        return [
            'stats' => [
                'switches_online' => "{$onlineSwitches}/{$totalSwitches}",
                'extensions'      => "{$registeredExt}/{$totalExtensions}",
                'active_calls'    => $activeCalls,
                'vpn'             => "{$vpnUp}/{$vpnTotal}",
                'alerts'          => $openAlerts,
                'critical'        => $criticalAlerts,
            ],
            'extension_grid' => $extensionGrid,
            'active_calls'   => $calls,
            'trunks'         => $trunks,
            'switches'       => $switches,
            'alerts'         => $alerts,
        ];
    }
}
