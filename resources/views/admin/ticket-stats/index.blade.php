@extends('layouts.admin')
@section('content')

@php
    $dows = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $maxHeat = 0;
    foreach ($heatmap as $row) { $maxHeat = max($maxHeat, ...$row); }
@endphp

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-ticket-detailed me-2 text-primary"></i>IT Ticket Portal — Visits</h4>
        <small class="text-muted">
            Analytics for <code>it.samirgroup.net</code> →
            <span class="text-truncate d-inline-block align-bottom" style="max-width:420px">{{ $destination }}</span>
            <span class="badge bg-secondary ms-1">{{ $forwardMode }}</span>
        </small>
    </div>
    <a href="{{ route('admin.ticket-stats.export', request()->query()) }}" class="btn btn-sm btn-outline-secondary">
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
                        <div class="fs-3 fw-bold lh-1">{{ number_format($cards[$key]['total']) }}</div>
                        <div class="text-muted" style="font-size:.72rem">visits</div>
                    </div>
                    <div>
                        <div class="fs-5 fw-semibold lh-1 text-primary">{{ number_format($cards[$key]['unique']) }}</div>
                        <div class="text-muted" style="font-size:.72rem">unique</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Time series ──────────────────────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-graph-up me-1"></i>Visits per day (last 30 days)</div>
    <div class="card-body"><canvas id="tvDaily" height="90"></canvas></div>
</div>

<div class="row g-3 mb-4">
    {{-- Branch breakdown --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-building me-1"></i>By branch</div>
            <div class="card-body">
                <canvas id="tvBranch" height="150"></canvas>
                <table class="table table-sm mt-3 mb-0">
                    <tbody>
                        @forelse ($byBranch as $name => $count)
                        <tr><td>{{ $name }}</td><td class="text-end fw-semibold">{{ number_format($count) }}</td></tr>
                        @empty
                        <tr><td class="text-muted text-center py-3">No data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    {{-- Browser + device --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-window me-1"></i>By browser &amp; device</div>
            <div class="card-body">
                <div class="row">
                    <div class="col-6"><canvas id="tvBrowser" height="180"></canvas></div>
                    <div class="col-6"><canvas id="tvDevice" height="180"></canvas></div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- ── Peak hours heatmap ───────────────────────────────────── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-calendar3 me-1"></i>Peak hours (hour-of-day × day-of-week, last 30 days)</div>
    <div class="card-body table-responsive">
        <table class="table table-sm text-center align-middle mb-0" style="font-size:.7rem">
            <thead>
                <tr>
                    <th></th>
                    @for ($h = 0; $h < 24; $h++)<th class="text-muted fw-normal">{{ $h }}</th>@endfor
                </tr>
            </thead>
            <tbody>
                @foreach ($heatmap as $dow => $hours)
                <tr>
                    <th class="text-muted fw-normal text-end">{{ $dows[$dow] }}</th>
                    @foreach ($hours as $count)
                    @php $intensity = $maxHeat > 0 ? $count / $maxHeat : 0; @endphp
                    <td style="background: rgba(13,110,253,{{ $count > 0 ? round(0.12 + $intensity * 0.78, 2) : 0 }}); color: {{ $intensity > 0.5 ? '#fff' : 'inherit' }}"
                        title="{{ $dows[$dow] }} {{ $loop->index }}:00 — {{ $count }} visit(s)">{{ $count ?: '' }}</td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ── Recent visits (filterable, paginated) ────────────────── --}}
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
                <label class="form-label small mb-0 text-muted">Branch</label>
                <select name="branch" class="form-select form-select-sm">
                    <option value="">All branches</option>
                    @foreach ($branches as $b)
                    <option value="{{ $b }}" {{ $filters['branch'] === $b ? 'selected' : '' }}>{{ $b }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-secondary">Filter</button>
                <a href="{{ route('admin.ticket-stats.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Time</th><th>Branch</th><th>IP</th><th>Browser</th>
                    <th>Platform</th><th>Device</th><th>Unique</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($recent as $v)
                <tr>
                    <td class="text-nowrap">{{ $v->visited_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') }}</td>
                    <td>{{ $v->branch }}</td>
                    <td class="font-monospace small">{{ $v->ip_address }}</td>
                    <td>{{ $v->browser }}</td>
                    <td>{{ $v->platform }}</td>
                    <td>{{ $v->device_type }}</td>
                    <td>@if($v->is_unique_today)<span class="badge bg-success-subtle text-success">new</span>@endif</td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No visits match the filters.</td></tr>
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
    const tick   = isDark ? '#adb5bd' : '#495057';
    const grid   = isDark ? 'rgba(255,255,255,.08)' : 'rgba(0,0,0,.06)';
    const palette = ['#0d6efd', '#6610f2', '#198754', '#fd7e14', '#dc3545', '#0dcaf0', '#ffc107', '#20c997'];

    const daily = @json($series);
    new Chart(document.getElementById('tvDaily'), {
        type: 'line',
        data: {
            labels: daily.labels,
            datasets: [{
                data: daily.values, label: 'Visits', tension: .3,
                borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,.12)', fill: true, pointRadius: 2,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: tick, maxTicksLimit: 12 }, grid: { color: grid } },
                y: { beginAtZero: true, ticks: { color: tick, precision: 0 }, grid: { color: grid } }
            }
        }
    });

    new Chart(document.getElementById('tvBranch'), {
        type: 'bar',
        data: {
            labels: @json($byBranch->keys()),
            datasets: [{ data: @json($byBranch->values()), backgroundColor: palette }]
        },
        options: {
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { beginAtZero: true, ticks: { color: tick, precision: 0 }, grid: { color: grid } },
                y: { ticks: { color: tick }, grid: { display: false } }
            }
        }
    });

    const doughnut = (id, map) => new Chart(document.getElementById(id), {
        type: 'doughnut',
        data: { labels: Object.keys(map), datasets: [{ data: Object.values(map), backgroundColor: palette }] },
        options: { plugins: { legend: { position: 'bottom', labels: { color: tick, boxWidth: 10, font: { size: 10 } } } } }
    });
    doughnut('tvBrowser', @json($byBrowser));
    doughnut('tvDevice', @json($byDevice));
})();
</script>

@endsection
