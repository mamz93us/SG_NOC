@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h4 class="mb-1 fw-bold">
                <i class="bi bi-graph-up me-2 text-primary"></i>{{ $isp->provider }} — SLA Detail
            </h4>
            <small class="text-muted">
                <a href="{{ route('admin.network.sla.index') }}" class="text-decoration-none">SLA Dashboard</a>
                / {{ $isp->provider }} ({{ $isp->circuit_id ?: 'N/A' }})
                &middot; {{ $isp->branch?->name ?: 'Unassigned' }}
            </small>
        </div>
        <a href="{{ route('admin.network.sla.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

{{-- Stats Row --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-4 {{ $uptime >= 99 ? 'border-success' : ($uptime >= 95 ? 'border-warning' : 'border-danger') }}">
            <div class="card-body text-center">
                <div class="text-muted small">Monthly Uptime</div>
                <div class="fs-1 fw-bold {{ $uptime >= 99 ? 'text-success' : ($uptime >= 95 ? 'text-warning' : 'text-danger') }}">
                    {{ number_format($uptime, 2) }}%
                </div>
                <div class="progress mt-2" style="height:8px">
                    <div class="progress-bar {{ $uptime >= 99 ? 'bg-success' : ($uptime >= 95 ? 'bg-warning' : 'bg-danger') }}"
                         style="width:{{ min($uptime, 100) }}%"></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-info border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Avg Latency (this month)</div>
                <div class="fs-1 fw-bold text-info">{{ number_format($avgLatency, 1) }}<small class="fs-5">ms</small></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-4 {{ $avgLoss <= 1 ? 'border-success' : ($avgLoss <= 5 ? 'border-warning' : 'border-danger') }}">
            <div class="card-body text-center">
                <div class="text-muted small">Avg Packet Loss (this month)</div>
                <div class="fs-1 fw-bold {{ $avgLoss <= 1 ? 'text-success' : ($avgLoss <= 5 ? 'text-warning' : 'text-danger') }}">
                    {{ number_format($avgLoss, 2) }}<small class="fs-5">%</small>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Charts --}}
<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-transparent"><strong><i class="bi bi-activity me-1"></i>Latency (Last 24h)</strong></div>
            <div class="card-body">
                <canvas id="latencyChart" height="200"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-transparent"><strong><i class="bi bi-exclamation-triangle me-1"></i>Packet Loss (Last 24h)</strong></div>
            <div class="card-body">
                <canvas id="lossChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- Connection Info --}}
<div class="card shadow-sm">
    <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1"></i>Connection Details</strong></div>
    <div class="card-body small">
        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:40%">Provider</td><td class="fw-semibold">{{ $isp->provider }}</td></tr>
                    <tr><td class="text-muted">Circuit ID</td><td>{{ $isp->circuit_id ?: '—' }}</td></tr>
                    <tr><td class="text-muted">Branch</td><td>{{ $isp->branch?->name ?: '—' }}</td></tr>
                    <tr><td class="text-muted">Speed</td><td>{{ $isp->speedLabel() }}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted" style="width:40%">Static IP</td><td><code>{{ $isp->static_ip ?: '—' }}</code></td></tr>
                    <tr><td class="text-muted">Gateway</td><td><code>{{ $isp->gateway ?: '—' }}</code></td></tr>
                    <tr><td class="text-muted">Subnet</td><td><code>{{ $isp->subnet ?: '—' }}</code></td></tr>
                    <tr><td class="text-muted">Contract</td><td>{!! $isp->contractStatusBadge() !!} {{ $isp->contractStatusLabel() }}</td></tr>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const labels  = @json($chartLabels);
const latData = @json($chartLatency);
const lossData = @json($chartLoss);

new Chart(document.getElementById('latencyChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Latency (ms)',
            data: latData,
            borderColor: '#0dcaf0',
            backgroundColor: 'rgba(13,202,240,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 1,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, title: { display: true, text: 'ms' } },
            x: { ticks: { maxTicksLimit: 12, font: { size: 10 } } }
        }
    }
});

new Chart(document.getElementById('lossChart'), {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Packet Loss (%)',
            data: lossData,
            borderColor: '#ffc107',
            backgroundColor: 'rgba(255,193,7,0.1)',
            fill: true,
            tension: 0.3,
            pointRadius: 1,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, max: 100, title: { display: true, text: '%' } },
            x: { ticks: { maxTicksLimit: 12, font: { size: 10 } } }
        }
    }
});
</script>
@endpush
