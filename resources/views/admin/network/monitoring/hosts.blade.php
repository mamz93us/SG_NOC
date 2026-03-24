@extends('layouts.admin')

@section('content')
<div class="container-fluid py-4">

    {{-- Page Header --}}
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-list-check me-2 text-primary"></i>SNMP Hosts List</h4>
            <small class="text-muted">All monitored hosts — filterable operational table</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.network.monitoring.dashboard') }}" class="btn btn-outline-info btn-sm">
                <i class="bi bi-speedometer2 me-1"></i>Dashboard
            </a>
            <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-broadcast me-1"></i>Monitoring
            </a>
        </div>
    </div>

    {{-- Summary KPI Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-primary">{{ number_format($totalHosts) }}</div>
                    <div class="text-muted small">Total Hosts</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-success">{{ number_format($upCount) }}</div>
                    <div class="text-muted small">Up</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-danger">{{ number_format($downCount) }}</div>
                    <div class="text-muted small">Down</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <div class="fs-2 fw-bold text-warning">{{ number_format($alertHostCount) }}</div>
                    <div class="text-muted small">With Active Alerts</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="GET" action="{{ route('admin.network.monitoring.hosts.list') }}" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Search name or IP…" value="{{ request('search') }}">
                </div>
                <div class="col-6 col-md-2">
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="up"       {{ request('status') === 'up'       ? 'selected' : '' }}>Up</option>
                        <option value="down"     {{ request('status') === 'down'     ? 'selected' : '' }}>Down</option>
                        <option value="degraded" {{ request('status') === 'degraded' ? 'selected' : '' }}>Degraded</option>
                        <option value="unknown"  {{ request('status') === 'unknown'  ? 'selected' : '' }}>Unknown</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        @foreach($types as $t)
                        <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <div class="form-check ms-1">
                        <input class="form-check-input" type="checkbox" name="has_alerts" id="hasAlerts" value="1"
                               {{ request('has_alerts') ? 'checked' : '' }}>
                        <label class="form-check-label small" for="hasAlerts">Active alerts only</label>
                    </div>
                </div>
                <div class="col-12 col-md-1 d-flex gap-1">
                    <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                    <a href="{{ route('admin.network.monitoring.hosts.list') }}" class="btn btn-outline-secondary btn-sm">✕</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Hosts Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($hosts->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-broadcast display-4 d-block mb-2"></i>No hosts match the selected filters.
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Host</th>
                            <th>IP Address</th>
                            <th>Branch</th>
                            <th>Type</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Alerts</th>
                            <th class="text-center">Sensors</th>
                            <th>Last Ping</th>
                            <th>Latency</th>
                            <th>Last SNMP</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($hosts as $host)
                        @php
                            $check = $latestChecks[$host->id] ?? null;
                            $alerts = $activeAlerts[$host->id] ?? 0;
                            $statusColors = [
                                'up'       => 'success',
                                'down'     => 'danger',
                                'degraded' => 'warning',
                                'unknown'  => 'secondary',
                            ];
                            $statusColor = $statusColors[$host->status ?? 'unknown'] ?? 'secondary';
                        @endphp
                        <tr>
                            <td class="fw-semibold">{{ $host->name }}</td>
                            <td class="font-monospace text-muted">{{ $host->ip }}</td>
                            <td>{{ $host->branch?->name ?? '—' }}</td>
                            <td>
                                @if($host->type)
                                <span class="badge bg-light text-dark border">{{ ucfirst($host->type) }}</span>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-{{ $statusColor }}">
                                    {{ ucfirst($host->status ?? 'unknown') }}
                                </span>
                            </td>
                            <td class="text-center">
                                @if($alerts > 0)
                                <span class="badge bg-danger">{{ $alerts }}</span>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <span class="badge bg-info text-dark">{{ $host->snmp_sensors_count }}</span>
                            </td>
                            <td class="text-muted">
                                @if($check && $check->checked_at)
                                <span title="{{ $check->checked_at }}">
                                    {{ \Carbon\Carbon::parse($check->checked_at)->diffForHumans() }}
                                </span>
                                @elseif($host->last_ping_at)
                                {{ $host->last_ping_at->diffForHumans() }}
                                @else
                                —
                                @endif
                            </td>
                            <td>
                                @if($check && $check->latency_ms !== null)
                                <span class="text-{{ $check->latency_ms < 10 ? 'success' : ($check->latency_ms < 50 ? 'warning' : 'danger') }}">
                                    {{ round($check->latency_ms, 1) }}ms
                                </span>
                                @else
                                <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-muted">
                                @if($host->last_snmp_at)
                                {{ $host->last_snmp_at->diffForHumans() }}
                                @else
                                —
                                @endif
                            </td>
                            <td>
                                <a href="{{ route('admin.network.monitoring.show', $host) }}"
                                   class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="{{ route('admin.network.monitoring.hosts.settings', $host) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Settings">
                                    <i class="bi bi-gear"></i>
                                </a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-3 py-2 border-top d-flex align-items-center justify-content-between flex-wrap gap-2">
                <small class="text-muted">
                    Showing {{ $hosts->firstItem() }}–{{ $hosts->lastItem() }} of {{ $hosts->total() }} hosts
                </small>
                {{ $hosts->withQueryString()->links('pagination::bootstrap-5') }}
            </div>
            @endif
        </div>
    </div>

</div>
@endsection
