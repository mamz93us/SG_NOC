@extends('layouts.portal')

@section('title', 'Campaign benchmark')

@section('content')
<div class="container-fluid py-4">
    <h3 class="mb-3"><i class="bi bi-envelope-paper me-2"></i>Email Marketing</h3>
    @include('portal.email-marketing._nav')

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-bar-chart-line me-2"></i>Campaign benchmark</h4>
        <a href="{{ route('portal.marketing.campaigns.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>All campaigns
        </a>
    </div>

    {{-- ── Picker: campaigns + metric ────────────────────────────── --}}
    <form method="GET" class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label">Campaigns to compare</label>
                    <select name="campaigns[]" class="form-select" multiple size="6"
                            style="height: auto;">
                        @foreach ($catalog as $c)
                            <option value="{{ $c->id }}"
                                @selected(in_array($c->id, $selectedIds))>
                                {{ $c->name }}
                                @if ($c->sent_at) — sent {{ $c->sent_at->format('Y-m-d') }} @endif
                                ({{ $c->total_sent }} sent)
                            </option>
                        @endforeach
                    </select>
                    <small class="text-muted">Ctrl-click / Cmd-click to multi-select. Defaults to the 5 most recent sent campaigns.</small>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Metric</label>
                    <select name="metric" class="form-select">
                        @foreach ($metrics as $key => $m)
                            <option value="{{ $key }}" @selected($metric === $key)>{{ $m['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100"><i class="bi bi-bar-chart me-1"></i>Compare</button>
                </div>
            </div>
        </div>
    </form>

    @if ($selected->isEmpty())
        <div class="alert alert-info">No campaigns selected. Pick at least one from the list above.</div>
    @else
        {{-- ── Summary table ────────────────────────────────────── --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-light">
                <strong>Campaigns selected</strong>
                <small class="text-muted ms-2">All rates derived from email_campaigns counter columns.</small>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Campaign</th>
                            <th>Sent on</th>
                            <th>List</th>
                            <th class="text-end">Sent</th>
                            <th class="text-end">Delivery</th>
                            <th class="text-end">Open</th>
                            <th class="text-end">Click</th>
                            <th class="text-end">Bounce</th>
                            <th class="text-end">Complaint</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $r)
                        <tr>
                            <td>
                                <a href="{{ route('portal.marketing.campaigns.show', $r['id']) }}"><strong>{{ $r['name'] }}</strong></a>
                            </td>
                            <td><small>{{ $r['sent_at'] }}</small></td>
                            <td><small>{{ $r['list'] }}</small></td>
                            <td class="text-end">{{ number_format($r['total_sent']) }}</td>
                            <td class="text-end">{{ $r['delivery_rate'] }}%</td>
                            <td class="text-end {{ $metric === 'open_rate' ? 'fw-bold' : '' }}">{{ $r['open_rate'] }}%</td>
                            <td class="text-end {{ $metric === 'click_rate' ? 'fw-bold' : '' }}">{{ $r['click_rate'] }}%</td>
                            <td class="text-end {{ $metric === 'bounce_rate' ? 'fw-bold text-danger' : '' }}">{{ $r['bounce_rate'] }}%</td>
                            <td class="text-end {{ $metric === 'complaint_rate' ? 'fw-bold text-warning' : '' }}">{{ $r['complaint_rate'] }}%</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ── Bar chart ─────────────────────────────────────────── --}}
        <div class="card shadow-sm">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-bar-chart me-1"></i>{{ $metricLabel }} comparison</strong>
                <small class="text-muted">{{ $selected->count() }} campaign(s)</small>
            </div>
            <div class="card-body">
                <canvas id="benchmark-chart" height="{{ max(200, $selected->count() * 50) }}"></canvas>
            </div>
        </div>
    @endif
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
<script>
(function () {
    const rows = @json($rows);
    if (!rows.length) return;

    const labels = rows.map(r => r.name);
    const data   = rows.map(r => r.value);
    const color  = @json($metricColor);
    const label  = @json($metricLabel);

    new Chart(document.getElementById('benchmark-chart'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: label + ' (%)',
                data:  data,
                backgroundColor: color,
                borderColor:     color,
                borderWidth: 1,
            }],
        },
        options: {
            indexAxis: 'y',  // horizontal bars
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { callback: v => v + '%' },
                },
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return label + ': ' + ctx.parsed.x + '%';
                        },
                    },
                },
            },
        },
    });
})();
</script>
@endsection
