@extends('layouts.admin')
@section('title', 'Switch Drop Statistics')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bar-chart-fill me-2 text-danger"></i>Switch Drop Statistics</h4>
        <small class="text-muted">30-day analysis of switch drop and error trends</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-drops.dashboard') }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-speedometer2 me-1"></i>Dashboard</a>
        <a href="{{ route('admin.switch-drops.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list me-1"></i>All Stats</a>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Daily Drop Trend --}}
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Daily Total Drops (30 days)</div>
            <div class="card-body">
                <canvas id="dailyDropChart" height="120"></canvas>
            </div>
        </div>
    </div>

    {{-- Branch Comparison --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Drops by Branch (30 days)</div>
            <div class="card-body">
                <canvas id="branchDropChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    {{-- Error Breakdown --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Error Breakdown by Day</div>
            <div class="card-body">
                <canvas id="errorBreakdownChart" height="180"></canvas>
            </div>
        </div>
    </div>

    {{-- Worst Devices --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold">Worst Devices by Drops (7 days)</div>
            <div class="card-body">
                <canvas id="worstDevicesChart" height="180"></canvas>
            </div>
        </div>
    </div>
</div>

{{-- Summary Tables --}}
<div class="row g-4">
    {{-- Worst Devices Table --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-diagram-3 text-danger me-1"></i>Worst Devices (7 days)</div>
            <div class="card-body p-0">
                @if($worstDevices->isEmpty())
                <div class="text-muted text-center py-4 small">No data</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Device</th><th>IP</th><th>Branch</th><th>Total Drops</th><th>Errors</th><th></th></tr></thead>
                        <tbody>
                            @foreach($worstDevices as $d)
                            <tr>
                                <td class="fw-semibold">{{ $d->device_name }}</td>
                                <td class="font-monospace text-muted small">{{ $d->device_ip }}</td>
                                <td class="text-muted">{{ $d->branch ?: '—' }}</td>
                                <td><span class="badge bg-{{ $d->total_drops >= 1000 ? 'danger' : ($d->total_drops >= 200 ? 'warning text-dark' : 'secondary') }}">{{ number_format($d->total_drops) }}</span></td>
                                <td class="text-muted">{{ number_format($d->total_errors) }}</td>
                                <td><a href="{{ route('admin.switch-drops.device', urlencode($d->device_ip)) }}" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Worst Interfaces Table --}}
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-hdd-network text-warning me-1"></i>Worst Interfaces (7 days)</div>
            <div class="card-body p-0">
                @if($worstInterfaces->isEmpty())
                <div class="text-muted text-center py-4 small">No data</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light"><tr><th>Device</th><th>Interface</th><th>Total Drops</th><th>Errors</th><th>CRC</th></tr></thead>
                        <tbody>
                            @foreach($worstInterfaces as $i)
                            <tr>
                                <td class="fw-semibold">{{ $i->device_name }}</td>
                                <td class="font-monospace text-muted small">{{ $i->interface_name }}</td>
                                <td><span class="badge bg-{{ $i->total_drops >= 1000 ? 'danger' : ($i->total_drops >= 200 ? 'warning text-dark' : 'secondary') }}">{{ number_format($i->total_drops) }}</span></td>
                                <td class="text-muted">{{ number_format($i->total_errors) }}</td>
                                <td class="text-muted">{{ number_format($i->total_crc) }}</td>
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
const dailyDropData   = @json($dailyTrend);
const branchDropData  = @json($branchComparison);
const errorBreakdown  = @json($errorBreakdown);
const worstDevicesData = @json($worstDevices);

// Daily Drops Trend
new Chart(document.getElementById('dailyDropChart'), {
    type: 'bar',
    data: {
        labels: dailyDropData.map(d => d.date),
        datasets: [{
            label: 'Total Drops',
            data: dailyDropData.map(d => parseInt(d.total_drops) || 0),
            backgroundColor: '#dc354588',
            borderColor: '#dc3545',
            borderWidth: 1
        }]
    },
    options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

// Branch Comparison
new Chart(document.getElementById('branchDropChart'), {
    type: 'bar',
    data: {
        labels: branchDropData.map(b => b.branch || 'Unknown'),
        datasets: [{ label: 'Total Drops', data: branchDropData.map(b => parseInt(b.total_drops) || 0), backgroundColor: '#fd7e1488', borderColor: '#fd7e14', borderWidth: 1 }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});

// Error Breakdown Stacked
new Chart(document.getElementById('errorBreakdownChart'), {
    type: 'bar',
    data: {
        labels: errorBreakdown.map(d => d.date),
        datasets: [
            { label: 'In Discards',  data: errorBreakdown.map(d => d.in_discards),  backgroundColor: '#0d6efd88', stack: 'drops' },
            { label: 'Out Discards', data: errorBreakdown.map(d => d.out_discards), backgroundColor: '#6610f288', stack: 'drops' },
            { label: 'In Errors',    data: errorBreakdown.map(d => d.in_errors),    backgroundColor: '#dc354588', stack: 'drops' },
            { label: 'Out Errors',   data: errorBreakdown.map(d => d.out_errors),   backgroundColor: '#fd7e1488', stack: 'drops' },
        ]
    },
    options: {
        plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 11 } } } },
        scales: { y: { beginAtZero: true, stacked: true }, x: { stacked: true } }
    }
});

// Worst Devices Chart
new Chart(document.getElementById('worstDevicesChart'), {
    type: 'bar',
    data: {
        labels: worstDevicesData.map(d => d.device_name),
        datasets: [{ label: 'Total Drops', data: worstDevicesData.map(d => parseInt(d.total_drops) || 0), backgroundColor: '#dc354588', borderColor: '#dc3545', borderWidth: 1 }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>
@endpush
