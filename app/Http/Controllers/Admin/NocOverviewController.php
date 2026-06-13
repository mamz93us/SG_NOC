<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extensive NOC overview dashboard. Counters/gauges/tables are computed in
 * index() (cached, branch-aware); time-series graphs lazy-load via chart().
 * Every subsystem block is guarded so a missing/empty table degrades to zero
 * rather than 500-ing the whole page.
 */
class NocOverviewController extends Controller
{
    public function index(Request $request)
    {
        $branchId = $request->input('branch'); // null/'all' = all branches
        $branchId = ($branchId === null || $branchId === 'all' || $branchId === '') ? null : (int) $branchId;
        $range = in_array($request->input('range'), ['24h', '7d', '30d', '90d'], true) ? $request->input('range') : '7d';

        $cacheKey = 'noc_overview:'.($branchId ?? 'all');
        $data = Cache::remember($cacheKey, 60, fn () => [
            'counters' => $this->counters($branchId),
            'gauges' => $this->gauges($branchId),
            'tables' => $this->tables($branchId),
            'matrix' => $this->branchMatrix(),
            's2s' => $this->siteToSite($branchId),
            'voip' => $this->voip($branchId),
        ]);

        return view('admin.noc.overview', array_merge($data, [
            'branches' => Branch::orderBy('name')->get(['id', 'name']),
            'selectedBranch' => $branchId,
            'range' => $range,
            'generatedAt' => now(),
        ]));
    }

    // ─── Counters (Row A) ─────────────────────────────────────────

    protected function counters(?int $branchId): array
    {
        return [
            'events_critical' => $this->safe(fn () => DB::table('noc_events')->whereIn('status', ['open', 'acknowledged'])->where('severity', 'critical')->count()),
            'events_open' => $this->safe(fn () => DB::table('noc_events')->whereIn('status', ['open', 'acknowledged'])->count()),
            'incidents_open' => $this->safe(fn () => DB::table('incidents')->whereIn('status', ['open', 'investigating'])->count()),
            'aps_down' => $this->safe(fn () => $this->scoped(DB::table('access_points')->where('status', 'down'), 'access_points', $branchId)->count()),
            'vpn_down' => $this->safe(fn () => $this->scoped(DB::table('vpn_tunnels')->where('status', 'down'), 'vpn_tunnels', $branchId)->count()),
            'hosts_down' => $this->safe(fn () => $this->scoped(DB::table('monitored_hosts')->where('status', 'down'), 'monitored_hosts', $branchId)->count()),
            'isp_branches' => $this->safe(fn () => $this->branchesWithIspIssues()),
            'backups_overdue' => $this->safe(fn () => DB::table('backup_accounts')->where('last_status', 'overdue')->count()),
            'expiring_30d' => $this->safe(fn () => $this->expiringCount(30)),
            'pending_approval' => $this->safe(fn () => DB::table('workflow_requests')->whereIn('status', ['pending', 'manager_input_pending', 'awaiting_manager_form'])->count()),
            // VoIP counters (shown in the headline row)
            'voip_ext_registered' => $this->safe(fn () => DB::table('ucm_extensions_cache')->where('status', '!=', 'unavailable')->count()),
            'voip_trunks_up' => $this->safe(fn () => max(0, DB::table('ucm_trunks_cache')->count() - DB::table('ucm_trunks_cache')->where('status', 'unreachable')->count())),
            'voip_active_calls' => $this->safe(fn () => DB::table('ucm_active_calls')->count()),
        ];
    }

    // ─── Gauges / donuts (Row B) ──────────────────────────────────

    protected function gauges(?int $branchId): array
    {
        return [
            'devices' => $this->safe(fn () => $this->groupCount('devices', 'status', $branchId), []),
            'aps' => $this->safe(fn () => $this->groupCount('access_points', 'status', $branchId), []),
            'vpn' => $this->safe(fn () => $this->groupCount('vpn_tunnels', 'status', $branchId), []),
            'hosts' => $this->safe(fn () => $this->groupCount('monitored_hosts', 'status', $branchId), []),
            'printers' => $this->safe(fn () => $this->groupCount('printers', 'printer_status', $branchId), []),
            'sophos_fw' => $this->safe(fn () => [
                'connected' => DB::table('sophos_central_firewalls')->where('status', 'connected')->count(),
                'disconnected' => DB::table('sophos_central_firewalls')->where('status', '!=', 'connected')->count(),
                'fw_upgrade' => DB::table('sophos_central_firewalls')->whereNotNull('available_firmware')->where('available_firmware', '!=', '[]')->where('available_firmware', '!=', '')->count(),
            ], []),
        ];
    }

    // ─── Live tables (Row D) ──────────────────────────────────────

    protected function tables(?int $branchId): array
    {
        return [
            'recent_events' => $this->safe(fn () => DB::table('noc_events')
                ->whereIn('status', ['open', 'acknowledged'])
                ->orderByDesc('last_seen')
                ->limit(10)
                ->get(['module', 'severity', 'title', 'status', 'first_seen', 'last_seen']), collect()),
            'down_aps' => $this->safe(fn () => $this->scoped(DB::table('access_points')->where('status', 'down'), 'access_points', $branchId)
                ->orderBy('name')->limit(15)->get(['name', 'ip_address', 'site', 'last_ping_at']), collect()),
            'expiring' => $this->safe(fn () => $this->expiringItems(30), collect()),
        ];
    }

    // ─── Per-branch matrix (Row E) ────────────────────────────────

    protected function branchMatrix(): array
    {
        return $this->safe(function () {
            $branches = Branch::orderBy('name')->get(['id', 'name']);
            $apsUp = $this->countByBranch('access_points', "status = 'up'");
            $apsTot = $this->countByBranch('access_points');
            $hostUp = $this->countByBranch('monitored_hosts', "status = 'up'");
            $hostTot = $this->countByBranch('monitored_hosts');
            $vpnUp = $this->countByBranch('vpn_tunnels', "status = 'up'");
            $vpnTot = $this->countByBranch('vpn_tunnels');
            $vq = $this->mosByBranch();

            return $branches->map(fn ($b) => [
                'id' => $b->id,
                'name' => $b->name,
                'aps_up' => $apsUp[$b->id] ?? 0,
                'aps_total' => $apsTot[$b->id] ?? 0,
                'hosts_up' => $hostUp[$b->id] ?? 0,
                'hosts_total' => $hostTot[$b->id] ?? 0,
                'vpn_up' => $vpnUp[$b->id] ?? 0,
                'vpn_total' => $vpnTot[$b->id] ?? 0,
                'mos' => isset($vq[$b->id]) ? round((float) $vq[$b->id]->m, 2) : null,
                'calls' => $vq[$b->id]->c ?? 0,
            ])->all();
        }, []);
    }

    // ─── Site-to-Site VPN (per firewall + hub) ────────────────────

    protected function siteToSite(?int $branchId): array
    {
        $sophos = $this->safe(fn () => $this->sophosVpnFromSensors($branchId), collect());

        return [
            'sophos' => $sophos,
            'sophos_summary' => [
                'up' => $sophos->where('status', 'up')->count(),
                'down' => $sophos->where('status', 'down')->count(),
                'total' => $sophos->count(),
            ],
            'hub' => $this->safe(function () use ($branchId) {
                if (! Schema::hasTable('vpn_tunnels')) {
                    return collect();
                }
                $q = DB::table('vpn_tunnels')->select('name', 'branch_id', 'status', 'ping_status',
                    'ping_latency_ms', 'remote_public_ip', 'remote_subnet', 'last_checked_at');
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }

                return $q->orderBy('name')->get();
            }, collect()),
        ];
    }

    /**
     * Sophos site-to-site tunnels derived from SNMP sensors
     * (sensor_group='VPN', name 'VPN: <tunnel> - Connection', value 1.0 = up),
     * mirroring the NOC Command Center source.
     */
    protected function sophosVpnFromSensors(?int $branchId): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('snmp_sensors')) {
            return collect();
        }

        $sensors = \App\Models\SnmpSensor::with(['host.branch'])
            ->where('sensor_group', 'VPN')
            ->where('name', 'like', 'VPN:%- Connection')
            ->get();

        if ($branchId) {
            $sensors = $sensors->filter(fn ($s) => (int) ($s->host?->branch_id) === $branchId)->values();
        }

        $ids = $sensors->pluck('id')->all();
        $latest = collect();
        if ($ids) {
            $latest = DB::table('sensor_metrics as m')
                ->whereIn('m.sensor_id', $ids)
                ->whereRaw('m.recorded_at = (select max(m2.recorded_at) from sensor_metrics m2 where m2.sensor_id = m.sensor_id)')
                ->select('m.sensor_id', 'm.value', 'm.recorded_at')
                ->get()->keyBy('sensor_id');
        }

        return $sensors->map(function ($s) use ($latest) {
            $m = $latest->get($s->id);
            $up = $m && (float) $m->value >= 1.0;

            return (object) [
                'name' => trim(str_replace(['VPN:', '- Connection'], '', $s->name)),
                'status' => $up ? 'up' : 'down',
                'firewall' => $s->host?->name ?: '-',
                'firewall_ip' => $s->host?->ip ?: '-',
                'branch' => $s->host?->branch?->name ?: 'No branch',
                'last_checked' => $m && $m->recorded_at
                    ? Carbon::parse($m->recorded_at)->diffForHumans()
                    : ($s->last_recorded_at?->diffForHumans() ?? '-'),
            ];
        })->sortBy('status')->values();
    }

    // ─── VoIP / Telephony details ─────────────────────────────────

    protected function voip(?int $branchId): array
    {
        $extTotal = $this->safe(fn () => DB::table('ucm_extensions_cache')->count());
        $extReg = $this->safe(fn () => DB::table('ucm_extensions_cache')->where('status', '!=', 'unavailable')->count());
        $trunkTotal = $this->safe(fn () => DB::table('ucm_trunks_cache')->count());
        $trunkDown = $this->safe(fn () => DB::table('ucm_trunks_cache')->where('status', 'unreachable')->count());

        return [
            'ext_total' => $extTotal,
            'ext_registered' => $extReg,
            'trunks_total' => $trunkTotal,
            'trunks_up' => max(0, $trunkTotal - $trunkDown),
            'trunks_down' => $trunkDown,
            'active_calls' => $this->safe(fn () => DB::table('ucm_active_calls')->count()),
            'calls_today' => $this->safe(fn () => DB::table('ucm_active_calls')->where('start_time', '>=', now()->startOfDay())->count()),
            'avg_mos_today' => $this->safe(function () use ($branchId) {
                $q = DB::table('voice_quality_reports')->whereNotNull('mos_lq')->where('call_start', '>=', now()->startOfDay());
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
                $v = $q->avg('mos_lq');

                return $v ? round((float) $v, 2) : null;
            }, null),
            'quality' => $this->safe(function () use ($branchId) {
                $q = DB::table('voice_quality_reports')->where('call_start', '>=', now()->startOfDay());
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }

                return $q->select('quality_label', DB::raw('COUNT(*) as c'))->groupBy('quality_label')->pluck('c', 'quality_label')->all();
            }, []),
            'trunks' => $this->safe(fn () => DB::table('ucm_trunks_cache')->orderBy('trunk_name')->limit(20)->get(['trunk_name', 'host', 'status', 'last_checked_at']), collect()),
        ];
    }

    // ─── Time-series charts (Row C) — lazy JSON ───────────────────

    public function chart(Request $request)
    {
        $metric = $request->input('metric', 'events');
        $range = in_array($request->input('range'), ['24h', '7d', '30d', '90d'], true) ? $request->input('range') : '7d';
        $branchId = $request->input('branch');
        $branchId = ($branchId === null || $branchId === 'all' || $branchId === '') ? null : (int) $branchId;

        [$since, $unit] = match ($range) {
            '24h' => [now()->subDay(), 'hour'],
            '30d' => [now()->subDays(30), 'day'],
            '90d' => [now()->subDays(90), 'day'],
            default => [now()->subDays(7), 'hour'],
        };

        $key = "noc_overview_chart:{$metric}:{$range}:".($branchId ?? 'all');
        $payload = Cache::remember($key, 60, fn () => $this->buildChart($metric, $since, $unit, $branchId));

        return response()->json($payload);
    }

    protected function buildChart(string $metric, Carbon $since, string $unit, ?int $branchId): array
    {
        return match ($metric) {
            'events' => $this->safe(fn () => $this->seriesBySeverity('noc_events', 'first_seen', 'severity', $since, $unit), $this->emptyChart()),
            'syslog' => $this->safe(fn () => $this->syslogSeries($since, $unit), $this->emptyChart()),
            'isp_latency' => $this->safe(fn () => $this->ispLatencySeries($since, $unit), $this->emptyChart()),
            'calls' => $this->safe(fn () => $this->countSeries('voice_quality_reports', 'call_start', $since, $unit, 'Calls'), $this->emptyChart()),
            'voip_mos' => $this->safe(fn () => $this->avgSeries('voice_quality_reports', 'call_start', 'mos_lq', $since, $unit, 'Avg MOS'), $this->emptyChart()),
            'voip_extensions' => $this->safe(fn () => $this->metricSnapshotSeries('voip_ext_registered', $since, $unit, 'Registered'), $this->emptyChart()),
            'voip_trunks' => $this->safe(fn () => $this->metricSnapshotSeries('voip_trunks_up', $since, $unit, 'Trunks up'), $this->emptyChart()),
            'backups' => $this->safe(fn () => $this->backupSeries($since), $this->emptyChart()),
            'ap_uptime' => $this->safe(fn () => $this->uptimeSeries('access_point', $since, $unit, $branchId), $this->emptyChart()),
            'host_uptime' => $this->safe(fn () => $this->uptimeSeries('monitored_host', $since, $unit, $branchId), $this->emptyChart()),
            'vpn_uptime' => $this->safe(fn () => $this->uptimeSeries('vpn_tunnel', $since, $unit, $branchId), $this->emptyChart()),
            default => $this->emptyChart(),
        };
    }

    // ─── Chart builders ───────────────────────────────────────────

    protected function seriesBySeverity(string $table, string $tsCol, string $sevCol, Carbon $since, string $unit): array
    {
        $bucket = $this->bucketExpr($tsCol, $unit);
        $rows = DB::table($table)
            ->where($tsCol, '>=', $since)
            ->select(DB::raw("$bucket as bucket"), $sevCol, DB::raw('COUNT(*) as c'))
            ->groupBy('bucket', $sevCol)
            ->orderBy('bucket')
            ->get();

        $labels = $rows->pluck('bucket')->unique()->values();
        $severities = ['critical', 'warning', 'info'];
        $series = [];
        foreach ($severities as $sev) {
            $series[$sev] = $labels->map(fn ($l) => (int) ($rows->firstWhere(fn ($r) => $r->bucket === $l && $r->{$sevCol} === $sev)->c ?? 0))->all();
        }

        return ['labels' => $labels->all(), 'series' => $series];
    }

    protected function syslogSeries(Carbon $since, string $unit): array
    {
        $bucket = $this->bucketExpr('received_at', $unit);
        $rows = DB::table('syslog_messages')
            ->where('received_at', '>=', $since)
            ->select(DB::raw("$bucket as bucket"), 'severity', DB::raw('COUNT(*) as c'))
            ->groupBy('bucket', 'severity')
            ->orderBy('bucket')
            ->get();

        $labels = $rows->pluck('bucket')->unique()->values();
        // syslog severity is numeric 0-7: <=3 critical/error, 4 warning, >=5 info
        $tier = fn ($s) => $s <= 3 ? 'critical' : ($s == 4 ? 'warning' : 'info');
        $series = ['critical' => [], 'warning' => [], 'info' => []];
        foreach ($labels as $l) {
            $bucketRows = $rows->where('bucket', $l);
            foreach (['critical', 'warning', 'info'] as $t) {
                $series[$t][] = (int) $bucketRows->filter(fn ($r) => $tier((int) $r->severity) === $t)->sum('c');
            }
        }

        return ['labels' => $labels->all(), 'series' => $series];
    }

    protected function ispLatencySeries(Carbon $since, string $unit): array
    {
        $bucket = $this->bucketExpr('checked_at', $unit);
        $rows = DB::table('link_checks')
            ->where('checked_at', '>=', $since)
            ->select(DB::raw("$bucket as bucket"), DB::raw('AVG(latency) as lat'), DB::raw('AVG(packet_loss) as loss'))
            ->groupBy('bucket')->orderBy('bucket')->get();

        return [
            'labels' => $rows->pluck('bucket')->all(),
            'series' => [
                'Latency (ms)' => $rows->map(fn ($r) => round((float) $r->lat, 1))->all(),
                'Packet loss (%)' => $rows->map(fn ($r) => round((float) $r->loss, 1))->all(),
            ],
        ];
    }

    protected function countSeries(string $table, string $tsCol, Carbon $since, string $unit, string $label): array
    {
        $bucket = $this->bucketExpr($tsCol, $unit);
        $rows = DB::table($table)->where($tsCol, '>=', $since)
            ->select(DB::raw("$bucket as bucket"), DB::raw('COUNT(*) as c'))
            ->groupBy('bucket')->orderBy('bucket')->get();

        return ['labels' => $rows->pluck('bucket')->all(), 'series' => [$label => $rows->pluck('c')->map(fn ($c) => (int) $c)->all()]];
    }

    protected function avgSeries(string $table, string $tsCol, string $valCol, Carbon $since, string $unit, string $label): array
    {
        $bucket = $this->bucketExpr($tsCol, $unit);
        $rows = DB::table($table)->whereNotNull($valCol)->where($tsCol, '>=', $since)
            ->select(DB::raw("$bucket as bucket"), DB::raw("AVG($valCol) as v"))
            ->groupBy('bucket')->orderBy('bucket')->get();

        return ['labels' => $rows->pluck('bucket')->all(), 'series' => [$label => $rows->map(fn ($r) => round((float) $r->v, 2))->all()]];
    }

    protected function metricSnapshotSeries(string $metric, Carbon $since, string $unit, string $label): array
    {
        if (! Schema::hasTable('noc_metric_snapshots')) {
            return $this->emptyChart();
        }
        $bucket = $this->bucketExpr('captured_at', $unit);
        $rows = DB::table('noc_metric_snapshots')->where('metric', $metric)->where('captured_at', '>=', $since)
            ->select(DB::raw("$bucket as bucket"), DB::raw('AVG(value) as v'))
            ->groupBy('bucket')->orderBy('bucket')->get();

        return ['labels' => $rows->pluck('bucket')->all(), 'series' => [$label => $rows->map(fn ($r) => round((float) $r->v, 1))->all()]];
    }

    protected function backupSeries(Carbon $since): array
    {
        $bucket = $this->bucketExpr('captured', 'day');
        $union = collect();
        foreach (['database_backups' => 'completed_at', 'sftp_backups' => 'uploaded_at'] as $table => $tsCol) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $rows = DB::table($table)
                ->whereNotNull($tsCol)->where($tsCol, '>=', $since)
                ->select(DB::raw($this->bucketExpr($tsCol, 'day').' as bucket'), 'status', DB::raw('COUNT(*) as c'))
                ->groupBy('bucket', 'status')->get();
            $union = $union->concat($rows);
        }

        $labels = $union->pluck('bucket')->unique()->sort()->values();
        $ok = $labels->map(fn ($l) => (int) $union->where('bucket', $l)->whereIn('status', ['uploaded'])->sum('c'))->all();
        $fail = $labels->map(fn ($l) => (int) $union->where('bucket', $l)->where('status', 'failed')->sum('c'))->all();

        return ['labels' => $labels->all(), 'series' => ['Uploaded' => $ok, 'Failed' => $fail]];
    }

    protected function uptimeSeries(string $entityType, Carbon $since, string $unit, ?int $branchId): array
    {
        $bucket = $this->bucketExpr('captured_at', $unit);
        $q = DB::table('availability_snapshots')
            ->where('entity_type', $entityType)
            ->where('captured_at', '>=', $since);
        if ($branchId) {
            $q->where('branch_id', $branchId);
        }
        $rows = $q->select(
            DB::raw("$bucket as bucket"),
            DB::raw('SUM(CASE WHEN up = 1 THEN 1 ELSE 0 END) as up_c'),
            DB::raw('COUNT(*) as total_c')
        )->groupBy('bucket')->orderBy('bucket')->get();

        return [
            'labels' => $rows->pluck('bucket')->all(),
            'series' => ['Uptime %' => $rows->map(fn ($r) => $r->total_c > 0 ? round(100 * $r->up_c / $r->total_c, 1) : 0)->all()],
        ];
    }

    // ─── Shared helpers ───────────────────────────────────────────

    protected function groupCount(string $table, string $col, ?int $branchId): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
            return [];
        }
        $q = DB::table($table);
        if ($branchId && Schema::hasColumn($table, 'branch_id')) {
            $q->where('branch_id', $branchId);
        }

        return $q->select($col, DB::raw('COUNT(*) as c'))
            ->groupBy($col)
            ->pluck('c', $col)
            ->map(fn ($c) => (int) $c)
            ->all();
    }

    /** Avg MOS + call count per branch over the last 24h. */
    protected function mosByBranch(): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('voice_quality_reports')) {
            return collect();
        }

        return DB::table('voice_quality_reports')
            ->whereNotNull('branch_id')->whereNotNull('mos_lq')
            ->where('call_start', '>=', now()->subDay())
            ->select('branch_id', DB::raw('AVG(mos_lq) as m'), DB::raw('COUNT(*) as c'))
            ->groupBy('branch_id')->get()->keyBy('branch_id');
    }

    /** @return array<int,int> branch_id => count */
    protected function countByBranch(string $table, ?string $whereRaw = null): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'branch_id')) {
            return [];
        }
        $q = DB::table($table)->whereNotNull('branch_id');
        if ($whereRaw) {
            $q->whereRaw($whereRaw);
        }

        return $q->select('branch_id', DB::raw('COUNT(*) as c'))
            ->groupBy('branch_id')->pluck('c', 'branch_id')
            ->map(fn ($c) => (int) $c)->all();
    }

    protected function scoped($query, string $table, ?int $branchId)
    {
        if ($branchId && Schema::hasColumn($table, 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query;
    }

    protected function branchesWithIspIssues(): int
    {
        if (! Schema::hasTable('link_checks') || ! Schema::hasTable('isp_connections')) {
            return 0;
        }

        return (int) DB::table('link_checks')
            ->join('isp_connections', 'isp_connections.id', '=', 'link_checks.isp_id')
            ->where('link_checks.checked_at', '>=', now()->subMinutes(15))
            ->where('link_checks.success', false)
            ->distinct()
            ->count('isp_connections.branch_id');
    }

    protected function expiringCount(int $days): int
    {
        $until = now()->addDays($days);
        $n = 0;
        if (Schema::hasTable('licenses')) {
            $n += DB::table('licenses')->whereNotNull('expiry_date')->whereBetween('expiry_date', [now(), $until])->count();
        }
        if (Schema::hasTable('ssl_certificates')) {
            $n += DB::table('ssl_certificates')->whereNotNull('expires_at')->whereBetween('expires_at', [now(), $until])->count();
        }
        if (Schema::hasTable('isp_connections')) {
            $n += DB::table('isp_connections')->whereNotNull('contract_end')->whereBetween('contract_end', [now(), $until])->count();
        }
        if (Schema::hasTable('devices') && Schema::hasColumn('devices', 'warranty_expiry')) {
            $n += DB::table('devices')->whereNotNull('warranty_expiry')->whereBetween('warranty_expiry', [now(), $until])->count();
        }

        return $n;
    }

    protected function expiringItems(int $days): \Illuminate\Support\Collection
    {
        $until = now()->addDays($days);
        $items = collect();

        if (Schema::hasTable('licenses')) {
            foreach (DB::table('licenses')->whereNotNull('expiry_date')->whereBetween('expiry_date', [now(), $until])->get(['license_name', 'expiry_date']) as $r) {
                $items->push(['type' => 'License', 'name' => $r->license_name, 'date' => $r->expiry_date]);
            }
        }
        if (Schema::hasTable('ssl_certificates')) {
            foreach (DB::table('ssl_certificates')->whereNotNull('expires_at')->whereBetween('expires_at', [now(), $until])->get(['domain', 'expires_at']) as $r) {
                $items->push(['type' => 'SSL cert', 'name' => $r->domain, 'date' => $r->expires_at]);
            }
        }
        if (Schema::hasTable('isp_connections')) {
            foreach (DB::table('isp_connections')->whereNotNull('contract_end')->whereBetween('contract_end', [now(), $until])->get(['provider', 'contract_end']) as $r) {
                $items->push(['type' => 'ISP contract', 'name' => $r->provider, 'date' => $r->contract_end]);
            }
        }

        return $items->sortBy('date')->take(15)->values();
    }

    // ─── DB-agnostic time bucket ──────────────────────────────────

    protected function bucketExpr(string $col, string $unit): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            return $unit === 'hour'
                ? "strftime('%Y-%m-%d %H:00', $col)"
                : "strftime('%Y-%m-%d', $col)";
        }

        // mysql / mariadb
        return $unit === 'hour'
            ? "DATE_FORMAT($col, '%Y-%m-%d %H:00')"
            : "DATE_FORMAT($col, '%Y-%m-%d')";
    }

    protected function emptyChart(): array
    {
        return ['labels' => [], 'series' => []];
    }

    /**
     * Run a query block, returning a fallback if the table/column doesn't
     * exist or anything throws — keeps one broken subsystem from 500-ing
     * the whole dashboard.
     */
    protected function safe(callable $fn, $fallback = 0)
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
