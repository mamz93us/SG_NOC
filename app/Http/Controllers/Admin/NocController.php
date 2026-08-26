<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPrinterAlertEmailJob;
use App\Models\ActivityLog;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IdentityUser;
use App\Models\NetworkSwitch;
use App\Models\NocEvent;
use App\Models\PhonePortMap;
use App\Models\Printer;
use App\Models\UcmActiveCall;
use App\Models\UcmExtensionCache;
use App\Models\UcmServer;
use App\Models\UcmTrunkCache;
use App\Services\HealthScoringService;
use App\Services\NotificationService;
use App\Services\Sophos\SophosVpnBoard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class NocController extends Controller
{
    public function __construct(
        private HealthScoringService $health,
        private SophosVpnBoard $vpnBoard,
    ) {}

    public function dashboard()
    {
        // ── FAST QUERIES ONLY ──────────────────────────────────────────
        // Heavy sections (UCM, Branch Health, VPN details, Sophos VPN)
        // are loaded via AJAX from dashboardHeavyData() to speed up initial paint.

        // Global Identity summary (3 fast COUNT queries)
        $identityQuery = IdentityUser::query();
        $allowedDomains = \App\Models\AllowedDomain::getList();
        if (! empty($allowedDomains)) {
            $identityQuery->where(function ($q) use ($allowedDomains) {
                foreach ($allowedDomains as $domain) {
                    $q->orWhere('user_principal_name', 'like', "%@{$domain}");
                }
            });
        }
        $totalUsers = (clone $identityQuery)->count();
        $licensedUsers = (clone $identityQuery)->whereNotNull('assigned_licenses')->where('assigned_licenses', '!=', '[]')->where('assigned_licenses', '!=', 'null')->count();
        $licensedPercent = $totalUsers > 0 ? (int) round($licensedUsers / $totalUsers * 100) : 0;

        // Network summary (2 COUNT queries)
        $totalSwitches = NetworkSwitch::count();
        $onlineSwitches = NetworkSwitch::where('status', 'online')->count();
        $onlinePercent = $totalSwitches > 0 ? (int) round($onlineSwitches / $totalSwitches * 100) : 0;

        // Assets summary (2 COUNT queries + 1 subquery)
        $totalDevices = Device::count();
        $missingCreds = Device::whereDoesntHave('credentials')->count();
        $printersOverdue = Printer::whereNotNull('service_interval_days')
            ->where('service_interval_days', '>', 0)
            ->whereRaw('DATE_ADD(COALESCE(last_service_date, created_at), INTERVAL service_interval_days DAY) < NOW()')
            ->count();

        // Phones & Contacts (3 COUNT queries)
        $contactCount = \App\Models\Contact::count();
        $phoneRequestCount = \App\Models\PhoneRequestLog::distinct('mac')->whereNotNull('mac')->count();
        $totalXmlRequests = \App\Models\PhoneRequestLog::count();

        // VoIP quick counts from cache tables (fast COUNTs — no API calls)
        $totalExt = UcmExtensionCache::count();
        $totalIdle = UcmExtensionCache::where('status', 'idle')->count();
        $totalInUse = UcmExtensionCache::whereIn('status', ['inuse', 'busy', 'ringing'])->count();

        // VPN / Hosts summary (4 COUNT queries — no ::all())
        // Source of truth is the Branch Tunnel Watchdog; "online" means fully up,
        // so a tunnel that is passing traffic but missing a subnet is not counted.
        $vpnTotal = \App\Models\BranchTunnel::active()->count();
        $vpnOnline = \App\Models\BranchTunnel::active()->where('state', \App\Models\BranchTunnel::STATE_UP)->count();
        $hostsUp = \App\Models\MonitoredHost::where('status', 'up')->count();
        $hostsDown = \App\Models\MonitoredHost::where('status', 'down')->count();

        // DHCP (3 COUNT queries)
        $dhcpTotal = \App\Models\DhcpLease::count();
        $dhcpConflicts = \App\Models\DhcpLease::where('is_conflict', true)->count();
        $dhcpBySource = \App\Models\DhcpLease::selectRaw('source, count(*) as cnt')->groupBy('source')->pluck('cnt', 'source')->toArray();

        // Sophos summary (COUNT queries only)
        $sophosTotal = \App\Models\SophosFirewall::count();
        $sophosSynced = \App\Models\SophosFirewall::whereNotNull('last_synced_at')->count();
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

    public function dashboardHeavyData(Request $request)
    {
        // UCM PBX Stats (the N+1 loop that was slowing the page)
        $ucmServers = \App\Models\UcmServer::orderBy('name')->get();
        $ucmStats = $ucmServers->map(function ($server) {
            $stats = \App\Services\IppbxApiService::getCachedStats($server);

            return [
                'name' => $server->name,
                'host' => parse_url($server->url, PHP_URL_HOST) ?? $server->url,
                'online' => $stats['online'] ?? false,
                'uptime' => $stats['uptime'] ?? null,
                'error' => $stats['error'] ?? null,
                'model' => $stats['model'] ?? null,
                'firmware' => $stats['firmware'] ?? null,
                'ext' => $stats['extensions'] ?? [],
                'trunks' => $stats['trunk_counts'] ?? [],
            ];
        });

        // Branch Health — one bounded set of queries for the whole estate, not a
        // fan-out per branch. Worst-first so whatever needs attention is on top.
        //
        // Lightweight summaries only: per-check detail is an order of magnitude
        // more payload and is only ever read on the drill-down, which fetches it
        // itself. ->values() re-indexes because PHP encodes sparse-keyed arrays
        // as JSON objects, which breaks .map() in the dashboard JS.
        $branches = $this->health->allBranches()->map(function ($b) {
            $h = $b->health;

            return [
                'id' => $b->id,
                'name' => $b->name,
                'url' => route('admin.noc.branch', $b->id),
                'total' => $h['total'],
                'raw_total' => $h['raw_total'],
                'coverage_percent' => $h['coverage_percent'],
                'normalized_percent' => $h['normalized_percent'],
                'status' => $h['status'],
                'status_label' => HealthScoringService::statusLabel($h['status']),
                'status_color' => HealthScoringService::statusColor($h['status']),
                'capped' => $h['cap_reasons'] !== [],
                'cap_reasons' => array_map(fn ($r) => $r['message'], $h['cap_reasons']),
                'categories' => collect($h['categories'])->map(fn ($c) => [
                    'key' => $c['key'],
                    'label' => $c['label'],
                    'points' => $c['points'],
                    'max_points' => $c['max_points'],
                    'percent' => $c['percent'],
                    // Coloured on the category's own 0-100 percentage. Passing raw
                    // points would paint a perfect 15/15 Devices score red.
                    'color' => HealthScoringService::healthColorStatic($c['percent']),
                ])->values(),
            ];
        })->values();

        // VPN Tunnel Details — from the Branch Tunnel Watchdog. `state` carries
        // "degraded" (gateway up, a carried subnet unreachable) alongside up/down;
        // the panel colours that amber rather than calling it up.
        // Worst-first, so whatever needs attention is at the top of the panel.
        $vpnStateRank = ['down' => 0, 'degraded' => 1, 'unknown' => 2, 'up' => 3];
        $vpnTunnels = \App\Models\BranchTunnel::active()->with('branch')->ordered()->get()
            ->sortBy(fn ($t) => $vpnStateRank[$t->state] ?? 2)
            ->values()
            ->map(fn ($t) => [
                'name' => $t->name,
                'status' => $t->state,
                'detail' => $t->firewall_ip,
                'branch' => $t->branch?->name ?: 'No branch',
            ]);

        // Sophos S2S VPN Tunnels.
        //
        // Assembled by SophosVpnBoard, which pairs each tunnel's "Connection"
        // sensor with its "Active" one. Reading Connection alone -- as this used
        // to -- rendered a tunnel deliberately switched off on the firewall as a
        // red "down", indistinguishable from one that had failed. Retired
        // sensors (tunnels deleted from the firewall) are hidden unless asked
        // for, and an operator can mute a tunnel from the panel.
        $showRetired = $request->boolean('show_retired');
        $sophosVpnTunnels = $this->vpnBoard->tunnels(includeRetired: $showRetired);

        return response()->json([
            'ucm_stats' => $ucmStats,
            'branches' => $branches,
            'vpn_tunnels' => $vpnTunnels,
            'vpn_summary' => [
                'up' => $vpnTunnels->where('status', 'up')->count(),
                'degraded' => $vpnTunnels->where('status', 'degraded')->count(),
                'down' => $vpnTunnels->where('status', 'down')->count(),
            ],
            'sophos_vpn_tunnels' => $sophosVpnTunnels,
            'sophos_vpn_summary' => $this->vpnBoard->summary($sophosVpnTunnels) + [
                // So the panel can offer "show N retired" without a second call.
                'retired_hidden' => $showRetired ? 0 : $this->vpnBoard->retiredCount(),
                'showing_retired' => $showRetired,
            ],
        ]);
    }

    public function branch(Branch $branch)
    {
        $score = $this->health->scoreForBranch($branch);
        $switches = NetworkSwitch::where('branch_id', $branch->id)->get();
        $devices = Device::where('branch_id', $branch->id)->with('credentials')->get();
        $printers = Printer::where('branch_id', $branch->id)->with('supplies')->get();

        // Branch tunnels, from the watchdog. This card used to read VpnTunnel,
        // whose own docblock says the model is vestigial and its table empty in
        // production — so it was permanently blank while the dashboard beside it
        // showed the real thing.
        $branchTunnels = \App\Models\BranchTunnel::where('branch_id', $branch->id)
            ->with('activeProbes')->ordered()->get();
        $ispConns = \App\Models\IspConnection::where('branch_id', $branch->id)->get();
        $monitorHosts = \App\Models\MonitoredHost::where('branch_id', $branch->id)->get();
        $landlines = \App\Models\Landline::where('branch_id', $branch->id)->get();
        $ipCount = \App\Models\IpReservation::where('branch_id', $branch->id)->count();
        $employees = \App\Models\Employee::where('branch_id', $branch->id)->get();
        $accessPoints = \App\Models\AccessPoint::where('branch_id', $branch->id)
            ->orderBy('name')->get();

        // Indexed on branch_id now. The previous whereHas('branch', ...) called a
        // relation NocEvent did not have and threw on every render of this page.
        $openAlerts = NocEvent::whereIn('status', ['open', 'acknowledged'])
            ->where('branch_id', $branch->id)
            ->orderByDesc('last_seen')->limit(5)->get();
        $openIncidents = \App\Models\Incident::where('branch_id', $branch->id)
            ->whereIn('status', ['open', 'investigating'])
            ->latest()->limit(5)->get();

        // DHCP + IPAM + Sophos for this branch
        $dhcpLeases = \App\Models\DhcpLease::where('branch_id', $branch->id)->latest('last_seen')->limit(10)->get();
        $subnets = \App\Models\IpamSubnet::where('branch_id', $branch->id)->get();
        $sophosFirewalls = \App\Models\SophosFirewall::where('branch_id', $branch->id)->get();
        $sophosVpnTunnels = \App\Models\SophosVpnTunnel::whereIn('firewall_id', $sophosFirewalls->pluck('id'))->get();

        return view('admin.noc.branch', compact(
            'branch', 'score', 'switches', 'devices', 'printers',
            'branchTunnels', 'ispConns', 'monitorHosts', 'landlines',
            'ipCount', 'employees', 'accessPoints', 'openAlerts', 'openIncidents',
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
            'status' => 'acknowledged',
            'acknowledged_by' => Auth::id(),
        ]);

        return back()->with('success', 'Event acknowledged.');
    }

    public function resolve(NocEvent $event)
    {
        $event->update([
            'status' => 'resolved',
            'resolved_by' => Auth::id(),
            'resolved_at' => now(),
        ]);

        return back()->with('success', 'Event resolved.');
    }

    /**
     * Manually re-send the notification(s) associated with an event.
     * Cooldown is intentionally bypassed — the admin clicked Resend on purpose.
     */
    public function resend(NocEvent $event, NotificationService $notifications)
    {
        if ($event->source_type === 'printer') {
            // Clear the idempotency stamp so SendPrinterAlertEmailJob will re-send.
            $event->update(['email_sent_at' => null]);
            SendPrinterAlertEmailJob::dispatch($event->id);
        } else {
            $type = match ($event->module) {
                'printers' => 'cups_printer_offline',
                'network' => 'noc_alert',
                'voip' => 'noc_alert',
                default => $event->module.'_alert',
            };

            $notifications->notifyViaRules(
                type: $type,
                title: $event->title,
                message: $event->message,
                link: null,
                severity: $event->severity,
                skipCooldown: true,
            );

            $event->update(['email_sent_at' => now()]);
        }

        ActivityLog::log('noc_event.resend', $event, ['resent_by' => Auth::id()]);

        return back()->with('success', 'Alert resent.');
    }

    // ── Extensions Page ─────────────────────────────────────────────

    public function extensionsPage()
    {
        return view('admin.noc.extensions');
    }

    // ── Extension Grid (AJAX) ────────────────────────────────────────

    public function extensionGrid()
    {
        // Memory-scoped version. The full switch_cdp_neighbors table can be tens
        // of thousands of rows, and even cursor() buffers the whole result set at
        // the mysqlnd driver level — which blew the 128M FPM memory_limit here
        // (Connection.php:492). Fix: load the ~few-hundred rendered extensions
        // first, then pull ONLY the CDP rows matching their IPs/MACs instead of
        // the entire table.

        // {ucm_id => name} — replaces the per-extension ->ucmServer eager load.
        $ucmNames = UcmServer::pluck('name', 'id')->all();

        // Phone-port map keyed by "{ucm_id}-{extension}".
        $portMaps = [];
        DB::table('phone_port_map')
            ->select('ucm_server_id', 'extension', 'phone_mac', 'switch_name', 'switch_port', 'vlan')
            ->orderBy('id')
            ->cursor()
            ->each(function ($m) use (&$portMaps) {
                $portMaps[$m->ucm_server_id.'-'.$m->extension] = $m;
            });

        // Extensions we actually render — small set (a few hundred). Loaded up
        // front so the heavy CDP lookup can be scoped to just these phones.
        $extensions = UcmExtensionCache::orderBy('extension')->get();

        // Candidate IPs + cleaned phone MACs for those extensions.
        $lookupIps = [];
        $lookupMacs = [];
        foreach ($extensions as $ext) {
            if ($ext->ip_address) {
                $lookupIps[$ext->ip_address] = true;
            }
            $map = $portMaps[$ext->ucm_id.'-'.$ext->extension] ?? null;
            $mc = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $map->phone_mac ?? ''));
            if ($mc !== '') {
                $lookupMacs[$mc] = true;
            }
        }

        // Set of WiFi MACs (uppercased, hex-only) used by registered phones.
        $wifiMacs = [];
        DB::table('devices')
            ->where('type', 'phone')
            ->whereNotNull('wifi_mac')
            ->pluck('wifi_mac')
            ->each(function ($m) use (&$wifiMacs) {
                $clean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $m));
                if ($clean !== '') {
                    $wifiMacs[$clean] = true;
                }
            });

        // CDP neighbor index — scoped to just our phones' IPs/MACs so we never
        // buffer the whole switch_cdp_neighbors table. neighbor_mac is normalized
        // in SQL to the hex-only form we compare against. Lookup tries IP, then MAC.
        $cdpByIp = [];
        $cdpByMac = [];
        if ($lookupIps || $lookupMacs) {
            DB::table('switch_cdp_neighbors')
                ->select('device_name', 'device_ip', 'local_interface', 'neighbor_ip', 'neighbor_mac', 'platform')
                ->where(function ($q) use ($lookupIps, $lookupMacs) {
                    if ($lookupIps) {
                        $q->orWhereIn('neighbor_ip', array_keys($lookupIps));
                    }
                    if ($lookupMacs) {
                        $q->orWhereIn(
                            DB::raw("UPPER(REPLACE(REPLACE(REPLACE(COALESCE(neighbor_mac,''),':',''),'.',''),'-',''))"),
                            array_keys($lookupMacs)
                        );
                    }
                })
                ->orderBy('id')
                ->cursor()
                ->each(function ($n) use (&$cdpByIp, &$cdpByMac) {
                    if ($n->neighbor_ip) {
                        $cdpByIp[$n->neighbor_ip] = $n;
                    }
                    if ($n->neighbor_mac) {
                        $clean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $n->neighbor_mac));
                        if ($clean !== '') {
                            $cdpByMac[$clean] = $n;
                        }
                    }
                });
        }

        // Main extension loop — over the already-loaded (small) collection.
        $data = [];
        foreach ($extensions as $ext) {
            $key = $ext->ucm_id.'-'.$ext->extension;
            $map = $portMaps[$key] ?? null;

            $phoneMac = $map->phone_mac ?? '-';
            $macClean = strtoupper(preg_replace('/[^a-fA-F0-9]/', '', $phoneMac));
            $isWifi = ($macClean !== '' && isset($wifiMacs[$macClean]));

            // Prefer PhonePortMap when it has a port (carries VLAN/location);
            // fall back to CDP neighbor lookup by IP, then MAC.
            $switchName = $map->switch_name ?? null;
            $switchPort = ($map && $map->switch_port) ? 'Port '.$map->switch_port : null;
            $portSource = $switchPort ? 'meraki' : null;

            if (! $switchPort) {
                $cdp = ($ext->ip_address && isset($cdpByIp[$ext->ip_address]))
                    ? $cdpByIp[$ext->ip_address]
                    : (($macClean !== '' && isset($cdpByMac[$macClean])) ? $cdpByMac[$macClean] : null);
                if ($cdp) {
                    $switchName = $switchName ?: $cdp->device_name;
                    $switchPort = $cdp->local_interface;
                    $portSource = 'cdp';
                }
            }

            $location = ($map && $map->switch_name && $map->switch_port)
                ? "{$map->switch_name} / Port {$map->switch_port}"
                : '-';

            $data[] = [
                'extension' => $ext->extension,
                'name' => $ext->name ?: '-',
                'status' => $ext->status,
                'status_badge' => $ext->statusBadgeClass(),
                'ip' => $ext->ip_address ?: '-',
                'switch_name' => $switchName ?: '-',
                'switch_port' => $switchPort ?: '-',
                'port_source' => $portSource,
                'location' => $location,
                'vlan' => $map->vlan ?? '-',
                'mac' => $phoneMac,
                'wifi' => $isWifi,
                'server' => $ucmNames[$ext->ucm_id] ?? '-',
            ];
        }

        // Active calls — small table, but join the server name in SQL so we
        // don't re-instantiate UcmServer eager-loads per row.
        $activeCalls = DB::table('ucm_active_calls as c')
            ->leftJoin('ucm_servers as s', 'c.ucm_id', '=', 's.id')
            ->select('c.caller', 'c.callee', 'c.start_time', 's.name as server_name')
            ->orderByDesc('c.start_time')
            ->get()
            ->map(function ($c) {
                $start = $c->start_time ? \Carbon\Carbon::parse($c->start_time) : null;
                $duration = $start ? gmdate('H:i:s', max(0, now()->diffInSeconds($start))) : '-';

                return [
                    'caller' => $c->caller,
                    'callee' => $c->callee,
                    'duration' => $duration,
                    'server' => $c->server_name ?: '-',
                ];
            });

        return response()->json([
            'extensions' => $data,
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
        $totalSwitches = NetworkSwitch::count();
        $onlineSwitches = NetworkSwitch::where('status', 'online')->count();
        $totalExtensions = UcmExtensionCache::count();
        $registeredExt = UcmExtensionCache::whereIn('status', ['idle', 'inuse', 'busy', 'ringing'])->count();
        $activeCalls = UcmActiveCall::count();
        $vpnUp = \App\Models\BranchTunnel::active()->where('state', \App\Models\BranchTunnel::STATE_UP)->count();
        $vpnTotal = \App\Models\BranchTunnel::active()->count();
        $openAlerts = NocEvent::open()->count();
        $criticalAlerts = NocEvent::open()->where('severity', 'critical')->count();

        // Extensions with port mapping
        $extensions = UcmExtensionCache::orderBy('extension')->get();
        $portMaps = PhonePortMap::all()->keyBy(fn ($m) => $m->ucm_server_id.'-'.$m->extension);

        $extensionGrid = $extensions->map(function ($ext) use ($portMaps) {
            $key = $ext->ucm_id.'-'.$ext->extension;
            $map = $portMaps[$key] ?? null;

            return [
                'extension' => $ext->extension,
                'name' => $ext->name ?: '-',
                'status' => $ext->status,
                'status_badge' => $ext->statusBadgeClass(),
                'location' => $map?->locationLabel() ?: '-',
            ];
        });

        // Active calls
        $calls = UcmActiveCall::orderByDesc('start_time')->get()->map(fn ($c) => [
            'caller' => $c->caller,
            'callee' => $c->callee,
            'duration' => $c->durationFormatted(),
        ]);

        // Trunks
        $trunks = UcmTrunkCache::orderBy('trunk_name')->get()->map(fn ($t) => [
            'name' => $t->trunk_name,
            'host' => $t->host ?: '-',
            'status' => $t->status,
            'status_badge' => $t->statusBadgeClass(),
        ]);

        // Switches
        $switches = NetworkSwitch::orderBy('name')->get()->map(fn ($s) => [
            'name' => $s->name,
            'status' => $s->status,
            'ip' => $s->lan_ip ?: '-',
        ]);

        // Recent alerts
        $alerts = NocEvent::open()->orderByDesc('severity')->orderByDesc('last_seen')->limit(10)->get()->map(fn ($e) => [
            'title' => $e->title,
            'severity' => $e->severity,
            'module' => $e->module,
            'time' => $e->last_seen?->diffForHumans() ?: '-',
        ]);

        return [
            'stats' => [
                'switches_online' => "{$onlineSwitches}/{$totalSwitches}",
                'extensions' => "{$registeredExt}/{$totalExtensions}",
                'active_calls' => $activeCalls,
                'vpn' => "{$vpnUp}/{$vpnTotal}",
                'alerts' => $openAlerts,
                'critical' => $criticalAlerts,
            ],
            'extension_grid' => $extensionGrid,
            'active_calls' => $calls,
            'trunks' => $trunks,
            'switches' => $switches,
            'alerts' => $alerts,
        ];
    }
}
