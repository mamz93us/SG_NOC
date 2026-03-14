@extends('layouts.admin')

@section('content')
<div class="d-flex align-items-start justify-content-between mb-4">
    <div>
        <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-link link-secondary ps-0">
            <i class="bi bi-arrow-left me-1"></i> Back to Monitoring
        </a>
        <h2 class="h3 mt-2 mb-0 fw-bold">SNMP Health Dashboard</h2>
        <p class="text-muted small mt-1 mb-0">System-wide SNMP monitoring health and diagnostics.</p>
    </div>
    <div class="d-flex gap-2 align-items-center mt-4">
        {{-- Auto-refresh indicator --}}
        <span id="autoRefreshBadge" class="badge bg-success-subtle text-success border border-success-subtle me-1" style="font-size:.75rem">
            <i class="bi bi-arrow-repeat me-1"></i>Auto-refresh: <span id="countdown">60</span>s
        </span>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleAutoRefresh()">
            <i class="bi bi-pause-fill" id="pauseIcon"></i>
        </button>

        {{-- Poll All (Async — dispatches to queue) --}}
        <form action="{{ route('admin.network.monitoring.poll-all') }}" method="POST" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" onclick="this.disabled=true; this.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Dispatching...'; this.form.submit();">
                <i class="bi bi-broadcast me-1"></i>Poll All (Queue)
            </button>
        </form>

        {{-- Poll All Sync (blocks until done) --}}
        <form action="{{ route('admin.network.monitoring.poll-all-sync') }}" method="POST" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-warning btn-sm" onclick="this.disabled=true; this.innerHTML='<span class=\'spinner-border spinner-border-sm me-1\'></span>Polling...'; this.form.submit();">
                <i class="bi bi-lightning-charge-fill me-1"></i>Poll All (Sync)
            </button>
        </form>

        {{-- Manual refresh --}}
        <a href="{{ route('admin.network.monitoring.health') }}" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-arrow-clockwise"></i>
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show border-0 shadow-sm" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

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
    <div class="card-header bg-white py-3 border-0 d-flex align-items-center justify-content-between">
        <h5 class="card-title mb-0 fw-bold"><i class="bi bi-hdd-rack me-2"></i>Host Overview</h5>
        <small class="text-muted">Last refreshed: {{ now()->format('H:i:s') }}</small>
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
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($hosts as $host)
                    @php
                        $statusColors = ['up' => 'success', 'down' => 'danger', 'degraded' => 'warning', 'unknown' => 'secondary'];
                        $color = $statusColors[$host->status] ?? 'secondary';
                        $activeSensors = $host->snmpSensors->where('status', 'active')->count();
                        $problemSensors = $host->snmpSensors->where('status', '!=', 'active')->count();
                        $isStale = $host->last_snmp_at && $host->last_snmp_at->lt(now()->subMinutes(10));
                    @endphp
                    <tr class="{{ $isStale ? 'table-warning' : '' }}">
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
                            @if($host->last_snmp_at)
                                <span class="{{ $isStale ? 'text-warning fw-bold' : '' }}">
                                    {{ $host->last_snmp_at->diffForHumans() }}
                                </span>
                                @if($isStale)
                                    <i class="bi bi-exclamation-triangle-fill text-warning ms-1" title="Stale — last polled over 10 minutes ago"></i>
                                @endif
                            @else
                                <span class="text-danger">Never</span>
                            @endif
                        </td>
                        <td class="text-end pe-3">
                            <form action="{{ route('admin.network.monitoring.hosts.force-poll', $host) }}" method="POST" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-outline-primary btn-sm py-0 px-2" title="Force poll this host"
                                    onclick="this.disabled=true; this.innerHTML='<span class=\'spinner-border spinner-border-sm\'></span>'; this.form.submit();">
                                    <i class="bi bi-broadcast"></i>
                                </button>
                            </form>
                            <a href="{{ route('admin.network.monitoring.show', $host) }}" class="btn btn-outline-secondary btn-sm py-0 px-2" title="View details">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Scheduler status info --}}
<div class="card shadow-sm border-0 mt-4">
    <div class="card-body">
        <h6 class="fw-bold mb-3"><i class="bi bi-clock-history me-2"></i>Automatic Polling Schedule</h6>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill text-success me-2 fs-5"></i>
                    <div>
                        <div class="fw-semibold small">SNMP Metrics Collection</div>
                        <div class="text-muted" style="font-size:.78rem">Every 1 minute (via queue)</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill text-success me-2 fs-5"></i>
                    <div>
                        <div class="fw-semibold small">Device Discovery</div>
                        <div class="text-muted" style="font-size:.78rem">Daily (inline)</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="d-flex align-items-center">
                    <i class="bi bi-check-circle-fill text-success me-2 fs-5"></i>
                    <div>
                        <div class="fw-semibold small">Interface Discovery</div>
                        <div class="text-muted" style="font-size:.78rem">Daily (inline)</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="alert alert-info border-0 mt-3 mb-0 py-2 small">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Tip:</strong> If hosts show stale data, ensure the Laravel scheduler is running:
            <code class="ms-1">* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1</code>
            <br>
            <span class="ms-4">Also ensure a queue worker is running: <code>php artisan queue:work --daemon</code></span>
        </div>
    </div>
</div>

<script>
    let autoRefresh = true;
    let countdownVal = 60;
    let countdownTimer;

    function startCountdown() {
        countdownVal = 60;
        document.getElementById('countdown').textContent = countdownVal;
        clearInterval(countdownTimer);
        countdownTimer = setInterval(() => {
            countdownVal--;
            document.getElementById('countdown').textContent = countdownVal;
            if (countdownVal <= 0) {
                location.reload();
            }
        }, 1000);
    }

    function toggleAutoRefresh() {
        autoRefresh = !autoRefresh;
        const badge = document.getElementById('autoRefreshBadge');
        const icon = document.getElementById('pauseIcon');
        if (autoRefresh) {
            badge.className = 'badge bg-success-subtle text-success border border-success-subtle me-1';
            icon.className = 'bi bi-pause-fill';
            startCountdown();
        } else {
            badge.className = 'badge bg-secondary-subtle text-secondary border border-secondary-subtle me-1';
            icon.className = 'bi bi-play-fill';
            clearInterval(countdownTimer);
            document.getElementById('countdown').textContent = '⏸';
        }
    }

    startCountdown();
</script>
@endsection
