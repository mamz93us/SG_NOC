@extends('layouts.admin')
@section('title', 'Switch QoS Monitor')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-primary"></i>Switch QoS Monitor</h4>
        <small class="text-muted">Cisco MLS QoS queue drops & policer stats — {{ today()->format('d M Y') }}</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-qos.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-list me-1"></i>All Stats</a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-warning alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- KPI Cards --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $interfacesWithDrops > 10 ? 'danger' : ($interfacesWithDrops > 0 ? 'warning' : 'success') }}">
                    {{ number_format($interfacesWithDrops) }}
                </div>
                <div class="small text-muted mt-1">Interfaces w/ Queue Drops</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ $switchesPolled }}</div>
                <div class="small text-muted mt-1">Switches Polled Today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $policerOutOfProfile > 0 ? 'warning' : 'success' }}">
                    {{ number_format($policerOutOfProfile) }}
                </div>
                <div class="small text-muted mt-1">Policer Out-of-Profile</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-{{ $activeAlerts->count() > 0 ? 'warning' : 'success' }}">{{ $activeAlerts->count() }}</div>
                <div class="small text-muted mt-1">Active QoS Alerts</div>
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
    {{-- Per-queue breakdown --}}
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-layers me-1"></i>Drops by Output Queue</div>
            <div class="card-body">
                <canvas id="queueBreakdownChart" height="150"></canvas>
            </div>
        </div>
    </div>

    {{-- Top Switches --}}
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-diagram-3 text-warning me-1"></i>Top 10 Switches by QoS Drops</div>
            <div class="card-body p-0">
                @if($topDropSwitches->isEmpty())
                <div class="text-muted text-center py-4 small"><i class="bi bi-check-circle text-success d-block mb-1"></i>No QoS drops across any switch</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th>Device</th><th>Queue Drops</th><th>Policer</th><th></th></tr>
                        </thead>
                        <tbody>
                            @foreach($topDropSwitches as $s)
                            <tr>
                                <td>
                                    <div class="fw-semibold">{{ $s->device_name }}</div>
                                    <div class="font-monospace text-muted" style="font-size:0.75rem">{{ $s->device_ip }}</div>
                                </td>
                                <td>
                                    <span class="badge bg-{{ $s->total_drops >= 1000 ? 'danger' : ($s->total_drops >= 100 ? 'warning text-dark' : 'secondary') }}">
                                        {{ number_format($s->total_drops) }}
                                    </span>
                                </td>
                                <td class="text-muted">{{ number_format($s->total_policer) }}</td>
                                <td>
                                    <a href="{{ route('admin.switch-qos.device', urlencode($s->device_ip)) }}" class="btn btn-sm btn-outline-secondary py-0 px-1"><i class="bi bi-eye"></i></a>
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

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold"><i class="bi bi-exclamation-triangle text-danger me-1"></i>Top 10 Interfaces by Queue Drops</div>
    <div class="card-body p-0">
        @if($topDropInterfaces->isEmpty())
        <div class="text-muted text-center py-4 small"><i class="bi bi-check-circle text-success d-block mb-1"></i>No interface queue drops today</div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Device</th><th>Interface</th>
                        <th class="text-center" colspan="3">Queue 0</th>
                        <th class="text-center" colspan="3">Queue 1</th>
                        <th class="text-center" colspan="3">Queue 2</th>
                        <th class="text-center" colspan="3">Queue 3</th>
                        <th>Policer</th>
                        <th>Total</th>
                    </tr>
                    <tr class="text-muted" style="font-size:0.7rem">
                        <th></th><th></th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>t1</th><th>t2</th><th>t3</th>
                        <th>OoP</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($topDropInterfaces as $i)
                    <tr>
                        <td class="fw-semibold">{{ $i->device_name }}</td>
                        <td class="font-monospace text-muted small">{{ $i->interface_name }}</td>
                        <td>{{ number_format($i->q0_t1_drop) }}</td><td>{{ number_format($i->q0_t2_drop) }}</td><td>{{ number_format($i->q0_t3_drop) }}</td>
                        <td>{{ number_format($i->q1_t1_drop) }}</td><td>{{ number_format($i->q1_t2_drop) }}</td><td>{{ number_format($i->q1_t3_drop) }}</td>
                        <td>{{ number_format($i->q2_t1_drop) }}</td><td>{{ number_format($i->q2_t2_drop) }}</td><td>{{ number_format($i->q2_t3_drop) }}</td>
                        <td>{{ number_format($i->q3_t1_drop) }}</td><td>{{ number_format($i->q3_t2_drop) }}</td><td>{{ number_format($i->q3_t3_drop) }}</td>
                        <td class="text-muted">{{ number_format($i->policer_out_of_profile) }}</td>
                        <td>
                            <span class="badge bg-{{ $i->total_drops >= 1000 ? 'danger' : ($i->total_drops >= 100 ? 'warning text-dark' : 'secondary') }}">
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

{{-- ─── Switches Inventory ─────────────────────────────────────────── --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-diagram-3 text-primary me-1"></i>Switches &amp; Routers Inventory</span>
        <div class="d-flex gap-2 small">
            <span class="badge bg-light text-dark border">Total: {{ $inventoryStats['total'] }}</span>
            <span class="badge bg-{{ $inventoryStats['never_polled'] > 0 ? 'warning text-dark' : 'success' }}">Never polled: {{ $inventoryStats['never_polled'] }}</span>
            <span class="badge bg-{{ $inventoryStats['missing_telnet'] > 0 ? 'danger' : 'success' }}">Missing telnet: {{ $inventoryStats['missing_telnet'] }}</span>
            <span class="badge bg-info">QoS supported: {{ $inventoryStats['mls_qos_supported'] }}</span>
        </div>
    </div>
    <div class="card-body p-0">
        @if($inventory->isEmpty())
        <div class="text-muted text-center py-4 small">
            No switches or routers in inventory. Add one from the Assets page to get started.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>Branch</th>
                        <th>IP</th>
                        <th class="text-center">Telnet</th>
                        <th class="text-center">Enable</th>
                        <th class="text-center">MLS QoS</th>
                        <th class="text-center">Reachable</th>
                        <th>Last polled</th>
                        <th class="text-center">Ifaces</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($inventory as $d)
                    <tr>
                        <td class="fw-semibold">
                            <i class="bi {{ $d->type === 'router' ? 'bi-router' : 'bi-hdd-network' }} text-muted me-1"></i>{{ $d->name }}
                        </td>
                        <td class="text-muted">{{ $d->branch?->name ?? '—' }}</td>
                        <td class="font-monospace text-muted">{{ $d->ip_address }}</td>
                        <td class="text-center">
                            @if($d->has_telnet_cred)
                                <span class="badge bg-success" title="Telnet password is set"><i class="bi bi-key"></i></span>
                            @else
                                <span class="badge bg-danger" title="No telnet credential"><i class="bi bi-exclamation-triangle"></i></span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($d->has_enable_cred)
                                <span class="badge bg-success"><i class="bi bi-key"></i></span>
                            @else
                                <span class="badge bg-secondary">—</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($d->mls_qos_supported === true)
                                <span class="badge bg-success">Yes</span>
                            @elseif($d->mls_qos_supported === false)
                                <span class="badge bg-warning text-dark">No</span>
                            @else
                                <span class="badge bg-secondary">?</span>
                            @endif
                        </td>
                        <td class="text-center">
                            @if($d->telnet_reachable === true)
                                <span class="badge bg-success"><i class="bi bi-check"></i></span>
                            @elseif($d->telnet_reachable === false)
                                <span class="badge bg-danger"><i class="bi bi-x"></i></span>
                            @else
                                <span class="badge bg-secondary">?</span>
                            @endif
                        </td>
                        <td class="text-muted">
                            @if($d->last_polled_at)
                                {{ \Carbon\Carbon::parse($d->last_polled_at)->diffForHumans() }}
                            @else
                                <span class="text-danger small"><i class="bi bi-dash-circle me-1"></i>never</span>
                            @endif
                        </td>
                        <td class="text-center text-muted">{{ $d->polled_interfaces ?: '—' }}</td>
                        <td class="text-nowrap">
                            <a href="{{ route('admin.switch-qos.setup', $d->id) }}" class="btn btn-sm btn-outline-secondary py-0 px-1" title="Credentials &amp; setup"><i class="bi bi-gear"></i></a>
                            @if($d->last_polled_at)
                            <a href="{{ route('admin.switch-qos.device', urlencode($d->ip_address)) }}" class="btn btn-sm btn-outline-secondary py-0 px-1" title="View QoS details"><i class="bi bi-eye"></i></a>
                            @endif
                            @can('manage-credentials')
                            <form method="POST" action="{{ route('admin.switch-qos.test', $d->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-1" title="Probe telnet + MLS QoS"><i class="bi bi-broadcast"></i></button>
                            </form>
                            <form method="POST" action="{{ route('admin.switch-qos.poll', $d->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-success py-0 px-1" title="Poll now"><i class="bi bi-play-fill"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

@if($activeAlerts->count() > 0)
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-bell text-warning me-1"></i>Active QoS Alerts</span>
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
@endif
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
<script>
const queueData = @json($queueBreakdown);
new Chart(document.getElementById('queueBreakdownChart'), {
    type: 'doughnut',
    data: {
        labels: ['Queue 0', 'Queue 1', 'Queue 2', 'Queue 3'],
        datasets: [{
            data: [
                parseInt(queueData?.q0) || 0,
                parseInt(queueData?.q1) || 0,
                parseInt(queueData?.q2) || 0,
                parseInt(queueData?.q3) || 0,
            ],
            backgroundColor: ['#6c757d88', '#dc354588', '#ffc10788', '#0d6efd88'],
            borderColor: ['#6c757d', '#dc3545', '#ffc107', '#0d6efd'],
            borderWidth: 1
        }]
    },
    options: {
        plugins: { legend: { position: 'bottom' } },
        responsive: true
    }
});
</script>
@endpush
