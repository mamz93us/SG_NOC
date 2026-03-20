@extends('layouts.admin')
@section('title', 'Sensor: ' . $sensor->name)
@section('content')
<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="{{ url()->previous() }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h4 class="mb-0 fw-bold"><i class="bi bi-graph-up me-2 text-primary"></i>{{ $sensor->name }}</h4>
            @if($sensor->unit)
                <span class="badge bg-light text-muted border">{{ $sensor->unit }}</span>
            @endif
            @if($sensor->sensor_group)
                <span class="badge bg-secondary-subtle text-secondary">{{ $sensor->sensor_group }}</span>
            @endif
        </div>
        <div class="btn-group" role="group" aria-label="Time range">
            <button class="btn btn-sm btn-outline-secondary range-btn active" data-days="1">24h</button>
            <button class="btn btn-sm btn-outline-secondary range-btn" data-days="7">7d</button>
            <button class="btn btn-sm btn-outline-secondary range-btn" data-days="14">14d</button>
            <button class="btn btn-sm btn-outline-secondary range-btn" data-days="30">30d</button>
            <button class="btn btn-sm btn-outline-secondary range-btn" data-days="90">90d</button>
        </div>
    </div>

    {{-- Main Chart Card --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body">
            <div class="position-relative" style="min-height: 300px;">
                <div id="chartLoader" class="position-absolute top-50 start-50 translate-middle text-muted small d-none">
                    <div class="spinner-border spinner-border-sm me-2" role="status"></div>Loading…
                </div>
                <canvas id="sensorChart" height="120"></canvas>
            </div>
        </div>
    </div>

    {{-- Stats Row --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-3">
            <div class="row text-center g-0">
                <div class="col-3 border-end">
                    <small class="text-muted d-block">Current</small>
                    <strong id="stat-current" class="fs-5">—</strong>
                </div>
                <div class="col-3 border-end">
                    <small class="text-muted d-block">Average</small>
                    <strong id="stat-avg" class="fs-5">—</strong>
                </div>
                <div class="col-3 border-end">
                    <small class="text-muted d-block">Min</small>
                    <strong id="stat-min" class="fs-5">—</strong>
                </div>
                <div class="col-3">
                    <small class="text-muted d-block">Max</small>
                    <strong id="stat-max" class="fs-5">—</strong>
                </div>
            </div>
        </div>
    </div>

    {{-- Sensor Meta --}}
    <div class="card shadow-sm border-0">
        <div class="card-header py-2 bg-transparent">
            <h6 class="mb-0 fw-semibold small text-uppercase text-muted">Sensor Details</h6>
        </div>
        <div class="card-body py-2">
            <table class="table table-sm table-borderless small mb-0">
                <tr>
                    <th class="text-muted" style="width:160px">Host</th>
                    <td>
                        @if($sensor->host)
                            <a href="{{ route('admin.network.monitoring.show', $sensor->host) }}">
                                {{ $sensor->host->name }} ({{ $sensor->host->ip }})
                            </a>
                        @else
                            —
                        @endif
                    </td>
                </tr>
                <tr><th class="text-muted">OID</th><td class="font-monospace">{{ $sensor->oid }}</td></tr>
                <tr><th class="text-muted">Data Type</th><td>{{ $sensor->data_type }}</td></tr>
                <tr><th class="text-muted">Group</th><td>{{ $sensor->sensor_group ?? '—' }}</td></tr>
                @if($sensor->warning_threshold !== null)
                <tr><th class="text-muted">Warning Threshold</th><td class="text-warning fw-semibold">{{ $sensor->warning_threshold }} {{ $sensor->unit }}</td></tr>
                @endif
                @if($sensor->critical_threshold !== null)
                <tr><th class="text-muted">Critical Threshold</th><td class="text-danger fw-semibold">{{ $sensor->critical_threshold }} {{ $sensor->unit }}</td></tr>
                @endif
                <tr><th class="text-muted">Status</th><td><span class="badge bg-{{ $sensor->status === 'active' ? 'success' : 'secondary' }}">{{ $sensor->status ?? 'unknown' }}</span></td></tr>
                <tr><th class="text-muted">Last Polled</th><td>{{ $sensor->last_recorded_at ? $sensor->last_recorded_at->diffForHumans() : '—' }}</td></tr>
            </table>
        </div>
    </div>

</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
let chart = null;
const sensorId = {{ $sensor->id }};
const unit = '{{ addslashes($sensor->unit ?? '') }}';
const warnThreshold = {{ $sensor->warning_threshold !== null ? $sensor->warning_threshold : 'null' }};
const critThreshold = {{ $sensor->critical_threshold !== null ? $sensor->critical_threshold : 'null' }};

async function loadChart(days) {
    const loader = document.getElementById('chartLoader');
    loader.classList.remove('d-none');

    try {
        const resp = await fetch(`/admin/sensors/${sensorId}/history?days=${days}`);
        const json = await resp.json();
        const labels = json.data.map(d => new Date(d.ts).toLocaleString());
        const values = json.data.map(d => d.v);

        if (chart) {
            chart.destroy();
            chart = null;
        }

        const annotations = {};
        if (warnThreshold !== null) {
            annotations.warningLine = {
                type: 'line', yMin: warnThreshold, yMax: warnThreshold,
                borderColor: 'rgba(255, 193, 7, 0.7)', borderWidth: 1.5, borderDash: [5, 5],
            };
        }
        if (critThreshold !== null) {
            annotations.criticalLine = {
                type: 'line', yMin: critThreshold, yMax: critThreshold,
                borderColor: 'rgba(220, 53, 69, 0.7)', borderWidth: 1.5, borderDash: [5, 5],
            };
        }

        chart = new Chart(document.getElementById('sensorChart'), {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: json.sensor.name,
                    data: values,
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13,110,253,0.08)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: values.length > 150 ? 0 : 3,
                    pointHoverRadius: 5,
                    borderWidth: 2,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => `${ctx.parsed.y} ${unit}`,
                        }
                    },
                },
                scales: {
                    y: {
                        beginAtZero: false,
                        title: { display: !!unit, text: unit },
                        grid: { color: 'rgba(0,0,0,0.04)' },
                    },
                    x: {
                        ticks: {
                            maxTicksLimit: 12,
                            maxRotation: 0,
                        },
                        grid: { display: false },
                    }
                }
            }
        });

        // Update stats
        if (values.length > 0) {
            const sum = values.reduce((a, b) => a + b, 0);
            const avg = sum / values.length;
            const minVal = Math.min(...values);
            const maxVal = Math.max(...values);
            document.getElementById('stat-current').textContent = values[values.length - 1] + (unit ? ' ' + unit : '');
            document.getElementById('stat-avg').textContent     = avg.toFixed(2) + (unit ? ' ' + unit : '');
            document.getElementById('stat-min').textContent     = minVal + (unit ? ' ' + unit : '');
            document.getElementById('stat-max').textContent     = maxVal + (unit ? ' ' + unit : '');
        } else {
            ['stat-current','stat-avg','stat-min','stat-max'].forEach(id => {
                document.getElementById(id).textContent = 'No data';
            });
        }
    } catch (e) {
        console.error('Failed to load sensor chart:', e);
    } finally {
        loader.classList.add('d-none');
    }
}

document.querySelectorAll('.range-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        document.querySelectorAll('.range-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        loadChart(parseInt(this.dataset.days));
    });
});

loadChart(1);
</script>
@endpush
@endsection
