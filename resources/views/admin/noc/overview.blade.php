@extends('layouts.admin')
@section('title', 'NOC Overview')

@php
    $counterDefs = [
        ['key' => 'events_critical', 'label' => 'Critical Events', 'icon' => 'bi-exclamation-octagon-fill', 'tone' => 'danger'],
        ['key' => 'events_open', 'label' => 'Open Events', 'icon' => 'bi-bell-fill', 'tone' => 'warning'],
        ['key' => 'incidents_open', 'label' => 'Open Incidents', 'icon' => 'bi-clipboard2-pulse', 'tone' => 'danger'],
        ['key' => 'aps_down', 'label' => 'APs Down', 'icon' => 'bi-router', 'tone' => 'danger'],
        ['key' => 'vpn_down', 'label' => 'VPN Down', 'icon' => 'bi-shield-lock', 'tone' => 'danger'],
        ['key' => 'hosts_down', 'label' => 'Hosts Down', 'icon' => 'bi-hdd-network', 'tone' => 'danger'],
        ['key' => 'isp_branches', 'label' => 'Branches w/ ISP Issues', 'icon' => 'bi-diagram-3', 'tone' => 'warning'],
        ['key' => 'backups_overdue', 'label' => 'Backups Overdue', 'icon' => 'bi-shield-exclamation', 'tone' => 'warning'],
        ['key' => 'expiring_30d', 'label' => 'Expiring ≤30d', 'icon' => 'bi-calendar-x', 'tone' => 'warning'],
        ['key' => 'pending_approval', 'label' => 'Pending Approvals', 'icon' => 'bi-hourglass-split', 'tone' => 'info'],
    ];
    $toneClass = ['danger' => 'text-danger', 'warning' => 'text-warning', 'info' => 'text-info', 'success' => 'text-success'];
@endphp

@section('content')
<div class="container-fluid py-4" id="noc-overview"
     data-chart-url="{{ route('admin.noc.overview.chart') }}"
     data-branch="{{ $selectedBranch ?? 'all' }}"
     data-range="{{ $range }}">

    {{-- Header / controls --}}
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-grid-1x2-fill me-2"></i>NOC Overview</h4>
        <form method="GET" class="d-flex align-items-center gap-2" id="filterForm">
            <select name="branch" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <option value="all" {{ $selectedBranch ? '' : 'selected' }}>All branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string)$selectedBranch === (string)$b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
            <select name="range" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                @foreach(['24h' => 'Last 24h', '7d' => 'Last 7 days', '30d' => 'Last 30 days', '90d' => 'Last 90 days'] as $val => $lbl)
                    <option value="{{ $val }}" {{ $range === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                @endforeach
            </select>
            <div class="form-check form-switch mb-0 ms-1">
                <input class="form-check-input" type="checkbox" id="autoRefresh">
                <label class="form-check-label small text-muted" for="autoRefresh">Auto</label>
            </div>
            <span class="text-muted small ms-1" title="Generated">{{ $generatedAt->format('H:i:s') }}</span>
        </form>
    </div>

    {{-- Row A — counters --}}
    <div class="row g-2 mb-4">
        @foreach($counterDefs as $c)
        <div class="col-6 col-md-3 col-xl">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 d-flex align-items-center gap-2">
                    <i class="bi {{ $c['icon'] }} fs-4 {{ $toneClass[$c['tone']] ?? 'text-secondary' }}"></i>
                    <div>
                        <div class="fs-4 fw-bold lh-1">{{ $counters[$c['key']] ?? 0 }}</div>
                        <div class="text-muted" style="font-size:.72rem">{{ $c['label'] }}</div>
                    </div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Row B — gauges / donuts --}}
    <div class="row g-3 mb-4">
        @foreach([
            ['id' => 'g_devices', 'title' => 'Devices', 'data' => $gauges['devices'] ?? []],
            ['id' => 'g_aps', 'title' => 'Access Points', 'data' => $gauges['aps'] ?? []],
            ['id' => 'g_vpn', 'title' => 'VPN Tunnels', 'data' => $gauges['vpn'] ?? []],
            ['id' => 'g_hosts', 'title' => 'Monitored Hosts', 'data' => $gauges['hosts'] ?? []],
            ['id' => 'g_printers', 'title' => 'Printers', 'data' => $gauges['printers'] ?? []],
        ] as $g)
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="text-muted small mb-2">{{ $g['title'] }}</div>
                    @if(empty($g['data']))
                        <div class="text-muted small py-4">No data</div>
                    @else
                        <canvas id="{{ $g['id'] }}" height="140" data-donut='@json($g['data'])'></canvas>
                    @endif
                </div>
            </div>
        </div>
        @endforeach
        <div class="col-6 col-md-4 col-xl-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-2 text-center">Sophos Firewalls</div>
                    @php $sf = $gauges['sophos_fw'] ?? []; @endphp
                    <div class="d-flex justify-content-between small"><span>Connected</span><strong class="text-success">{{ $sf['connected'] ?? 0 }}</strong></div>
                    <div class="d-flex justify-content-between small"><span>Disconnected</span><strong class="text-danger">{{ $sf['disconnected'] ?? 0 }}</strong></div>
                    <div class="d-flex justify-content-between small"><span>Firmware update</span><strong class="text-warning">{{ $sf['fw_upgrade'] ?? 0 }}</strong></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row C — time-series charts (lazy) --}}
    <div class="row g-3 mb-4">
        @foreach([
            ['id' => 'c_events', 'metric' => 'events', 'title' => 'NOC Events by Severity', 'type' => 'area'],
            ['id' => 'c_syslog', 'metric' => 'syslog', 'title' => 'Syslog Volume by Severity', 'type' => 'area'],
            ['id' => 'c_isp', 'metric' => 'isp_latency', 'title' => 'ISP Latency & Packet Loss', 'type' => 'line'],
            ['id' => 'c_calls', 'metric' => 'calls', 'title' => 'UCM Call Volume', 'type' => 'bar'],
            ['id' => 'c_backups', 'metric' => 'backups', 'title' => 'Backups (Uploaded vs Failed)', 'type' => 'bar'],
            ['id' => 'c_ap_uptime', 'metric' => 'ap_uptime', 'title' => 'Access Point Uptime %', 'type' => 'line'],
            ['id' => 'c_host_uptime', 'metric' => 'host_uptime', 'title' => 'Host Uptime %', 'type' => 'line'],
            ['id' => 'c_vpn_uptime', 'metric' => 'vpn_uptime', 'title' => 'VPN Uptime %', 'type' => 'line'],
        ] as $chart)
        <div class="col-12 col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold small">{{ $chart['title'] }}</div>
                <div class="card-body">
                    <canvas id="{{ $chart['id'] }}" height="110"
                            data-metric="{{ $chart['metric'] }}" data-type="{{ $chart['type'] }}"></canvas>
                    <div class="text-muted small text-center chart-empty d-none">No data for this range.</div>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Row D — live tables --}}
    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold small"><i class="bi bi-bell me-1"></i>Open Events</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <tbody>
                        @forelse($tables['recent_events'] ?? [] as $e)
                            <tr>
                                <td><span class="badge {{ $e->severity === 'critical' ? 'bg-danger' : ($e->severity === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark') }}">{{ ucfirst($e->severity) }}</span></td>
                                <td class="small">{{ $e->title }}</td>
                                <td class="small text-muted text-nowrap">{{ \Illuminate\Support\Carbon::parse($e->last_seen)->diffForHumans(short: true) }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3 small">No open events 🎉</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold small"><i class="bi bi-router me-1"></i>APs Down</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <tbody>
                        @forelse($tables['down_aps'] ?? [] as $ap)
                            <tr><td class="small fw-semibold">{{ $ap->name }}</td><td class="small text-muted">{{ $ap->site }}</td></tr>
                        @empty
                            <tr><td class="text-center text-muted py-3 small">All APs up</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold small"><i class="bi bi-calendar-x me-1"></i>Expiring Soon (≤30d)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0 align-middle">
                        <tbody>
                        @forelse($tables['expiring'] ?? [] as $x)
                            <tr>
                                <td><span class="badge bg-light text-dark border">{{ $x['type'] }}</span></td>
                                <td class="small">{{ $x['name'] }}</td>
                                <td class="small text-muted text-nowrap">{{ \Illuminate\Support\Carbon::parse($x['date'])->format('d M') }}</td>
                            </tr>
                        @empty
                            <tr><td class="text-center text-muted py-3 small">Nothing expiring</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Row E — branch matrix --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent fw-semibold small"><i class="bi bi-grid-3x3-gap me-1"></i>Branch Health Matrix</div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr><th>Branch</th><th>Access Points</th><th>Hosts</th><th>VPN Tunnels</th></tr>
                </thead>
                <tbody>
                @php
                    $cell = function ($up, $total) {
                        if ($total === 0) return '<span class="text-muted">—</span>';
                        $cls = $up === $total ? 'success' : ($up === 0 ? 'danger' : 'warning');
                        return "<span class=\"badge bg-{$cls}" . ($cls === 'warning' ? ' text-dark' : '') . "\">{$up}/{$total}</span>";
                    };
                @endphp
                @forelse($matrix as $row)
                    <tr>
                        <td class="fw-semibold">{{ $row['name'] }}</td>
                        <td>{!! $cell($row['aps_up'], $row['aps_total']) !!}</td>
                        <td>{!! $cell($row['hosts_up'], $row['hosts_total']) !!}</td>
                        <td>{!! $cell($row['vpn_up'], $row['vpn_total']) !!}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-3 small">No branches.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2"><i class="bi bi-info-circle me-1"></i>
        Counters &amp; gauges cached ~60s. Uptime charts fill in over time from the hourly snapshot job
        (<code>noc:snapshot-availability</code>). Events are tenant-wide (not branch-scoped).
    </p>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const root = document.getElementById('noc-overview');
    const chartUrl = root.dataset.chartUrl;
    const branch = root.dataset.branch;
    const range = root.dataset.range;
    const palette = ['#0d6efd', '#dc3545', '#ffc107', '#198754', '#6f42c1', '#fd7e14', '#20c997', '#6c757d'];
    const sevColors = { critical: '#dc3545', warning: '#ffc107', info: '#0dcaf0', 'Uptime %': '#198754',
                        'Latency (ms)': '#0d6efd', 'Packet loss (%)': '#dc3545', Uploaded: '#198754', Failed: '#dc3545', Calls: '#0d6efd' };

    // Donuts (Row B) — data is { label: count }
    document.querySelectorAll('canvas[data-donut]').forEach(cv => {
        const data = JSON.parse(cv.dataset.donut);
        const labels = Object.keys(data);
        new Chart(cv, {
            type: 'doughnut',
            data: { labels, datasets: [{ data: Object.values(data),
                backgroundColor: labels.map((l, i) => l === 'up' || l === 'connected' || l === 'active' ? '#198754'
                    : (l === 'down' || l === 'error' ? '#dc3545' : palette[i % palette.length])) }] },
            options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } },
                       cutout: '60%', responsive: true, maintainAspectRatio: false }
        });
    });

    // Time-series (Row C) — lazy fetch per chart
    document.querySelectorAll('canvas[data-metric]').forEach(cv => {
        const metric = cv.dataset.metric;
        const type = cv.dataset.type;
        const empty = cv.parentElement.querySelector('.chart-empty');
        fetch(`${chartUrl}?metric=${metric}&range=${range}&branch=${branch}`, { headers: { Accept: 'application/json' } })
            .then(r => r.json())
            .then(payload => {
                const labels = payload.labels || [];
                const series = payload.series || {};
                if (!labels.length || !Object.keys(series).length) { cv.classList.add('d-none'); empty?.classList.remove('d-none'); return; }
                const datasets = Object.entries(series).map(([name, vals], i) => ({
                    label: name,
                    data: vals,
                    borderColor: sevColors[name] || palette[i % palette.length],
                    backgroundColor: (sevColors[name] || palette[i % palette.length]) + (type === 'area' ? '55' : 'cc'),
                    fill: type === 'area',
                    stack: (type === 'area' || type === 'bar') ? 'a' : undefined,
                    tension: 0.3, borderWidth: 2, pointRadius: 0,
                }));
                new Chart(cv, {
                    type: type === 'bar' ? 'bar' : 'line',
                    data: { labels, datasets },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        interaction: { mode: 'index', intersect: false },
                        plugins: { legend: { labels: { boxWidth: 12, font: { size: 10 } } } },
                        scales: {
                            x: { ticks: { maxTicksLimit: 8, font: { size: 9 } }, stacked: type === 'area' || type === 'bar' },
                            y: { beginAtZero: true, ticks: { font: { size: 9 } }, stacked: type === 'area' || type === 'bar' }
                        }
                    }
                });
            })
            .catch(() => { cv.classList.add('d-none'); empty?.classList.remove('d-none'); });
    });

    // Auto-refresh (30s)
    const auto = document.getElementById('autoRefresh');
    const KEY = 'noc_overview_auto';
    if (localStorage.getItem(KEY) === '1') auto.checked = true;
    let timer = auto.checked ? setInterval(() => location.reload(), 30000) : null;
    auto.addEventListener('change', () => {
        localStorage.setItem(KEY, auto.checked ? '1' : '0');
        if (auto.checked) { timer = setInterval(() => location.reload(), 30000); }
        else { clearInterval(timer); }
    });
})();
</script>
@endpush
