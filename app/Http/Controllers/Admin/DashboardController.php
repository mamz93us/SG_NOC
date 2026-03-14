<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\PhoneRequestLog;
use App\Models\Setting;
use App\Models\AdminLink;
use App\Models\AdminLinkClick;
use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    public function index()
    {
        // ── Simple DB counts (instant) ────────────────────────────────────
        $branchCount       = Branch::count();
        $contactCount      = Contact::count();
        $phoneRequestCount = PhoneRequestLog::distinct('mac')->whereNotNull('mac')->count();
        $totalXmlRequests  = PhoneRequestLog::count();

        // ── UCM live stats (cached 5 min per server) ──────────────────────
        $ucmServers = UcmServer::orderBy('name')->get();
        $ucmStats   = [];

        foreach ($ucmServers as $server) {
            $cacheKey = "dashboard_ucm_{$server->id}_" . md5($server->url . $server->api_username);

            if (!$server->is_active) {
                $ucmStats[] = [
                    'server' => $server,
                    'stats'  => ['online' => false, 'error' => 'Server is disabled'],
                ];
                continue;
            }

            $stats = IppbxApiService::getCachedStats($server);

            $ucmStats[] = ['server' => $server, 'stats' => $stats];
        }

        // ── Aggregate UCM totals ───────────────────────────────────────────
        $ucmOnline         = collect($ucmStats)->where('stats.online', true)->count();
        $totalExt          = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['total']        ?? 0);
        $totalIdle         = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['idle']         ?? 0);
        $totalInUse        = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['inuse']        ?? 0);
        $totalUnavail      = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['unavailable']  ?? 0);
        $totalTrunks       = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['total']      ?? 0);
        $totalReachable    = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['reachable']  ?? 0);
        $totalUnreachable  = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['unreachable']?? 0);

        $settings = Setting::get();

        // ── Quick Admin Links (top 5 most clicked) ──────────────────────
        $quickAdminLinks = AdminLinkClick::selectRaw('link_id, COUNT(*) as click_count')
            ->groupBy('link_id')
            ->orderByDesc('click_count')
            ->limit(5)
            ->with('link')
            ->get()
            ->filter(fn ($c) => $c->link && $c->link->is_active)
            ->map(fn ($c) => $c->link);

        if ($quickAdminLinks->isEmpty()) {
            $quickAdminLinks = AdminLink::active()->orderBy('sort_order')->limit(5)->get();
        }

        return view('admin.dashboard', compact(
            'branchCount',
            'contactCount',
            'phoneRequestCount',
            'totalXmlRequests',
            'ucmStats',
            'ucmOnline',
            'totalExt',
            'totalIdle',
            'totalInUse',
            'totalUnavail',
            'totalTrunks',
            'totalReachable',
            'totalUnreachable',
            'settings',
            'quickAdminLinks'
        ));
    }
}
