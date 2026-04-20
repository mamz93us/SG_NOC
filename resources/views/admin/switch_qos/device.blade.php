@extends('layouts.admin')
@section('title', 'Switch QoS: ' . $latestSnapshot->device_name)

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.switch-qos.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Stats</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-primary"></i>{{ $latestSnapshot->device_name }}</h4>
        <small class="text-muted font-monospace">{{ $deviceIp }}</small>
    </div>
    <div>
        <span class="badge bg-secondary">Last polled {{ $latestSnapshot->polled_at?->diffForHumans() ?: '—' }}</span>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-hdd-network me-1"></i>Per-Interface QoS Summary (latest poll)</div>
    <div class="card-body p-0">
        @if($interfaces->isEmpty())
        <div class="text-muted text-center py-4 small">No interface data available</div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Interface</th>
                        <th class="text-center" colspan="3">Queue 0 drops</th>
                        <th class="text-center" colspan="3">Queue 1 drops</th>
                        <th class="text-center" colspan="3">Queue 2 drops</th>
                        <th class="text-center" colspan="3">Queue 3 drops</th>
                        <th>Policer OoP</th>
                        <th>Total</th>
                        <th>Polled</th>
                    </tr>
                    <tr class="text-muted" style="font-size:0.7rem">
                        <th></th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th></th><th></th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($interfaces as $iface)
                    <tr class="{{ $iface->total_drops >= 1000 ? 'table-danger' : ($iface->total_drops >= 100 ? 'table-warning' : '') }}">
                        <td class="fw-semibold font-monospace">{{ $iface->interface_name }}</td>
                        <td>{{ number_format($iface->q0_t1_drop) }}</td><td>{{ number_format($iface->q0_t2_drop) }}</td><td>{{ number_format($iface->q0_t3_drop) }}</td>
                        <td>{{ number_format($iface->q1_t1_drop) }}</td><td>{{ number_format($iface->q1_t2_drop) }}</td><td>{{ number_format($iface->q1_t3_drop) }}</td>
                        <td>{{ number_format($iface->q2_t1_drop) }}</td><td>{{ number_format($iface->q2_t2_drop) }}</td><td>{{ number_format($iface->q2_t3_drop) }}</td>
                        <td>{{ number_format($iface->q3_t1_drop) }}</td><td>{{ number_format($iface->q3_t2_drop) }}</td><td>{{ number_format($iface->q3_t3_drop) }}</td>
                        <td class="text-muted">{{ number_format($iface->policer_out_of_profile) }}</td>
                        <td>
                            <span class="badge bg-{{ $iface->total_drops >= 1000 ? 'danger' : ($iface->total_drops >= 100 ? 'warning text-dark' : ($iface->total_drops > 0 ? 'info' : 'secondary')) }}">
                                {{ number_format($iface->total_drops) }}
                            </span>
                        </td>
                        <td class="text-muted small">{{ $iface->polled_at?->format('H:i') ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@if($trend->isNotEmpty())
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-graph-up me-1"></i>Cumulative Drop Counter — Last 24h (Top 5 Interfaces)</div>
    <div class="card-body">
        <canvas id="qosTrendChart" height="100"></canvas>
    </div>
</div>
@endif
@endsection

@push('scripts')
@if($trend->isNotEmpty())
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const trendData = @json($trend);
const allLabels = [...new Set(Object.values(trendData).flat().map(d => d.label))].sort();
const colors = ['#dc3545','#fd7e14','#ffc107','#0d6efd','#6610f2','#20c997','#0dcaf0','#198754'];

const datasets = Object.entries(trendData).slice(0, 5).map(([ifName, rows], i) => {
    const rowMap = {};
    rows.forEach(r => { rowMap[r.label] = parseInt(r.total_drops) || 0; });
    return {
        label: ifName,
        data: allLabels.map(l => rowMap[l] || 0),
        borderColor: colors[i % colors.length],
        backgroundColor: 'transparent',
        tension: 0.3,
        pointRadius: 2,
    };
});

new Chart(document.getElementById('qosTrendChart'), {
    type: 'line',
    data: { labels: allLabels, datasets },
    options: {
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        responsive: true
    }
});
</script>
@endif
@endpush
