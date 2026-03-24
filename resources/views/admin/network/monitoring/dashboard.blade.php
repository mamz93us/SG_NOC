@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-info"></i>SNMP Monitoring Dashboard</h4>
            <small class="text-muted">Operational overview of all monitored hosts</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.network.monitoring.hosts.list') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-list-check me-1"></i>Hosts List
            </a>
            <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-broadcast me-1"></i>Monitoring
            </a>
        </div>
    </div>

    {{-- KPI Cards Row 1 --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-primary">{{ $totalHosts }}</div>
                    <div class="small text-muted">Total Hosts</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-success border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-success">{{ $upCount }}</div>
                    <div class="small text-muted">Online</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-danger border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-danger">{{ $downCount }}</div>
                    <div class="small text-muted">Down</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-warning border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-warning">{{ $degradedCount }}</div>
                    <div class="small text-muted">Degraded</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-secondary">{{ $unknownCount }}</div>
                    <div class="small text-muted">Unknown</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-start border-danger border-3 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-danger">{{ $alertHostCount }}</div>
                    <div class="small text-muted">With Alerts</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 2: Availability bar + Sensor count --}}
    <div class="row g-3 mb-4">
        <div class="col-md-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <span class="fw-semibold small"><i class="bi bi-bar-chart-fill me-2 text-primary"></i>Host Status Distribution</span>
                </div>
                <div class="card-body pt-2">
                    @php
                        $total = max($totalHosts, 1);
                        $upPct      = round($upCount      / $total * 100);
                        $downPct    = round($downCount    / $total * 100);
                        $degradPct  = round($degradedCount/ $total * 100);
                        $unknownPct = max(0, 100 - $upPct - $downPct - $degradPct);
                    @endphp
                    <div class="progress mb-2" style="height:28px;border-radius:6px;">
                        <div class="progress-bar bg-success" style="width:{{ $upPct }}%" title="Up: {{ $upCount }}">
                            @if($upPct >= 8) {{ $upCount }} Up @endif
                        </div>
                        <div class="progress-bar bg-danger" style="width:{{ $downPct }}%" title="Down: {{ $downCount }}">
                            @if($downPct >= 8) {{ $downCount }} Down @endif
                        </div>
                        <div class="progress-bar bg-warning text-dark" style="width:{{ $degradPct }}%" title="Degraded: {{ $degradedCount }}">
                            @if($degradPct >= 8) {{ $degradedCount }} Deg. @endif
                        </div>
                        <div class="progress-bar bg-secondary" style="width:{{ $unknownPct }}%" title="Unknown: {{ $unknownCount }}">
                        </div>
                    </div>
                    <div class="d-flex gap-3 small text-muted">
                        <span><span class="badge bg-success me-1">&nbsp;</span>Up {{ $upPct }}%</span>
                        <span><span class="badge bg-danger me-1">&nbsp;</span>Down {{ $downPct }}%</span>
                        <span><span class="badge bg-warning me-1">&nbsp;</span>Degraded {{ $degradPct }}%</span>
                        <span><span class="badge bg-secondary me-1">&nbsp;</span>Unknown {{ $unknownPct }}%</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center d-flex flex-column justify-content-center py-3">
                    <div class="fs-1 fw-bold text-info">{{ number_format($totalSensors) }}</div>
                    <div class="text-muted">Total SNMP Sensors</div>
                    <div class="small text-muted mt-1">across all monitored hosts</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Row 3: Branch Breakdown + Down Hosts --}}
    <div class="row g-3 mb-4">
        {{-- Branch Breakdown --}}
        <div class="col-md-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0">
                    <span class="fw-semibold small"><i class="bi bi-building me-2 text-secondary"></i>Branch Breakdown</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Branch</th>
                                    <th class="text-center">Total</th>
                                    <th class="text-center text-success">Up</th>
                                    <th class="text-center text-danger">Down</th>
                                    <th class="text-center text-warning">Deg.</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($branchBreakdown as $row)
                                <tr>
                                    <td>{{ $row->branch?->name ?? 'No Branch' }}</td>
                                    <td class="text-center fw-semibold">{{ $row->total }}</td>
                                    <td class="text-center text-success">{{ $row->up_count }}</td>
                                    <td class="text-center text-danger">{{ $row->down_count }}</td>
                                    <td class="text-center text-warning">{{ $row->degraded_count }}</td>
                                </tr>
                                @empty
                                <tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- Down Hosts --}}
        <div class="col-md-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small"><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>Currently Down Hosts</span>
                    <span class="badge bg-danger">{{ $downCount }}</span>
                </div>
                <div class="card-body p-0">
                    @if($downHosts->isEmpty())
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-check-circle-fill text-success fs-4 d-block mb-1"></i>All hosts are up
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Host</th>
                                    <th>IP</th>
                                    <th>Branch</th>
                                    <th>Last Seen</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($downHosts as $host)
                                <tr>
                                    <td class="fw-semibold text-danger">{{ $host->name }}</td>
                                    <td class="font-monospace text-muted">{{ $host->ip }}</td>
                                    <td>{{ $host->branch?->name ?? '—' }}</td>
                                    <td class="text-muted">
                                        {{ $host->last_checked_at?->diffForHumans() ?? '—' }}
                                    </td>
                                    <td>
                                        <a href="{{ route('admin.network.monitoring.show', $host) }}"
                                           class="btn btn-sm btn-outline-danger py-0 px-1">
                                            <i class="bi bi-eye"></i>
                                        </a>
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

    {{-- Row 4: Recent Alerts + Top Problematic --}}
    <div class="row g-3">
        {{-- Recent Alerts --}}
        <div class="col-md-7">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pb-0 d-flex justify-content-between align-items-center">
                    <span class="fw-semibold small"><i class="bi bi-bell-fill me-2 text-warning"></i>Recent Active Alerts</span>
                </div>
                <div class="card-body p-0">
                    @if($recentAlerts->isEmpty())
                    <div class="text-center text-muted py-4">
                        <i class="bi bi-shield-check text-success fs-4 d-block mb-1"></i>No active alerts
                    </div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Host</th>
                                    <th>Rule</th>
                                    <th class="text-center">State</th>
                                    <th>Triggered</th>
                                    <th class="text-end">Fires</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($recentAlerts as $alert)
                                <tr>
                                    <td class="fw-semibold">
                                        @if($alert->host)
                                        <a href="{{ route('admin.network.monitoring.show', $alert->host) }}" class="text-decoration-none">
                                            {{ $alert->host->name }}
                                        </a>
                                        @else
                                        <span class="text-muted">ID #{{ $alert->entity_id }}</span>
                                        @endif
                                    </td>
                                    <td class="text-muted">{{ $alert->rule?->name ?? '—' }}</td>
                                    <td class="text-center">
                                        <span class="badge bg-{{ $alert->stateBadge() }}">{{ ucfirst($alert->state) }}</span>
                                    </td>
                                    <td class="text-muted">
                                        {{ $alert->first_triggered_at?->diffForHumans() ?? '—' }}
                                    </td>
                                    <td class="text-end">{{ $alert->alert_count }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        {{-- Top Problematic Hosts --}}
        <div class="col-md-5">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent border-0 pb-0">
                    <span class="fw-semibold small"><i class="bi bi-fire me-2 text-danger"></i>Top Problematic (Last 7 Days)</span>
                </div>
                <div class="card-body p-0">
                    @if($topProblematic->isEmpty())
                    <div class="text-center text-muted py-4">No alert events in last 7 days</div>
                    @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0 small">
                            <thead class="table-light">
                                <tr>
                                    <th>Host</th>
                                    <th class="text-center">Rules Hit</th>
                                    <th class="text-center">Total Fires</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($topProblematic as $row)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.network.monitoring.show', $row->host) }}" class="text-decoration-none fw-semibold">
                                            {{ $row->host->name }}
                                        </a>
                                        <div class="text-muted font-monospace" style="font-size:0.75rem;">{{ $row->host->ip }}</div>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-warning text-dark">{{ $row->rule_count }}</span>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-danger">{{ $row->total_fires ?? $row->rule_count }}</span>
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

</div>
@endsection
