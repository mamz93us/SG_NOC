<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Device;
use App\Models\IdentityUser;
use App\Models\NetworkSwitch;
use App\Models\NocEvent;
use App\Models\Printer;
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

        return view('admin.noc.dashboard', compact(
            'totalUsers', 'licensedUsers', 'disabledUsers', 'licensedPercent',
            'totalSwitches', 'onlineSwitches', 'onlinePercent',
            'totalDevices', 'assignedDevices', 'missingCreds', 'printersOverdue',
            'branches', 'openEvents',
            'contactCount', 'phoneRequestCount', 'totalXmlRequests',
            'ucmStats', 'ucmOnline', 'totalExt', 'totalIdle', 'totalInUse', 'totalUnavail', 'totalTrunks', 'totalReachable', 'totalUnreachable',
            'vpnTunnels', 'vpnOnline',
            'monitoredHosts', 'hostsUp', 'hostsDown'
        ));
    }

    public function branch(Branch $branch)
    {
        $score   = $this->health->scoreForBranch($branch->id);
        $switches = NetworkSwitch::where('branch_id', $branch->id)->get();
        $devices  = Device::where('branch_id', $branch->id)->with('credentials')->get();
        $printers = Printer::where('branch_id', $branch->id)->get();

        return view('admin.noc.branch', compact('branch', 'score', 'switches', 'devices', 'printers'));
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
}
