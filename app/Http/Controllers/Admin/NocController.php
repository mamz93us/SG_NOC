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
        // Global Identity summary
        $identityQuery = IdentityUser::query();
        
        $allowedDomains = \App\Models\AllowedDomain::getList();
        if (!empty($allowedDomains)) {
            $identityQuery->where(function ($q) use ($allowedDomains) {
                foreach ($allowedDomains as $domain) {
                    $q->orWhere('user_principal_name', 'like', "%@{$domain}");
                }
            });
        }

        $totalUsers       = (clone $identityQuery)->count();
        $licensedUsers    = (clone $identityQuery)->whereNotNull('assigned_licenses')->where('assigned_licenses', '!=', '[]')->where('assigned_licenses', '!=', 'null')->count();
        $disabledUsers    = (clone $identityQuery)->where('account_enabled', false)->count();
        $licensedPercent  = $totalUsers > 0 ? (int) round($licensedUsers / $totalUsers * 100) : 0;

        // Global Network summary
        $totalSwitches  = NetworkSwitch::count();
        $onlineSwitches = NetworkSwitch::where('status', 'online')->count();
        $onlinePercent  = $totalSwitches > 0 ? (int) round($onlineSwitches / $totalSwitches * 100) : 0;

        // Global Assets summary
        $totalDevices     = Device::count();
        $assignedDevices  = Device::where('status', 'assigned')->count();
        $missingCreds     = Device::whereDoesntHave('credentials')->count();

        // Printers overdue service
        $printersOverdue = Printer::all()->filter(fn ($p) => $p->isMaintenanceDue())->count();

        // Branch health scores
        $branches = $this->health->allBranches();

        // Open NOC events
        $openEvents = NocEvent::open()->orderByDesc('severity')->orderByDesc('last_seen')->limit(10)->get();

        // ── Main Dashboard Metrics Merged ────────────────────────────────

        // Phones & Contacts
        $contactCount      = \App\Models\Contact::count();
        $phoneRequestCount = \App\Models\PhoneRequestLog::distinct('mac')->whereNotNull('mac')->count();
        $totalXmlRequests  = \App\Models\PhoneRequestLog::count();

        // UCM Stats (Cached)
        $ucmServers = \App\Models\UcmServer::orderBy('name')->get();
        $ucmStats   = [];
        foreach ($ucmServers as $server) {
            $stats = \App\Services\IppbxApiService::getCachedStats($server);
            $ucmStats[] = ['server' => $server, 'stats' => $stats];
        }

        $ucmOnline         = collect($ucmStats)->where('stats.online', true)->count();
        $totalExt          = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['total']        ?? 0);
        $totalIdle         = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['idle']         ?? 0);
        $totalInUse        = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['inuse']        ?? 0);
        $totalUnavail      = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['unavailable']  ?? 0);
        $totalTrunks       = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['total']      ?? 0);
        $totalReachable    = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['reachable']  ?? 0);
        $totalUnreachable  = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['unreachable']?? 0);

        // VPN Status
        $vpnTunnels = \App\Models\VpnTunnel::all();
        $vpnOnline = $vpnTunnels->where('status', 'up')->count();

        // Monitored Hosts
        $monitoredHosts = \App\Models\MonitoredHost::all();
        $hostsUp = $monitoredHosts->where('status', 'up')->count();
        $hostsDown = $monitoredHosts->where('status', 'down')->count();

        // DHCP Lease Overview
        $dhcpTotal     = \App\Models\DhcpLease::count();
        $dhcpConflicts = \App\Models\DhcpLease::where('is_conflict', true)->count();
        $dhcpBySource  = \App\Models\DhcpLease::selectRaw("source, count(*) as cnt")->groupBy('source')->pluck('cnt', 'source')->toArray();

        // Sophos Firewall Overview
        $sophosAll    = \App\Models\SophosFirewall::all();
        $sophosTotal  = $sophosAll->count();
        $sophosSynced = $sophosAll->filter(fn($f) => $f->last_synced_at !== null)->count();
        $sophosVpnTunnels = \App\Models\SophosVpnTunnel::with('firewall.branch')->get();
        $sophosVpnUp  = $sophosVpnTunnels->where('status', 'up')->count();

        // Top Subnets by Utilization
        $topSubnets = \App\Models\IpamSubnet::withCount(['ipReservations', 'dhcpLeases'])
            ->orderByDesc('ip_reservations_count')
            ->limit(5)
            ->get();

        return view('admin.noc.dashboard', compact(
            'totalUsers', 'licensedUsers', 'disabledUsers', 'licensedPercent',
            'totalSwitches', 'onlineSwitches', 'onlinePercent',
            'totalDevices', 'assignedDevices', 'missingCreds', 'printersOverdue',
            'branches', 'openEvents',
            'contactCount', 'phoneRequestCount', 'totalXmlRequests',
            'ucmStats', 'ucmOnline', 'totalExt', 'totalIdle', 'totalInUse', 'totalUnavail', 'totalTrunks', 'totalReachable', 'totalUnreachable',
            'vpnTunnels', 'vpnOnline',
            'monitoredHosts', 'hostsUp', 'hostsDown',
            'dhcpTotal', 'dhcpConflicts', 'dhcpBySource',
            'sophosTotal', 'sophosSynced', 'sophosVpnUp', 'sophosVpnTunnels',
            'topSubnets'
        ));
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

    // ── Extension Grid (AJAX) ────────────────────────────────────────

    public function extensionGrid()
    {
        $extensions = UcmExtensionCache::with('ucmServer')
            ->orderBy('extension')
            ->get();

        $portMaps = PhonePortMap::all()->keyBy(fn ($m) => $m->ucm_server_id . '-' . $m->extension);

        $data = $extensions->map(function ($ext) use ($portMaps) {
            $key = $ext->ucm_id . '-' . $ext->extension;
            $map = $portMaps[$key] ?? null;

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
                'mac'          => $map?->phone_mac ?: '-',
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
