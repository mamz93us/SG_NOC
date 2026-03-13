@extends('layouts.admin')
@section('title', 'ITAM Dashboard')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-boxes me-2"></i>IT Asset Management</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.suppliers.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-building me-1"></i>Suppliers ({{ $supplierCount }})
            </a>
            <a href="{{ route('admin.devices.scan') }}" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-qr-code-scan me-1"></i>QR Scanner
            </a>
        </div>
    </div>

    {{-- Stat Cards Row --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-primary">{{ $stats['total'] }}</div>
                    <div class="small text-muted">Total Assets</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-success">{{ $stats['assigned'] }}</div>
                    <div class="small text-muted">Assigned</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-info">{{ $stats['available'] }}</div>
                    <div class="small text-muted">Available</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-warning">{{ $stats['maintenance'] }}</div>
                    <div class="small text-muted">Maintenance</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-secondary">{{ $stats['retired'] }}</div>
                    <div class="small text-muted">Retired</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-4 col-lg-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small mb-1">Total Cost</div>
                    <div class="fw-bold text-dark">${{ number_format($totalCost, 0) }}</div>
                    <div class="text-muted small mt-1">Book Value</div>
                    <div class="fw-bold text-success">${{ number_format($totalCurrentValue, 0) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        {{-- Chart: By Type --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Assets by Type</div>
                <div class="card-body d-flex align-items-center justify-content-center">
                    <canvas id="typeChart" width="220" height="220"></canvas>
                </div>
            </div>
        </div>

        {{-- Chart: By Branch --}}
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Assets by Branch (Top 10)</div>
                <div class="card-body">
                    <canvas id="branchChart" height="180"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        {{-- Warranty Expiring --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-shield-exclamation text-warning me-1"></i>Warranty Expiring (30 days)</span>
                    <span class="badge bg-warning text-dark">{{ $warrantyExpiring->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @forelse($warrantyExpiring as $d)
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <div>
                            <a href="{{ route('admin.devices.show', $d) }}" class="text-decoration-none fw-semibold">{{ $d->name }}</a>
                            <div class="text-muted small">{{ $d->branch?->name }}</div>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-warning text-dark small">{{ $d->warranty_expiry->format('d M') }}</span>
                            <div class="text-muted" style="font-size:0.7rem">{{ $d->warrantyDaysLeft() }}d left</div>
                        </div>
                    </div>
                    @empty
                    <div class="text-muted text-center py-3 small">No warranties expiring soon</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Licenses Expiring --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="bi bi-key text-danger me-1"></i>Licenses Expiring (30 days)</span>
                    <span class="badge bg-danger">{{ $licensesExpiring->count() }}</span>
                </div>
                <div class="card-body p-0">
                    @forelse($licensesExpiring as $lic)
                    <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                        <div>
                            <a href="{{ route('admin.itam.licenses.index') }}" class="text-decoration-none fw-semibold">{{ $lic->license_name }}</a>
                            <div class="text-muted small">{{ $lic->vendor }}</div>
                        </div>
                        <span class="badge bg-danger small">{{ $lic->expiry_date->format('d M') }}</span>
                    </div>
                    @empty
                    <div class="text-muted text-center py-3 small">No licenses expiring soon</div>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Recent Activity --}}
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold"><i class="bi bi-clock-history me-1"></i>Recent Activity</div>
                <div class="card-body p-0">
                    @forelse($recentActivity as $event)
                    <div class="d-flex gap-2 px-3 py-2 border-bottom align-items-start">
                        <i class="bi {{ $event->eventIcon() }} mt-1 flex-shrink-0"></i>
                        <div class="min-w-0">
                            <div class="small fw-semibold text-truncate">{{ Str::limit($event->description, 50) }}</div>
                            <div class="text-muted" style="font-size:0.7rem">{{ $event->created_at?->diffForHumans() }} · {{ $event->user?->name ?? 'System' }}</div>
                        </div>
                    </div>
                    @empty
                    <div class="text-muted text-center py-3 small">No recent activity</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
// Type doughnut chart
const typeData = @json($byType);
const typeLabels = Object.keys(typeData).map(k => k.charAt(0).toUpperCase() + k.slice(1));
const typeValues = Object.values(typeData);
const colors = ['#0d6efd','#6610f2','#6f42c1','#d63384','#dc3545','#fd7e14','#ffc107','#198754','#20c997','#0dcaf0','#adb5bd'];

new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: { labels: typeLabels, datasets: [{ data: typeValues, backgroundColor: colors, borderWidth: 2 }] },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } }, cutout: '60%' }
});

// Branch bar chart
const branchData = @json($byBranch);
const branchLabels = branchData.map(b => b.branch?.name ?? 'Unknown');
const branchValues = branchData.map(b => b.count);

new Chart(document.getElementById('branchChart'), {
    type: 'bar',
    data: {
        labels: branchLabels,
        datasets: [{ label: 'Assets', data: branchValues, backgroundColor: '#0d6efd88', borderColor: '#0d6efd', borderWidth: 1 }]
    },
    options: {
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 } } }
    }
});
</script>
@endpush
