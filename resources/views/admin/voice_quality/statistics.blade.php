@extends('layouts.admin')
@section('title', 'Voice Quality Statistics')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2 text-info"></i>Voice Quality Statistics</h4>
        <small class="text-muted">30-day analysis of call quality trends</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.voice-quality.dashboard') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.voice-quality.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list me-1"></i>Reports</a>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Daily MOS Trend --}}
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Daily Avg MOS-LQ (30 days)</div>
            <div class="card-body">
                <canvas id="dailyMosChart" height="120"></canvas>
            </div>
        </div>
    </div>

    {{-- Quality Distribution --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Quality Distribution (30 days)</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <canvas id="qualityDistChart" width="220" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Branch Comparison --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Avg MOS by Branch (30 days)</div>
            <div class="card-body">
                <canvas id="branchMosChart" height="180"></canvas>
            </div>
        </div>
    </div>

    {{-- Peak Hours --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Avg MOS by Hour of Day</div>
            <div class="card-body">
                <canvas id="peakHoursChart" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Codec Comparison --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Codec Performance (30 days)</div>
            <div class="card-body">
                <canvas id="codecChart" height="180"></canvas>
            </div>
        </div>
    </div>

    {{-- Call volume --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Daily Call Volume (30 days)</div>
            <div class="card-body">
                <canvas id="callVolumeChart" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Worst Extensions --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-arrow-down-circle text-danger me-1"></i>Worst Extensions (7 days)</div>
            <div class="card-body p-0">
                @if($worstExtensions->isEmpty())
                <div class="text-muted text-center py-4 small">No data</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Extension</th><th>Branch</th><th>Avg MOS</th><th>Avg Jitter</th><th>Calls</th></tr></thead>
                        <tbody>
                            @foreach($worstExtensions as $e)
                            <tr>
                                <td class="fw-semibold">{{ $e->extension }}</td>
                                <td class="text-muted">{{ $e->branch ?: '—' }}</td>
                                <td><span class="badge bg-{{ $e->avg_mos >= 4.0 ? 'success' : ($e->avg_mos >= 3.6 ? 'warning' : 'danger') }}">{{ number_format($e->avg_mos, 2) }}</span></td>
                                <td class="text-muted">{{ $e->avg_jitter ? number_format($e->avg_jitter, 1).'ms' : '—' }}</td>
                                <td class="text-muted">{{ $e->calls }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Best Extensions --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-arrow-up-circle text-success me-1"></i>Best Extensions (7 days)</div>
            <div class="card-body p-0">
                @if($bestExtensions->isEmpty())
                <div class="text-muted text-center py-4 small">No data</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Extension</th><th>Branch</th><th>Avg MOS</th><th>Calls</th></tr></thead>
                        <tbody>
                            @foreach($bestExtensions as $e)
                            <tr>
                                <td class="fw-semibold">{{ $e->extension }}</td>
                                <td class="text-muted">{{ $e->branch ?: '—' }}</td>
                                <td><span class="badge bg-{{ $e->avg_mos >= 4.0 ? 'success' : ($e->avg_mos >= 3.6 ? 'warning' : 'danger') }}">{{ number_format($e->avg_mos, 2) }}</span></td>
                                <td class="text-muted">{{ $e->calls }}</td>
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
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const dailyTrend = @json($dailyTrend);
const branchComp = @json($branchComparison);
const codecComp  = @json($codecComparison);
const peakHours  = @json($peakHours);
const qualityDist = @json($qualityDistribution);

// Daily MOS Trend
new Chart(document.getElementById('dailyMosChart'), {
    type: 'line',
    data: {
        labels: dailyTrend.map(d => d.date),
        datasets: [{
            label: 'Avg MOS-LQ',
            data: dailyTrend.map(d => parseFloat(d.avg_mos)),
            borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)',
            fill: true, tension: 0.3, pointRadius: 3
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { min: 1, max: 5, title: { display: true, text: 'MOS-LQ' } } },
        responsive: true, maintainAspectRatio: true
    }
});

// Quality Distribution doughnut
const qualityKeys = ['excellent','good','fair','poor','bad'];
const qualityLabels = ['Excellent','Good','Fair','Poor','Bad'];
const qualityColors = ['#198754','#0dcaf0','#ffc107','#fd7e14','#dc3545'];
new Chart(document.getElementById('qualityDistChart'), {
    type: 'doughnut',
    data: {
        labels: qualityLabels,
        datasets: [{ data: qualityKeys.map(k => qualityDist[k] || 0), backgroundColor: qualityColors, borderWidth: 2 }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }, cutout: '60%' }
});

// Branch MOS bar (horizontal)
new Chart(document.getElementById('branchMosChart'), {
    type: 'bar',
    data: {
        labels: branchComp.map(b => b.branch || 'Unknown'),
        datasets: [{
            label: 'Avg MOS-LQ',
            data: branchComp.map(b => parseFloat(b.avg_mos)),
            backgroundColor: branchComp.map(b => parseFloat(b.avg_mos) >= 4.0 ? '#19875488' : (parseFloat(b.avg_mos) >= 3.6 ? '#ffc10788' : '#dc354588')),
            borderColor: branchComp.map(b => parseFloat(b.avg_mos) >= 4.0 ? '#198754' : (parseFloat(b.avg_mos) >= 3.6 ? '#ffc107' : '#dc3545')),
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { min: 0, max: 5, title: { display: true, text: 'MOS-LQ' } } }
    }
});

// Peak hours
const hourlyLabels = Array.from({length: 24}, (_, i) => i + ':00');
const hourlyVals = new Array(24).fill(null);
peakHours.forEach(d => { hourlyVals[d.hour] = parseFloat(d.avg_mos) || null; });
new Chart(document.getElementById('peakHoursChart'), {
    type: 'bar',
    data: {
        labels: hourlyLabels,
        datasets: [{
            label: 'Avg MOS-LQ',
            data: hourlyVals,
            backgroundColor: hourlyVals.map(v => !v ? '#e9ecef' : (v >= 4.0 ? '#19875488' : (v >= 3.6 ? '#ffc10788' : '#dc354588'))),
            borderWidth: 0
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { min: 0, max: 5 } },
        spanGaps: true
    }
});

// Codec chart
new Chart(document.getElementById('codecChart'), {
    type: 'bar',
    data: {
        labels: codecComp.map(c => c.codec || 'Unknown'),
        datasets: [
            { label: 'Avg MOS-LQ', data: codecComp.map(c => parseFloat(c.avg_mos)), backgroundColor: '#0d6efd88', borderColor: '#0d6efd', borderWidth: 1, yAxisID: 'y' },
            { label: 'Avg Jitter (ms)', data: codecComp.map(c => parseFloat(c.avg_jitter)), backgroundColor: '#fd7e1488', borderColor: '#fd7e14', borderWidth: 1, yAxisID: 'y2' },
        ]
    },
    options: {
        plugins: { legend: { position: 'top' } },
        scales: {
            y:  { position: 'left',  title: { display: true, text: 'MOS-LQ' }, min: 0, max: 5 },
            y2: { position: 'right', title: { display: true, text: 'Jitter ms' }, grid: { drawOnChartArea: false } }
        }
    }
});

// Call Volume
new Chart(document.getElementById('callVolumeChart'), {
    type: 'bar',
    data: {
        labels: dailyTrend.map(d => d.date),
        datasets: [{ label: 'Calls', data: dailyTrend.map(d => d.calls), backgroundColor: '#6610f288', borderColor: '#6610f2', borderWidth: 1 }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>
@endpush
