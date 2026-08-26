<?php

namespace App\Services\BranchHealth;

use App\Models\AccessPoint;
use App\Models\Branch;
use App\Models\BranchTunnel;
use App\Models\Device;
use App\Models\IspConnection;
use App\Models\LinkCheck;
use App\Models\MonitoredHost;
use App\Models\NetworkSwitch;
use App\Models\NocEvent;
use App\Models\Printer;
use App\Models\PrinterSupply;
use App\Models\Setting;
use App\Models\SophosFirewall;
use App\Models\UcmServer;
use App\Models\UcmTrunkCache;
use App\Models\VoiceMeshNode;
use App\Models\VoiceMeshPair;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads every telemetry source the branch score needs, in bulk.
 *
 * The whole point of this class is that its query count does not depend on how
 * many branches it is asked about. Scoring nine branches costs the same round
 * trips as scoring one; only the row counts differ. That is what lets the NOC
 * dashboard render the whole estate without the per-branch fan-out the old
 * HealthScoringService had.
 *
 * Two hard rules:
 *
 *   1. READ ONLY. This runs inside a GET. It must never reconcile devices,
 *      create health records, or touch Setting::get() (which creates the
 *      settings singleton as a side effect).
 *   2. NO LIVE DEVICE CALLS. Nothing here talks to a UCM, firewall, switch or
 *      printer. Every value comes from what the schedulers already collected.
 *
 * Cross-branch inputs (the voice mesh denominator, MOS extension attribution)
 * are resolved here so the evaluator can stay pure.
 */
class BranchTelemetryLoader
{
    /**
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, BranchSlice> keyed by branch id
     */
    public function load(Collection $branches): Collection
    {
        $branchIds = $branches->pluck('id')->all();

        if ($branchIds === []) {
            return collect();
        }

        $ucm = $this->loadUcm($branches);
        $mesh = $this->loadVoiceMesh();
        $mos = $this->loadMos($branches);
        $network = $this->loadNetwork($branchIds);
        $devices = $this->loadDevices($branchIds);
        $merakiStale = $this->merakiStaleMinutes();

        return $branches->mapWithKeys(function (Branch $branch) use ($ucm, $mesh, $mos, $network, $devices, $merakiStale) {
            $id = (int) $branch->id;
            $server = $ucm['servers'][$branch->ucm_server_id] ?? null;
            $node = $mesh['nodeByBranch'][$id] ?? null;

            return [$id => new BranchSlice(
                branch: $branch,
                ucmServer: $server,
                trunks: $server ? ($ucm['trunks'][$server->id] ?? collect()) : collect(),
                meshNode: $node,
                meshPairs: $node ? ($mesh['pairsByCaller'][$node->id] ?? collect()) : collect(),
                meshDestinations: $node
                    ? $mesh['nodes']->reject(fn ($n) => $n->id === $node->id)->values()
                    : collect(),
                mos: $mos[$id] ?? self::emptyMos(),
                firewalls: $network['firewalls'][$id] ?? collect(),
                tunnels: $network['tunnels'][$id] ?? collect(),
                ispConnections: $network['isps'][$id] ?? collect(),
                linkChecks: $network['linkChecks'],
                switches: $devices['switches'][$id] ?? collect(),
                accessPoints: $network['accessPoints'][$id] ?? collect(),
                criticalAlerts: $network['alerts'][$id] ?? collect(),
                printers: $devices['printers'][$id] ?? collect(),
                biometrics: $devices['biometrics'][$id] ?? collect(),
                tonerByPrinter: $devices['toner'],
                hostsByDeviceId: $devices['hostsByDeviceId'],
                hostsByIp: $devices['hostsByIp'],
                merakiByDeviceId: $devices['merakiByDeviceId'],
                merakiStaleMinutes: $merakiStale,
            )];
        });
    }

    // ─────────────────────────────────────────────────────────────
    // VoIP
    // ─────────────────────────────────────────────────────────────

    /**
     * UCM servers and their trunk cache.
     *
     * UCM is the one subsystem with no branch_id anywhere — the link runs the
     * other way, from branches.ucm_server_id. Several branches can share one PBX.
     */
    private function loadUcm(Collection $branches): array
    {
        $serverIds = $branches->pluck('ucm_server_id')->filter()->unique()->values();

        if ($serverIds->isEmpty() || ! Schema::hasTable('ucm_servers')) {
            return ['servers' => collect(), 'trunks' => collect()];
        }

        $servers = UcmServer::whereIn('id', $serverIds)->get()->keyBy('id');

        $trunks = Schema::hasTable('ucm_trunks_cache')
            ? UcmTrunkCache::whereIn('ucm_id', $servers->keys())->get()->groupBy('ucm_id')
            : collect();

        return ['servers' => $servers, 'trunks' => $trunks];
    }

    /**
     * The entire mesh, unconditionally.
     *
     * Not sliced by branch, and not filtered to the requested ids, because a
     * branch's score depends on every OTHER active node: the denominator is
     * "can this branch reach all its peers". The table is bounded at N*(N-1)
     * legs — 42 rows for seven nodes — so loading all of it is cheaper than
     * working out which subset matters.
     */
    private function loadVoiceMesh(): array
    {
        if (! Schema::hasTable('voice_mesh_nodes')) {
            return ['nodes' => collect(), 'nodeByBranch' => collect(), 'pairsByCaller' => collect()];
        }

        $nodes = VoiceMeshNode::where('is_active', true)->get();

        // branch_id is nullable and not unique on voice_mesh_nodes. A branch
        // with two active nodes is ambiguous, so take the lowest sort_order
        // deterministically rather than letting row order decide.
        $nodeByBranch = $nodes
            ->filter(fn ($n) => $n->branch_id !== null)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->keyBy('branch_id');

        $pairs = Schema::hasTable('voice_mesh_pairs')
            ? VoiceMeshPair::with('dest:id,code,name')
                ->whereIn('caller_node_id', $nodes->pluck('id'))
                ->get()
                ->groupBy('caller_node_id')
            : collect();

        return ['nodes' => $nodes, 'nodeByBranch' => $nodeByBranch, 'pairsByCaller' => $pairs];
    }

    /**
     * Per-branch MOS aggregates for both candidate windows.
     *
     * Grouped by extension rather than mapped in SQL. A CASE expression over
     * ext ranges would need a numeric cast that behaves differently on MySQL and
     * SQLite, and would be unindexable against a table fed by every call.
     * Grouping by extension yields one row per handset — hundreds, not millions —
     * and lets the range mapping happen in PHP where it can be tested.
     *
     * Both windows come back in one query, split by a recency flag, so widening
     * to seven days for a quiet branch costs no extra round trip.
     *
     * Timestamp is created_at (ingest), which is what the supporting composite
     * index covers, and what "the last 24 hours of observations" means.
     */
    private function loadMos(Collection $branches): array
    {
        $out = [];
        foreach ($branches as $b) {
            $out[(int) $b->id] = self::emptyMos();
        }

        if (! Schema::hasTable('voice_quality_reports')) {
            return $out;
        }

        $cfg = BranchHealthConfig::array('mos');
        $threshold = (float) $cfg['threshold'];
        $cut24 = now()->subHours((int) $cfg['window_hours']);
        $cutExtended = now()->subDays((int) $cfg['extended_window_days']);

        // Both windows in one pass, with every conditional wrapped in SUM().
        //
        // The obvious shape -- selecting a bare `CASE WHEN created_at >= ?` as a
        // recency flag and repeating it in GROUP BY -- is rejected by MySQL under
        // ONLY_FULL_GROUP_BY, which is on by default in MySQL 8. It cannot prove
        // the two expressions are the same because each `?` is a distinct
        // parameter, so it treats the select-list CASE as a non-aggregated column
        // that is not functionally dependent on the grouping. SQLite has no such
        // mode, so this passes locally and 500s in production.
        //
        // Aggregated conditionals are always legal, and GROUP BY is then just two
        // plain columns. This is also cheaper: one row per (extension, branch)
        // instead of two.
        $rows = DB::table('voice_quality_reports')
            ->selectRaw(
                'extension, branch_id, '
                .'COUNT(*) AS samples_all, '
                .'SUM(mos_lq) AS sum_all, '
                .'SUM(CASE WHEN mos_lq >= ? THEN 1 ELSE 0 END) AS passing_all, '
                .'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS samples_recent, '
                .'SUM(CASE WHEN created_at >= ? THEN mos_lq ELSE 0 END) AS sum_recent, '
                .'SUM(CASE WHEN created_at >= ? AND mos_lq >= ? THEN 1 ELSE 0 END) AS passing_recent',
                [$threshold, $cut24, $cut24, $cut24, $threshold]
            )
            ->whereNotNull('mos_lq')
            ->where('created_at', '>=', $cutExtended)
            ->groupBy('extension', 'branch_id')
            ->get();

        $rangeMap = $this->extensionRangeMap($branches);

        // buckets[branchId]['recent'|'all'] => running totals
        $buckets = [];
        foreach ($rows as $row) {
            $branchId = $row->branch_id !== null
                ? (int) $row->branch_id
                : $this->branchForExtension((string) $row->extension, $rangeMap);

            if ($branchId === null || ! array_key_exists($branchId, $out)) {
                continue; // unattributable, or not a branch we were asked about
            }

            foreach ([
                'recent' => ['samples_recent', 'passing_recent', 'sum_recent'],
                'all' => ['samples_all', 'passing_all', 'sum_all'],
            ] as $bucket => [$samples, $passing, $sum]) {
                $buckets[$branchId][$bucket]['samples'] = ($buckets[$branchId][$bucket]['samples'] ?? 0) + (int) $row->{$samples};
                $buckets[$branchId][$bucket]['passing'] = ($buckets[$branchId][$bucket]['passing'] ?? 0) + (int) $row->{$passing};
                $buckets[$branchId][$bucket]['sum_mos'] = ($buckets[$branchId][$bucket]['sum_mos'] ?? 0) + (float) $row->{$sum};
            }
        }

        $minSamples = (int) $cfg['min_samples'];

        foreach ($buckets as $branchId => $b) {
            $recent = $b['recent'] ?? ['samples' => 0, 'passing' => 0, 'sum_mos' => 0.0];

            // Widen to the long window only when the day is too quiet to judge.
            $useRecent = $recent['samples'] >= $minSamples;
            $use = $useRecent ? $recent : ($b['all'] ?? $recent);

            $out[$branchId] = [
                'samples' => (int) $use['samples'],
                'passing' => (int) $use['passing'],
                'avg_mos' => $use['samples'] > 0 ? round($use['sum_mos'] / $use['samples'], 2) : null,
                'window_label' => $useRecent ? $cfg['window_hours'].'h' : $cfg['extended_window_days'].'d',
            ];
        }

        return $out;
    }

    /**
     * branch id => [start, end] for branches with an EXPLICIT extension range.
     *
     * Reads the raw columns, never Branch::effectiveExtRange(), which falls back
     * to a global default of 1000-1999 when a branch leaves them null. Using the
     * helper here would collapse every unconfigured branch onto one interval and
     * hand all of their calls to whichever matched first.
     *
     * Overlapping ranges are dropped entirely rather than resolved arbitrarily —
     * nothing in the schema prevents an operator from creating them.
     */
    private function extensionRangeMap(Collection $branches): array
    {
        $ranges = [];
        foreach ($branches as $b) {
            if ($b->ext_range_start === null || $b->ext_range_end === null) {
                continue;
            }
            $ranges[(int) $b->id] = [(int) $b->ext_range_start, (int) $b->ext_range_end];
        }

        $overlapping = [];
        foreach ($ranges as $id => [$start, $end]) {
            foreach ($ranges as $otherId => [$oStart, $oEnd]) {
                if ($id !== $otherId && $start <= $oEnd && $oStart <= $end) {
                    $overlapping[$id] = true;
                    $overlapping[$otherId] = true;
                }
            }
        }

        return array_diff_key($ranges, $overlapping);
    }

    /** Exact digits only — a prefixed or lettered extension is not guessed at. */
    private function branchForExtension(string $extension, array $rangeMap): ?int
    {
        $extension = trim($extension);

        if ($extension === '' || ! ctype_digit($extension)) {
            return null;
        }

        $value = (int) $extension;

        foreach ($rangeMap as $branchId => [$start, $end]) {
            if ($value >= $start && $value <= $end) {
                return $branchId;
            }
        }

        return null;
    }

    private static function emptyMos(): array
    {
        return ['samples' => 0, 'passing' => 0, 'avg_mos' => null, 'window_label' => null];
    }

    // ─────────────────────────────────────────────────────────────
    // Network
    // ─────────────────────────────────────────────────────────────

    private function loadNetwork(array $branchIds): array
    {
        $firewalls = Schema::hasTable('sophos_firewalls')
            ? SophosFirewall::with('monitoredHost:id,ip,status,last_ping_at')
                ->whereIn('branch_id', $branchIds)->get()->groupBy('branch_id')
            : collect();

        $tunnels = Schema::hasTable('branch_tunnels')
            ? BranchTunnel::with('activeProbes')
                ->where('is_active', true)
                ->whereIn('branch_id', $branchIds)->get()->groupBy('branch_id')
            : collect();

        $isps = Schema::hasTable('isp_connections')
            ? IspConnection::whereIn('branch_id', $branchIds)->get()->groupBy('branch_id')
            : collect();

        $accessPoints = Schema::hasTable('access_points')
            ? AccessPoint::where('monitor_enabled', true)
                ->whereIn('branch_id', $branchIds)->get()->groupBy('branch_id')
            : collect();

        return [
            'firewalls' => $firewalls,
            'tunnels' => $tunnels,
            'isps' => $isps,
            'linkChecks' => $this->loadLatestLinkChecks($isps),
            'accessPoints' => $accessPoints,
            'alerts' => $this->loadCriticalAlerts($branchIds),
        ];
    }

    /**
     * The newest still-fresh LinkCheck for each ISP, keyed by isp id.
     *
     * Done as MAX(checked_at) GROUP BY isp_id followed by a fetch of exactly
     * those rows, rather than ordering the window and grouping in PHP. The
     * freshness window is not a safe bound on row count: SlaMonitorService
     * stamps checked_at at write time, so draining a backlog lands every queued
     * check inside the window at once.
     *
     * Deliberately NOT collapsed into a single query selecting `success`
     * alongside MAX(): SQLite's bare-column extension would return the right
     * row, while MySQL 8 under ONLY_FULL_GROUP_BY rejects the statement — the
     * exact shape of bug that passes on the SQLite test connection and 500s in
     * production.
     */
    private function loadLatestLinkChecks(Collection $ispsByBranch): Collection
    {
        if (! Schema::hasTable('link_checks')) {
            return collect();
        }

        $ispIds = $ispsByBranch->flatten(1)->pluck('id')->unique()->values();

        if ($ispIds->isEmpty()) {
            return collect();
        }

        $cutoff = now()->subMinutes(BranchHealthConfig::int('freshness.isp_link', 15));

        $latest = DB::table('link_checks')
            ->selectRaw('isp_id, MAX(checked_at) AS checked_at')
            ->whereIn('isp_id', $ispIds)
            ->where('checked_at', '>=', $cutoff)
            ->groupBy('isp_id')
            ->get();

        if ($latest->isEmpty()) {
            return collect();
        }

        return LinkCheck::where(function ($q) use ($latest) {
            foreach ($latest as $row) {
                $q->orWhere(fn ($w) => $w->where('isp_id', $row->isp_id)->where('checked_at', $row->checked_at));
            }
        })->get()->keyBy('isp_id');
    }

    /**
     * Open or acknowledged critical firewall/Sophos events, per branch.
     *
     * Matched on source_type OR module because the producers never agreed on an
     * identity convention: TunnelWatchdog and SyncSophosCentralCommand write
     * source_type/source_id, while NocAlertEngine (which SyncSophosDataJob uses)
     * writes module/entity_* and leaves source_type null.
     */
    private function loadCriticalAlerts(array $branchIds): Collection
    {
        if (! Schema::hasTable('noc_events') || ! Schema::hasColumn('noc_events', 'branch_id')) {
            return collect();
        }

        return NocEvent::query()
            ->select(['id', 'branch_id', 'module', 'source_type', 'severity', 'status', 'title', 'last_seen'])
            ->whereIn('branch_id', $branchIds)
            ->where('severity', 'critical')
            ->whereIn('status', ['open', 'acknowledged'])
            ->where(function ($q) {
                $q->whereIn('source_type', BranchHealthConfig::array('critical_alert_source_types'))
                    ->orWhereIn('module', BranchHealthConfig::array('critical_alert_modules'));
            })
            ->orderByDesc('last_seen')
            ->get()
            ->groupBy('branch_id');
    }

    // ─────────────────────────────────────────────────────────────
    // Devices
    // ─────────────────────────────────────────────────────────────

    private function loadDevices(array $branchIds): array
    {
        $devices = Schema::hasTable('devices')
            ? Device::query()
                ->select(['id', 'branch_id', 'name', 'type', 'ip_address', 'asset_code'])
                ->whereIn('type', ['switch', 'biometric'])
                ->whereIn('branch_id', $branchIds)
                ->get()
            : collect();

        $deviceIds = $devices->pluck('id');

        $printers = Schema::hasTable('printers')
            ? Printer::query()
                ->select(['id', 'branch_id', 'device_id', 'printer_name', 'ip_address',
                    'toner_black', 'toner_cyan', 'toner_magenta', 'toner_yellow', 'snmp_last_polled_at'])
                ->whereIn('branch_id', $branchIds)
                ->get()
            : collect();

        // Hosts are loaded by BOTH the branch's device ids and its own branch_id.
        // monitored_hosts.branch_id is nullable and can disagree with the owning
        // device's — filtering on it alone would silently drop a switch's host
        // and fall the check back to the Meraki row, which is the stale-data trap
        // this check exists to avoid.
        $linkedDeviceIds = $deviceIds->merge($printers->pluck('device_id')->filter())->unique()->values();

        $hosts = Schema::hasTable('monitored_hosts')
            ? MonitoredHost::query()
                ->select(['id', 'branch_id', 'device_id', 'name', 'ip', 'status', 'last_ping_at'])
                ->where(function ($q) use ($branchIds, $linkedDeviceIds) {
                    $q->whereIn('branch_id', $branchIds);
                    if ($linkedDeviceIds->isNotEmpty()) {
                        $q->orWhereIn('device_id', $linkedDeviceIds);
                    }
                })
                ->get()
            : collect();

        // Same reasoning for the Meraki side: key off device_id, not branch_id.
        $meraki = (Schema::hasTable('network_switches') && $deviceIds->isNotEmpty())
            ? NetworkSwitch::query()
                ->select(['id', 'device_id', 'branch_id', 'name', 'status', 'last_reported_at'])
                ->whereIn('device_id', $deviceIds)
                ->get()
            : collect();

        return [
            'switches' => $devices->where('type', 'switch')->groupBy('branch_id'),
            'biometrics' => $devices->where('type', 'biometric')->groupBy('branch_id'),
            'printers' => $printers->groupBy('branch_id'),
            'toner' => $this->loadToner($printers->pluck('id')),
            'hostsByDeviceId' => $hosts->filter(fn ($h) => $h->device_id !== null)->keyBy('device_id'),
            'hostsByIp' => $hosts->filter(fn ($h) => filled($h->ip))->keyBy('ip'),
            'merakiByDeviceId' => $meraki->filter(fn ($m) => $m->device_id !== null)->keyBy('device_id'),
        ];
    }

    /** Fresh, known toner readings only, grouped by printer. */
    private function loadToner(Collection $printerIds): Collection
    {
        if ($printerIds->isEmpty() || ! Schema::hasTable('printer_supplies')) {
            return collect();
        }

        $cutoff = now()->subMinutes(BranchHealthConfig::int('freshness.printer_supply', 30));

        return PrinterSupply::query()
            ->select(['id', 'printer_id', 'supply_type', 'supply_color', 'supply_percent', 'last_updated_at'])
            ->whereIn('printer_id', $printerIds)
            ->where('supply_type', 'toner')
            ->whereNotNull('supply_percent')
            ->where('last_updated_at', '>=', $cutoff)
            ->get()
            ->groupBy('printer_id');
    }

    /**
     * How old a Meraki row may be before it stops counting.
     *
     * Meraki's cadence is operator-configurable, so the window tracks it at
     * three intervals with a floor. Uses Setting::first() — Setting::get()
     * CREATES the singleton row, and this runs inside a GET.
     */
    private function merakiStaleMinutes(): int
    {
        $interval = 0;

        if (Schema::hasTable('settings')) {
            $interval = (int) (Setting::first()?->meraki_polling_interval ?: 0);
        }

        $cfg = BranchHealthConfig::get('freshness');

        return max(
            (int) ($cfg['meraki_switch_min'] ?? 15),
            $interval * (int) ($cfg['meraki_switch_intervals'] ?? 3)
        );
    }
}
