<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\AdminLink;
use App\Models\AdminLinkClick;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FormSubmission;
use App\Models\IdentitySyncLog;
use App\Models\Incident;
use App\Models\IspConnection;
use App\Models\LinkCheck;
use App\Models\NocEvent;
use App\Models\PhoneRequestLog;
use App\Models\Setting;
use App\Models\UcmServer;
use App\Services\IppbxApiService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Welcome screen — personalised landing page after admin login.
     * Hybrid modular grid: greeting, KPI tiles, activity feed,
     * branch health, quick actions. All widgets are permission-gated.
     */
    public function index()
    {
        $user = Auth::user();

        $kpis = Cache::remember("welcome.kpis.{$user->id}", 60, function () use ($user) {
            return [
                'incidents_open' => $user->can('view-incidents') && Schema::hasTable('incidents')
                    ? Incident::whereIn('status', ['open', 'investigating'])->count()
                    : null,

                'noc_events_open' => $user->can('view-noc') && Schema::hasTable('noc_events')
                    ? NocEvent::where('status', 'open')->count()
                    : null,

                'forms_pending' => $user->can('manage-forms') && Schema::hasTable('form_submissions')
                    ? FormSubmission::whereIn('status', ['new', 'reviewed'])->count()
                    : null,

                'identity_sync_health' => $user->can('view-identity') && Schema::hasTable('identity_sync_logs')
                    ? $this->identitySyncHealth()
                    : null,
            ];
        });

        $activity = ($user->can('view-activity-logs') && Schema::hasTable('activity_logs'))
            ? ActivityLog::with('user:id,name')->latest()->limit(15)->get()
            : collect();

        $branchHealth = Cache::remember('welcome.branch_health', 120, function () {
            return $this->computeBranchHealth();
        });

        $quickActions = $this->quickActionsFor($user);

        return view('admin.welcome', compact('kpis', 'activity', 'branchHealth', 'quickActions'));
    }

    /**
     * Phonebook & UCM Overview — the previous "/admin" dashboard.
     * Preserved verbatim and exposed at /admin/phonebook-overview so
     * nothing is lost when the home is reclaimed by the welcome page.
     */
    public function phonebookOverview()
    {
        [$branchCount, $contactCount, $phoneRequestCount, $totalXmlRequests] =
            Cache::remember('dashboard_top_counts', 300, function () {
                return [
                    Branch::count(),
                    Contact::count(),
                    PhoneRequestLog::distinct('mac')->whereNotNull('mac')->count(),
                    PhoneRequestLog::count(),
                ];
            });

        $ucmServers = UcmServer::orderBy('name')->get();
        $ucmStats   = [];

        foreach ($ucmServers as $server) {
            if (! $server->is_active) {
                $ucmStats[] = [
                    'server' => $server,
                    'stats'  => ['online' => false, 'error' => 'Server is disabled'],
                ];
                continue;
            }

            $stats = IppbxApiService::getCachedStats($server);
            $ucmStats[] = ['server' => $server, 'stats' => $stats];
        }

        $ucmOnline        = collect($ucmStats)->where('stats.online', true)->count();
        $totalExt         = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['total']        ?? 0);
        $totalIdle        = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['idle']         ?? 0);
        $totalInUse       = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['inuse']        ?? 0);
        $totalUnavail     = collect($ucmStats)->sum(fn ($u) => $u['stats']['extensions']['unavailable']  ?? 0);
        $totalTrunks      = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['total']      ?? 0);
        $totalReachable   = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['reachable']  ?? 0);
        $totalUnreachable = collect($ucmStats)->sum(fn ($u) => $u['stats']['trunk_counts']['unreachable']?? 0);

        $settings = Setting::get();

        $quickAdminLinks = Cache::remember('dashboard_quick_admin_links', 300, function () {
            $clicks = AdminLinkClick::selectRaw('link_id, COUNT(*) as click_count')
                ->groupBy('link_id')
                ->orderByDesc('click_count')
                ->limit(5)
                ->with('link')
                ->get()
                ->filter(fn ($c) => $c->link && $c->link->is_active)
                ->map(fn ($c) => $c->link);

            if ($clicks->isEmpty()) {
                $clicks = AdminLink::active()->orderBy('sort_order')->limit(5)->get();
            }
            return $clicks;
        });

        return view('admin.phonebook-overview', compact(
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

    /**
     * % of the last 20 sync runs that completed successfully.
     */
    private function identitySyncHealth(): array
    {
        $recent = IdentitySyncLog::latest('id')->limit(20)->get(['status', 'started_at', 'completed_at']);
        $count  = $recent->count();
        $okPct  = $count > 0
            ? (int) round($recent->where('status', 'completed')->count() / $count * 100)
            : null;
        $lastRun = $recent->first()?->completed_at ?? $recent->first()?->started_at;

        return [
            'success_pct' => $okPct,
            'last_run'    => $lastRun,
        ];
    }

    /**
     * Branch health snapshot — counts branches whose ISPs failed the
     * latest link check. Cached upstream so this only runs every 2 min.
     */
    private function computeBranchHealth(): array
    {
        if (! Schema::hasTable('branches')) {
            return ['total' => 0, 'down' => 0, 'healthy' => 0, 'down_branches' => collect()];
        }

        $totalBranches = Branch::count();
        $downBranchIds = collect();

        if (Schema::hasTable('link_checks') && Schema::hasTable('isp_connections')) {
            $downBranchIds = LinkCheck::where('checked_at', '>=', now()->subMinutes(15))
                ->where('success', false)
                ->join('isp_connections', 'link_checks.isp_id', '=', 'isp_connections.id')
                ->distinct()
                ->pluck('isp_connections.branch_id');
        }

        return [
            'total'         => $totalBranches,
            'down'          => $downBranchIds->count(),
            'healthy'       => max(0, $totalBranches - $downBranchIds->count()),
            'down_branches' => $downBranchIds->isNotEmpty()
                ? Branch::whereIn('id', $downBranchIds)->limit(5)->get(['id', 'name'])
                : collect(),
        ];
    }

    /**
     * Role-aware quick action launcher items. Skip silently if the
     * route isn't registered (e.g. permission group is disabled).
     */
    private function quickActionsFor($user): array
    {
        $candidates = [
            ['perm' => 'manage-contacts',         'route' => 'admin.contacts.create',           'label' => 'New Contact',          'icon' => 'bi-person-plus',          'tone' => 'blue'],
            ['perm' => 'manage-workflows',        'route' => 'admin.workflows.create',          'label' => 'New Workflow Request', 'icon' => 'bi-send-plus',            'tone' => 'indigo'],
            ['perm' => 'view-noc',                'route' => 'admin.noc.dashboard',             'label' => 'Open NOC Center',      'icon' => 'bi-speedometer2',         'tone' => 'amber'],
            ['perm' => 'manage-incidents',        'route' => 'admin.noc.incidents.create',      'label' => 'Report Incident',      'icon' => 'bi-exclamation-triangle', 'tone' => 'red'],
            ['perm' => 'manage-network-settings', 'route' => 'admin.network.diagnostics.index', 'label' => 'Network Diagnostics',  'icon' => 'bi-search',               'tone' => 'green'],
            ['perm' => 'view-activity-logs',      'route' => 'admin.activity-logs',             'label' => 'Audit Log',            'icon' => 'bi-shield-check',         'tone' => 'slate'],
        ];

        $out = [];
        foreach ($candidates as $c) {
            if ($user?->can($c['perm']) && Route::has($c['route'])) {
                $out[] = [
                    'label' => $c['label'],
                    'url'   => route($c['route']),
                    'icon'  => $c['icon'],
                    'tone'  => $c['tone'],
                ];
            }
        }

        return $out;
    }
}
