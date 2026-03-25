@extends('layouts.admin')
@section('title', 'Voice Quality Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Voice Quality Dashboard</h4>
        <small class="text-muted">Today's call quality metrics — {{ today()->format('d M Y') }}</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.voice-quality.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list me-1"></i>All Reports</a>
        <a href="{{ route('admin.voice-quality.statistics') }}" class="btn btn-sm btn-outline-info"><i class="bi bi-bar-chart me-1"></i>Statistics</a>
    </div>
</div>

{{-- KPI Cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $avgMos >= 4.0 ? 'success' : ($avgMos >= 3.6 ? 'warning' : 'danger') }}">
                    {{ $avgMos ? number_format($avgMos, 2) : '—' }}
                </div>
                <div class="small text-muted mt-1">Avg MOS-LQ (Today)</div>
                <div class="mt-1">
                    @if($avgMos)
                    <span class="badge bg-{{ $avgMos >= 4.0 ? 'success' : ($avgMos >= 3.6 ? 'warning' : 'danger') }}">
                        {{ $avgMos >= 4.3 ? 'Excellent' : ($avgMos >= 4.0 ? 'Good' : ($avgMos >= 3.6 ? 'Fair' : ($avgMos >= 3.0 ? 'Poor' : 'Bad'))) }}
                    </span>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ number_format($totalCalls) }}</div>
                <div class="small text-muted mt-1">Total Calls</div>
                <div class="mt-1"><span class="badge bg-success">{{ number_format($excellentCalls) }} excellent</span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-danger">{{ number_format($poorCalls) }}</div>
                <div class="small text-muted mt-1">Poor Calls (MOS &lt; 3.0)</div>
                <div class="mt-1">
                    @if($totalCalls > 0)
                    <span class="badge bg-{{ $poorCalls / $totalCalls > 0.1 ? 'danger' : 'secondary' }}">
                        {{ number_format($poorCalls / $totalCalls * 100, 1) }}%
                    </span>
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $activeAlerts->count() > 0 ? 'warning' : 'success' }}">{{ $activeAlerts->count() }}</div>
                <div class="small text-muted mt-1">Active Alerts</div>
                <div class="mt-1">
                    <span class="badge bg-{{ $activeAlerts->where('severity','critical')->count() > 0 ? 'danger' : 'secondary' }}">
                        {{ $activeAlerts->where('severity','critical')->count() }} critical
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Hourly MOS Chart --}}
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-graph-up me-1"></i>Hourly MOS-LQ Trend</span>
                <span class="badge bg-secondary" id="chart-refresh-badge">Auto-refresh every 60s</span>
            </div>
            <div class="card-body">
                <canvas id="hourlyMosChart" height="120"></canvas>
            </div>
        </div>
    </div>

    {{-- Quality Distribution Doughnut --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-pie-chart me-1"></i>Quality Distribution</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                @if($qualityDistribution->sum() > 0)
                <canvas id="qualityDoughnut" width="220" height="220"></canvas>
                @else
                <div class="text-muted text-center"><i class="bi bi-telephone-x display-4 d-block mb-2"></i>No calls today</div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Branch MOS --}}
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-building me-1"></i>MOS by Branch</div>
            <div class="card-body">
                @forelse($byBranch as $b)
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-semibold">{{ $b->branch ?? 'Unknown' }}</span>
                        <span class="text-{{ $b->avg_mos >= 4.0 ? 'success' : ($b->avg_mos >= 3.6 ? 'warning' : 'danger') }}">
                            {{ number_format($b->avg_mos, 2) }} ({{ $b->call_count }} calls)
                        </span>
                    </div>
                    <div class="progress" style="height:8px;">
                        <div class="progress-bar bg-{{ $b->avg_mos >= 4.0 ? 'success' : ($b->avg_mos >= 3.6 ? 'warning' : 'danger') }}"
                             style="width:{{ min(100, ($b->avg_mos / 5.0) * 100) }}%"></div>
                    </div>
                </div>
                @empty
                <div class="text-muted text-center small py-3">No branch data today</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Codec Stats --}}
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-file-earmark-code me-1"></i>Codec Statistics</div>
            <div class="card-body p-0">
                @if($codecStats->isEmpty())
                <div class="text-muted text-center py-4 small">No codec data today</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th>Codec</th><th>Calls</th><th>Avg MOS</th><th>Avg Jitter</th></tr>
                        </thead>
                        <tbody>
                            @foreach($codecStats as $c)
                            <tr>
                                <td class="fw-semibold font-monospace">{{ $c->codec }}</td>
                                <td>{{ $c->calls }}</td>
                                <td>
                                    <span class="badge bg-{{ $c->avg_mos >= 4.0 ? 'success' : ($c->avg_mos >= 3.6 ? 'warning' : 'danger') }}">
                                        {{ number_format($c->avg_mos, 2) }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ $c->avg_jitter ? number_format($c->avg_jitter, 1).'ms' : '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Worst Calls --}}
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Worst Calls Today</div>
            <div class="card-body p-0">
                @if($worstCalls->isEmpty())
                <div class="text-muted text-center py-4 small"><i class="bi bi-check-circle text-success d-block mb-1"></i>No poor calls today</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th>Extension</th><th>Remote</th><th>Branch</th><th>MOS</th><th>Jitter</th><th>Loss</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($worstCalls as $r)
                            <tr>
                                <td class="fw-semibold">{{ $r->extension }}</td>
                                <td class="text-muted">{{ $r->remote_extension ?: '—' }}</td>
                                <td>{{ $r->branch ?: '—' }}</td>
                                <td>
                                    <span class="badge bg-{{ $r->mos_lq >= 4.0 ? 'success' : ($r->mos_lq >= 3.6 ? 'warning' : 'danger') }}">
                                        {{ $r->mos_lq ? number_format($r->mos_lq, 2) : '—' }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ $r->jitter_avg ? number_format($r->jitter_avg, 1).'ms' : '—' }}</td>
                                <td class="text-muted">{{ $r->packet_loss ? number_format($r->packet_loss, 1).'%' : '—' }}</td>
                                <td><a href="{{ route('admin.voice-quality.show', $r) }}" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-1"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Active Alerts --}}
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-bell text-warning me-1"></i>Active Voice Alerts</span>
                <span class="badge bg-{{ $activeAlerts->count() > 0 ? 'warning text-dark' : 'secondary' }}">{{ $activeAlerts->count() }}</span>
            </div>
            <div class="card-body p-0">
                @forelse($activeAlerts as $alert)
                <div class="px-3 py-2 border-bottom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <span class="badge bg-{{ $alert->severity === 'critical' ? 'danger' : 'warning text-dark' }} me-1">{{ $alert->severity }}</span>
                            <span class="fw-semibold small">{{ $alert->source_ref }}</span>
                        </div>
                        <small class="text-muted">{{ $alert->created_at->diffForHumans() }}</small>
                    </div>
                    <div class="text-muted small mt-1">{{ Str::limit($alert->message, 80) }}</div>
                </div>
                @empty
                <div class="text-muted text-center py-4 small"><i class="bi bi-check-circle-fill text-success d-block mb-1"></i>No active alerts</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const hourlyData = @json($hourlyTrend);
const hourlyLabels = Array.from({length: 24}, (_, i) => i + ':00');
const hourlyValues = new Array(24).fill(null);
hourlyData.forEach(d => { hourlyValues[d.hour] = parseFloat(d.avg_mos) || null; });

const mosCtx = document.getElementById('hourlyMosChart');
if (mosCtx) {
    const mosChart = new Chart(mosCtx, {
        type: 'line',
        data: {
            labels: hourlyLabels,
            datasets: [{
                label: 'Avg MOS-LQ',
                data: hourlyValues,
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13,110,253,0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                spanGaps: true,
            }]
        },
        options: {
            plugins: { legend: { display: false } },
            scales: {
                y: { min: 1, max: 5, title: { display: true, text: 'MOS-LQ' } },
                x: { ticks: { maxRotation: 0 } }
            },
            responsive: true,
            maintainAspectRatio: true,
        }
    });

    // Auto-refresh every 60 seconds
    setInterval(function() {
        const date = '{{ today()->toDateString() }}';
        fetch('/admin/voice-quality/chart-data?type=hourly&date=' + date)
            .then(r => r.json())
            .then(data => {
                const vals = new Array(24).fill(null);
                data.forEach(d => { vals[d.label] = parseFloat(d.value) || null; });
                mosChart.data.datasets[0].data = vals;
                mosChart.update();
            });
    }, 60000);
}

@if($qualityDistribution->sum() > 0)
const qualityLabels = ['Excellent','Good','Fair','Poor','Bad'];
const qualityKeys   = ['excellent','good','fair','poor','bad'];
const qualityColors = ['#198754','#0dcaf0','#ffc107','#fd7e14','#dc3545'];
const qualityDist   = @json($qualityDistribution);
const qualityValues = qualityKeys.map(k => qualityDist[k] || 0);

new Chart(document.getElementById('qualityDoughnut'), {
    type: 'doughnut',
    data: {
        labels: qualityLabels,
        datasets: [{ data: qualityValues, backgroundColor: qualityColors, borderWidth: 2 }]
    },
    options: {
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
        cutout: '60%'
    }
});
@endif
</script>
@endpush
