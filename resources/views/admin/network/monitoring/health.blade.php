@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-link link-secondary ps-0">
        <i class="bi bi-arrow-left me-1"></i> Back to Monitoring
    </a>
    <h2 class="h3 mt-2 mb-0 fw-bold">SNMP Health Dashboard</h2>
    <p class="text-muted small mt-1">System-wide SNMP monitoring health and diagnostics.</p>
</div>

@if(!$snmpLoaded)
<div class="alert alert-danger border-0 shadow-sm d-flex align-items-center mb-4">
    <i class="bi bi-exclamation-triangle-fill fs-4 me-3"></i>
    <div>
        <strong>SNMP Extension Not Loaded</strong>
        <p class="mb-0 small">The PHP SNMP extension is not available on this server. SNMP polling will use the CLI fallback (snmpget/snmpwalk). For best performance, install the <code>php-snmp</code> extension.</p>
    </div>
</div>
@endif

<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm border-0 text-center p-4">
            <div class="display-5 fw-bold text-primary">{{ $totalHosts }}</div>
            <div class="text-muted small text-uppercase mt-1">SNMP-Enabled Hosts</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 text-center p-4">
            <div class="display-5 fw-bold text-{{ $unreachableHosts > 0 ? 'danger' : 'success' }}">{{ $unreachableHosts }}</div>
            <div class="text-muted small text-uppercase mt-1">Unreachable Hosts</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 text-center p-4">
            <div class="display-5 fw-bold text-{{ $staleSensors > 0 ? 'warning' : 'success' }}">{{ $staleSensors }}</div>
            <div class="text-muted small text-uppercase mt-1">Stale Sensors (>10m)</div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm border-0 text-center p-4">
            <div class="display-5 fw-bold text-{{ $unreachableSensors > 0 ? 'danger' : 'success' }}">{{ $unreachableSensors }}</div>
            <div class="text-muted small text-uppercase mt-1">Problem Sensors</div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="card-title mb-0 fw-bold">Host Overview</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Host</th>
                        <th>IP</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Sensors</th>
                        <th>Active / Problem</th>
                        <th>Last SNMP Poll</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hosts as $host)
                    @php
                        $statusColors = ['up' => 'success', 'down' => 'danger', 'degraded' => 'warning', 'unknown' => 'secondary'];
                        $color = $statusColors[$host->status] ?? 'secondary';
                        $activeSensors = $host->snmpSensors->where('status', 'active')->count();
                        $problemSensors = $host->snmpSensors->where('status', '!=', 'active')->count();
                    @endphp
                    <tr>
                        <td>
                            <a href="{{ route('admin.network.monitoring.show', $host) }}" class="fw-bold text-dark text-decoration-none">
                                {{ $host->name }}
                            </a>
                        </td>
                        <td><code class="text-muted">{{ $host->ip }}</code></td>
                        <td><span class="badge bg-{{ $color }}">{{ strtoupper($host->status) }}</span></td>
                        <td>{{ $host->discovered_type ?? $host->type }}</td>
                        <td>{{ $host->snmpSensors->count() }}</td>
                        <td>
                            <span class="text-success">{{ $activeSensors }}</span>
                            @if($problemSensors > 0)
                                / <span class="text-danger fw-bold">{{ $problemSensors }}</span>
                            @endif
                        </td>
                        <td class="text-muted small">
                            {{ $host->last_snmp_at ? $host->last_snmp_at->diffForHumans() : 'Never' }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
