@extends('layouts.admin')
@section('content')

@php
    $appMeta = [
        'noc'    => ['label' => 'NOC',    'icon' => 'bi-speedometer2', 'color' => '#0d6efd'],
        'em'     => ['label' => 'EM',     'icon' => 'bi-envelope-paper', 'color' => '#6610f2'],
        'portal' => ['label' => 'Portal', 'icon' => 'bi-person-badge', 'color' => '#20c997'],
    ];
    $eventBadge = fn ($e) => $e === 'login'
        ? '<span class="badge bg-primary-subtle text-primary">login</span>'
        : '<span class="badge bg-secondary-subtle text-secondary">access</span>';
@endphp

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>Access Analytics</h4>
        <small class="text-muted">Who's signing in to and using the <strong>NOC</strong>, <strong>EM</strong> and employee <strong>Portal</strong></small>
    </div>
    <a href="{{ route('admin.access-stats.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-download me-1"></i>Export CSV
    </a>
</div>

{{-- ── KPI cards ─────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    @foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'] as $key => $label)
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase">{{ $label }}</div>
                <div class="d-flex align-items-end gap-3 mt-1">
                    <div>
                        <div class="fs-3 fw-bold lh-1 text-primary">{{ number_format($cards[$key]['users']) }}</div>
                        <div class="text-muted" style="font-size:.72rem">users</div>
                    </div>
                    <div>
                        <div class="fs-5 fw-semibold lh-1">{{ number_format($cards[$key]['logins']) }}</div>
                        <div class="text-muted" style="font-size:.72rem">logins</div>
                    </div>
                    <div>
                        <div class="fs-6 fw-semibold lh-1 text-muted">{{ number_format($cards[$key]['accesses']) }}</div>
                        <div class="text-muted" style="font-size:.72rem">events</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Per-app summary ──────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    @foreach ($appMeta as $appKey => $meta)
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <i class="bi {{ $meta['icon'] }} fs-2" style="color: {{ $meta['color'] }}"></i>
                <div>
                    <div class="fw-bold">{{ $meta['label'] }}</div>
                    <div class="small text-muted">
                        <span class="fw-semibold text-body">{{ number_format($byApp[$appKey]['users'] ?? 0) }}</span> users ·
                        <span class="fw-semibold text-body">{{ number_format($byApp[$appKey]['accesses'] ?? 0) }}</span> events
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Time series (stacked by app) ─────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-graph-up me-1"></i>Access per day by app (last 30 days)</div>
    <div class="card-body"><canvas id="avDaily" height="90"></canvas></div>
</div>

<div class="row g-3 mb-4">
    {{-- Top users --}}
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-trophy me-1"></i>Most active users</div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr><th>User</th><th>Apps</th><th class="text-end">Logins</th><th class="text-end">Events</th><th>Last seen</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($topUsers as $u)
                        <tr>
                            <td>
                                <div class="fw-semibold">{{ $u->user_name ?: '—' }}</div>
                                <div class="text-muted small">{{ $u->user_email }}</div>
                            </td>
                            <td>{{ $u->apps }}</td>
                            <td class="text-end">{{ number_format($u->logins) }}</td>
                            <td class="text-end fw-semibold">{{ number_format($u->accesses) }}</td>
                            <td class="text-nowrap small">{{ \Illuminate\Support\Carbon::parse($u->last_seen)->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                        </tr>
                        @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No access recorded yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- Branch + device --}}
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-pie-chart me-1"></i>By branch &amp; device</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6"><canvas id="avBranch" height="180"></canvas></div>
                    <div class="col-6"><canvas id="avDevice" height="180"></canvas></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Recent access (filterable, paginated) ────────────────── --}}
<div class="card shadow-sm">
    <div class="card-header bg-transparent">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">From</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from'] }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">To</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to'] }}">
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">App</label>
                <select name="app" class="form-select form-select-sm">
                    <option value="">All apps</option>
                    @foreach ($apps as $a)
                    <option value="{{ $a }}" {{ $filters['app'] === $a ? 'selected' : '' }}>{{ $appMeta[$a]['label'] ?? $a }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">Event</label>
                <select name="event" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="login" {{ $filters['event'] === 'login' ? 'selected' : '' }}>Login</option>
                    <option value="access" {{ $filters['event'] === 'access' ? 'selected' : '' }}>Access</option>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small mb-0 text-muted">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="user / email / IP" value="{{ $filters['q'] }}">
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-secondary">Filter</button>
                <a href="{{ route('admin.access-stats.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th><th>User</th><th>App</th><th>Event</th>
                    <th>Branch</th><th>IP</th><th>Browser</th><th>Device</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent as $r)
                <tr>
                    <td class="text-nowrap">{{ $r->occurred_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    <td>
                        <div class="fw-semibold">{{ $r->user_name ?: '—' }}</div>
                        <div class="text-muted small">{{ $r->user_email }}</div>
                    </td>
                    <td><span class="badge" style="background: {{ $appMeta[$r->app]['color'] ?? '#6c757d' }}">{{ $appMeta[$r->app]['label'] ?? $r->app }}</span></td>
                    <td>{!! $eventBadge($r->event) !!}</td>
                    <td>{{ $r->branch }}</td>
                    <td class="font-monospace small">{{ $r->ip_address }}</td>
                    <td>{{ $r->browser }}</td>
                    <td>{{ $r->device_type }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No access matches the filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-transparent">{{ $recent->links() }}</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
    const tick = isDark ? '#adb5bd' : '#495057';
    const grid = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    const palette = ['#0d6efd', '#6610f2', '#20c997', '#fd7e14', '#dc3545', '#0dcaf0', '#ffc107', '#198754'];
    const appColors = { noc: '#0d6efd', em: '#6610f2', portal: '#20c997' };

    const series = @json($series);
    new Chart(document.getElementById('avDaily'), {
        type: 'bar',
        data: {
            labels: series.labels,
            datasets: Object.keys(series.datasets).map(app => ({
                label: app.toUpperCase(),
                data: series.datasets[app],
                backgroundColor: appColors[app] || '#6c757d',
            }))
        },
        options: {
            plugins: { legend: { position: 'top', labels: { color: tick, boxWidth: 12 } } },
            scales: {
                x: { stacked: true, ticks: { color: tick, maxTicksLimit: 12 }, grid: { color: grid } },
                y: { stacked: true, beginAtZero: true, ticks: { color: tick, precision: 0 }, grid: { color: grid } }
            }
        }
    });

    const doughnut = (id, map) => new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: { labels: Object.keys(map), datasets: [{ data: Object.values(map), backgroundColor: palette }] },
        options: { plugins: { legend: { position: 'bottom', labels: { color: tick, boxWidth: 10, font: { size: 10 } } } } }
    });
    doughnut('avBranch', @json($byBranch));
    doughnut('avDevice', @json($byDevice));
})();
</script>

@endsection
