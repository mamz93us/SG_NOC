@extends('layouts.admin')
@section('title', 'Switch Drop Monitor Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2 text-danger"></i>Switch Drop Monitor</h4>
        <small class="text-muted">Network interface drop & error counters — {{ today()->format('d M Y') }}</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-drops.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list me-1"></i>All Stats</a>
        <a href="{{ route('admin.switch-drops.statistics') }}" class="btn btn-sm btn-outline-info"><i class="bi bi-bar-chart me-1"></i>Statistics</a>
    </div>
</div>

{{-- KPI Cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $totalDropsToday > 1000 ? 'danger' : ($totalDropsToday > 100 ? 'warning' : 'success') }}">
                    {{ number_format($totalDropsToday) }}
                </div>
                <div class="small text-muted mt-1">Total Drops Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $totalErrorsToday > 500 ? 'danger' : ($totalErrorsToday > 50 ? 'warning' : 'success') }}">
                    {{ number_format($totalErrorsToday) }}
                </div>
                <div class="small text-muted mt-1">Interface Errors Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $switchesWithDrops > 5 ? 'danger' : ($switchesWithDrops > 0 ? 'warning' : 'success') }}">
                    {{ $switchesWithDrops }}
                </div>
                <div class="small text-muted mt-1">Switches w/ Drops &ge;100</div>
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
    {{-- Hourly Drop Chart --}}
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-graph-down me-1"></i>Drops per Hour</div>
            <div class="card-body">
                <canvas id="hourlyDropChart" height="130"></canvas>
            </div>
        </div>
    </div>

    {{-- By Branch --}}
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-building me-1"></i>Drops by Branch</div>
            <div class="card-body">
                <canvas id="branchDropChart" height="130"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Top Drop Interfaces --}}
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Top 10 Interfaces by Drops</div>
            <div class="card-body p-0">
                @if($topDropInterfaces->isEmpty())
                <div class="text-muted text-center py-4 small"><i class="bi bi-check-circle text-success d-block mb-1"></i>No drops recorded today</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th>Device</th><th>Interface</th><th>Branch</th><th>Discards</th><th>Errors</th><th>CRC</th><th>Total</th></tr>
                        </thead>
                        <tbody>
                            @foreach($topDropInterfaces as $i)
                            <tr>
                                <td class="fw-semibold">{{ $i->device_name }}</td>
                                <td class="font-monospace text-muted small">{{ $i->interface_name }}</td>
                                <td>{{ $i->branch ?: '—' }}</td>
                                <td class="text-muted">{{ number_format($i->total_discards) }}</td>
                                <td class="text-muted">{{ number_format($i->total_errors) }}</td>
                                <td class="text-muted">{{ number_format($i->total_crc) }}</td>
                                <td>
                                    <span class="badge bg-{{ $i->total_drops >= 500 ? 'danger' : ($i->total_drops >= 100 ? 'warning text-dark' : 'secondary') }}">
                                        {{ number_format($i->total_drops) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Top Drop Switches --}}
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-diagram-3 text-warning me-1"></i>Top 10 Switches by Drops</div>
            <div class="card-body p-0">
                @if($topDropSwitches->isEmpty())
                <div class="text-muted text-center py-4 small">No switches with drops today</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th>Device</th><th>Branch</th><th>Drops</th><th>Errors</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($topDropSwitches as $s)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $s->device_name }}</div>
                                    <div class="font-monospace text-muted" style="font-size:0.75rem">{{ $s->device_ip }}</div>
                                </td>
                                <td class="text-muted">{{ $s->branch ?: '—' }}</td>
                                <td>
                                    <span class="badge bg-{{ $s->total_drops >= 500 ? 'danger' : ($s->total_drops >= 100 ? 'warning text-dark' : 'secondary') }}">
                                        {{ number_format($s->total_drops) }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ number_format($s->total_errors) }}</td>
                                <td>
                                    <a href="{{ route('admin.switch-drops.device', urlencode($s->device_ip)) }}" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a>
                                </td>
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

@if($activeAlerts->count() > 0)
<div class="mt-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-bell text-warning me-1"></i>Active Switch Alerts</span>
            <span class="badge bg-warning text-dark">{{ $activeAlerts->count() }}</span>
        </div>
        <div class="card-body p-0">
            @foreach($activeAlerts as $alert)
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-start">
                <div>
                    <span class="badge bg-{{ $alert->severity === 'critical' ? 'danger' : 'warning text-dark' }} me-1">{{ $alert->severity }}</span>
                    <span class="fw-semibold small">{{ $alert->source_ref }}</span>
                    <span class="text-muted small ms-2">{{ Str::limit($alert->message, 100) }}</span>
                </div>
                <small class="text-muted text-nowrap ms-2">{{ $alert->created_at->diffForHumans() }}</small>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const hourlyDropData = @json($hourlyTrend);
const hourlyDropLabels = Array.from({length: 24}, (_, i) => i + ':00');
const hourlyDropVals = new Array(24).fill(0);
hourlyDropData.forEach(d => { hourlyDropVals[d.hour] = parseInt(d.total_drops) || 0; });

new Chart(document.getElementById('hourlyDropChart'), {
    type: 'bar',
    data: {
        labels: hourlyDropLabels,
        datasets: [{
            label: 'Total Drops',
            data: hourlyDropVals,
            backgroundColor: hourlyDropVals.map(v => v >= 500 ? '#dc354588' : (v >= 100 ? '#ffc10788' : '#0d6efd44')),
            borderWidth: 0
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
        responsive: true
    }
});

const branchDropData = @json($byBranch);
new Chart(document.getElementById('branchDropChart'), {
    type: 'bar',
    data: {
        labels: branchDropData.map(b => b.branch || 'Unknown'),
        datasets: [{
            label: 'Total Drops',
            data: branchDropData.map(b => parseInt(b.total_drops) || 0),
            backgroundColor: '#dc354588',
            borderColor: '#dc3545',
            borderWidth: 1
        }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>
@endpush
