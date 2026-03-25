@extends('layouts.admin')
@section('title', 'Switch Device: ' . $latest->device_name)

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.switch-drops.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Stats</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2 text-danger"></i>{{ $latest->device_name }}</h4>
        <small class="text-muted font-monospace">{{ $deviceIp }}
            @if($latest->branch) &bull; {{ $latest->branch }} @endif
        </small>
    </div>
    <div>
        <span class="badge bg-secondary">Last polled {{ $latest->polled_at?->diffForHumans() ?: '—' }}</span>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-hdd-network me-1"></i>Interface Drop Summary</div>
    <div class="card-body p-0">
        @if($interfaces->isEmpty())
        <div class="text-muted text-center py-4 small">No interface data available</div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>#</th><th>Interface</th>
                        <th>In Discards</th><th>Out Discards</th>
                        <th>In Errors</th><th>Out Errors</th>
                        <th>CRC Errors</th><th>Total Drops</th>
                        <th>Last Polled</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($interfaces as $iface)
                    @php $total = $iface->in_discards + $iface->out_discards + $iface->in_errors + $iface->out_errors; @endphp
                    <tr class="{{ $total >= 500 ? 'table-danger' : ($total >= 100 ? 'table-warning' : '') }}">
                        <td class="text-muted">{{ $iface->interface_index }}</td>
                        <td class="fw-semibold font-monospace">{{ $iface->interface_name ?: '—' }}</td>
                        <td>{{ number_format($iface->in_discards) }}</td>
                        <td>{{ number_format($iface->out_discards) }}</td>
                        <td>{{ number_format($iface->in_errors) }}</td>
                        <td>{{ number_format($iface->out_errors) }}</td>
                        <td>{{ number_format($iface->crc_errors) }}</td>
                        <td>
                            <span class="badge bg-{{ $total >= 500 ? 'danger' : ($total >= 100 ? 'warning text-dark' : ($total > 0 ? 'info' : 'secondary')) }}">
                                {{ number_format($total) }}
                            </span>
                        </td>
                        <td class="text-muted small">{{ $iface->last_polled ? \Carbon\Carbon::parse($iface->last_polled)->format('H:i') : '—' }}</td>
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
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-graph-down me-1"></i>Drop Trend (Last 24 Hours) — Top 5 Interfaces</div>
    <div class="card-body">
        <canvas id="trendChart" height="100"></canvas>
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
    rows.forEach(r => { rowMap[r.label] = parseInt(r.drops) || 0; });
    return {
        label: ifName,
        data: allLabels.map(l => rowMap[l] || 0),
        borderColor: colors[i % colors.length],
        backgroundColor: 'transparent',
        tension: 0.3,
        pointRadius: 2,
    };
});

new Chart(document.getElementById('trendChart'), {
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
