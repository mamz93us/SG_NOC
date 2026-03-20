@extends('layouts.admin')

@push('styles')
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;700&display=swap" rel="stylesheet">
@endpush

@section('content')
<style>
    .glass-card {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2);
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .glass-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.05) !important;
    }
    .sensor-value {
        font-family: 'Outfit', sans-serif;
        font-weight: 700;
        letter-spacing: -0.02em;
    }
    .interface-pill {
        font-size: 0.7rem;
        padding: 0.2rem 0.5rem;
    }
    .sensor-status-dot {
        display: inline-block;
        width: 8px;
        height: 8px;
        border-radius: 50%;
        margin-right: 4px;
    }
    .sensor-status-active { background-color: #198754; }
    .sensor-status-unreachable { background-color: #ffc107; }
    .sensor-status-error { background-color: #dc3545; }
</style>

@if(isset($snmpLoaded) && !$snmpLoaded)
<div class="alert alert-warning border-0 shadow-sm d-flex align-items-center mb-3">
    <i class="bi bi-exclamation-triangle-fill fs-5 me-3 text-warning"></i>
    <div>
        <strong>SNMP Extension Not Loaded</strong> &mdash;
        <span class="small">Using CLI fallback for SNMP polling.</span>
    </div>
</div>
@endif

<div class="mb-4 d-flex justify-content-between align-items-end">
    <div>
        <a href="{{ route('admin.network.monitoring.index') }}" class="btn btn-link link-secondary ps-0">
            <i class="bi bi-arrow-left me-1"></i> Back to Monitoring
        </a>
        <h2 class="h3 mt-2 mb-0 fw-bold">{{ $host->name }}</h2>
        <div class="d-flex align-items-center mt-2">
            @php
                $statusColors = [
                    'up' => 'success',
                    'down' => 'danger',
                    'degraded' => 'warning',
                    'unknown' => 'secondary'
                ];
                $color = $statusColors[$host->status] ?? 'secondary';
            @endphp
            <span class="badge bg-{{ $color }} me-3 shadow-sm">
                {{ strtoupper($host->status) }}
            </span>
            <code class="text-muted pe-3 border-end me-3 bg-light px-2 rounded">{{ $host->ip }}</code>
            <span class="text-muted small">Type: <span class="fw-bold text-dark">{{ strtoupper($host->type) }}</span></span>
            @if($host->discovered_type)
                <span class="badge bg-info-subtle text-info ms-2">{{ ucfirst($host->discovered_type) }}</span>
            @endif
        </div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        @if($host->snmp_enabled)
        <div class="input-group input-group-sm">
            <form action="{{ route('admin.network.monitoring.hosts.discover-device', $host) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-info border-end-0 rounded-start">
                    <i class="bi bi-search me-1"></i> Discover
                </button>
            </form>
            <form action="{{ route('admin.network.monitoring.hosts.discover-interfaces', $host) }}" method="POST" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-secondary rounded-end">
                    <i class="bi bi-hdd-network me-1"></i> Interfaces
                </button>
            </form>
        </div>
        @endif
        @if($host->ping_enabled)
        <form action="{{ route('admin.network.monitoring.hosts.ping', $host) }}" method="POST" class="m-0">
            @csrf
            <button type="submit" class="btn btn-success btn-sm px-3 shadow-sm" title="Manually ping this host now">
                <i class="bi bi-activity me-1"></i> Ping Now
            </button>
        </form>
        @endif
        <a href="{{ route('admin.network.monitoring.hosts.settings', $host) }}" class="btn btn-outline-secondary btn-sm px-3 shadow-sm">
            <i class="bi bi-gear me-1"></i> Settings
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Latency Graph -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 overflow-hidden">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 fw-bold">Latency & Availability</h5>
                <span class="text-muted small">Real-time Ping Statistics (24h)</span>
            </div>
            <div class="card-body pt-0">
                <div class="position-relative w-100" style="height: 250px;">
                    <canvas id="latencyChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Host Info & Stats -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 mb-4 text-white p-2" style="background: linear-gradient(135deg, #212529 0%, #343a40 100%);">
            <div class="card-body">
                <h6 class="text-white-50 small text-uppercase mb-4 opacity-75">Connectivity Performance</h6>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="opacity-75">Avg Latency</span>
                    <span class="h3 mb-0 fw-bold sensor-value text-info">{{ round($host->hostChecks->avg('latency_ms') ?? 0, 1) }}<small class="fs-6 ms-1 fw-normal opacity-50">ms</small></span>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="opacity-75">Packet Loss</span>
                    <span class="h3 mb-0 fw-bold sensor-value text-{{ $host->hostChecks->avg('packet_loss') > 5 ? 'danger' : 'success' }}">
                        {{ round($host->hostChecks->avg('packet_loss') ?? 0, 1) }}<small class="fs-6 ms-1 fw-normal opacity-50">%</small>
                    </span>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4 bg-light-subtle">
            <div class="card-header bg-transparent py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0 fw-bold text-muted text-uppercase small">Quick Actions</h6>
            </div>
            <div class="card-body pt-0">
                <form action="{{ route('admin.network.monitoring.hosts.force-poll', $host) }}" method="POST" class="mb-2">
                    @csrf
                    <button type="submit" class="btn btn-outline-info w-100">
                        <i class="bi bi-arrow-clockwise me-1"></i> Force Poll Now
                    </button>
                </form>
                <a href="{{ route('admin.network.monitoring.hosts.settings', $host) }}" class="btn btn-outline-secondary w-100 mb-2">
                    <i class="bi bi-gear me-1"></i> Manage Sensors & MIB
                </a>
                @if($host->mib)
                    <div class="d-flex align-items-center mt-3 mb-1">
                        <i class="bi bi-file-earmark-code text-primary me-2"></i>
                        <div class="small">
                            <span class="fw-bold">{{ $host->mib->name }}</span>
                            <span class="text-muted d-block x-small">{{ basename($host->mib->file_path) }}</span>
                        </div>
                    </div>
                @endif
                <div class="text-muted small mt-2">
                    <i class="bi bi-cpu me-1"></i> {{ $host->snmpSensors->count() }} sensors configured
                </div>
            </div>
        </div>
    </div>
</div>

@php
    $groupedSensors = ['General' => [], 'Interfaces' => [], 'Extensions' => [], 'Trunks' => [], 'VPN' => []];
    $vpnGrouped = [];
    // interface_errors: keyed by interface_index, each entry holds In Errors/Out Errors/In Discards/Out Discards sensors
    // interface_duplex: keyed by interface_index, holds duplex sensor
    $interfaceErrorSensors = [];  // [ index => [ 'In Errors' => sensor, 'Out Errors' => sensor, ... ] ]
    $interfaceDuplexSensors = []; // [ index => sensor ]

    foreach($host->snmpSensors as $sensor) {
        $name = $sensor->name ?: $sensor->oid;

        // Collect error counter sensors separately for the Interface Errors table
        if ($sensor->sensor_group === 'interface_errors') {
            $idx = $sensor->interface_index ?? 0;
            // Determine the column key from the sensor name suffix
            if (str_ends_with($name, ' In Errors')) {
                $interfaceErrorSensors[$idx]['In Errors'] = $sensor;
            } elseif (str_ends_with($name, ' Out Errors')) {
                $interfaceErrorSensors[$idx]['Out Errors'] = $sensor;
            } elseif (str_ends_with($name, ' In Discards')) {
                $interfaceErrorSensors[$idx]['In Discards'] = $sensor;
            } elseif (str_ends_with($name, ' Out Discards')) {
                $interfaceErrorSensors[$idx]['Out Discards'] = $sensor;
            }
            continue;
        }

        // Collect duplex sensors separately
        if ($sensor->sensor_group === 'interface_duplex') {
            $idx = $sensor->interface_index ?? 0;
            $interfaceDuplexSensors[$idx] = $sensor;
            continue;
        }

        // Group by explicitly set sensor_group first
        if ($sensor->sensor_group && isset($groupedSensors[$sensor->sensor_group])) {
            if ($sensor->sensor_group === 'VPN') {
                if (preg_match('/^VPN:\s*(.*?)\s*-\s*(Active|Connection)$/', $name, $m)) {
                    $vpnName = $m[1];
                    $type = $m[2];
                    $vpnGrouped[$vpnName][$type] = $sensor;
                } else {
                    $groupedSensors['VPN'][] = $sensor;
                }
            } else {
                $groupedSensors[$sensor->sensor_group][] = $sensor;
            }
            continue;
        }

        if (preg_match('/^(.*)\s+-\s+(Traffic In|Traffic Out|Status)$/', $name, $matches)) {
            $interfaceName = $matches[1];
            $type = $matches[2];
            $groupedSensors['Interfaces'][$interfaceName][$type] = $sensor;
        } elseif ($sensor->sensor_group === 'Extensions' || str_starts_with($name, 'Ext ')) {
            $groupedSensors['Extensions'][] = $sensor;
        } elseif ($sensor->sensor_group === 'Trunks' || str_starts_with($name, 'Trunk ')) {
            $groupedSensors['Trunks'][] = $sensor;
        } else {
            $groupedSensors['General'][] = $sensor;
        }
    }

    // Build a combined per-interface error table: merge error sensors with duplex sensors by index
    // We need interface names — pull them from the interface traffic sensors
    $interfaceNamesByIndex = [];
    foreach ($groupedSensors['Interfaces'] as $ifaceName => $sensors) {
        $firstSensor = reset($sensors);
        if ($firstSensor && $firstSensor->interface_index) {
            $interfaceNamesByIndex[$firstSensor->interface_index] = $ifaceName;
        }
    }
    // Gather all indexes that have error or duplex data
    $errorIndexes = array_unique(array_merge(array_keys($interfaceErrorSensors), array_keys($interfaceDuplexSensors)));
    sort($errorIndexes);
@endphp

<style>
    .glass-card {
        background: rgba(255, 255, 255, 0.7);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border: 1px solid rgba(255, 255, 255, 0.2) !important;
        transition: all 0.3s ease;
    }
    .glass-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
        background: rgba(255, 255, 255, 0.85);
    }
    .scrollable-table {
        max-height: 500px;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: rgba(0,0,0,0.1) transparent;
    }
    .scrollable-table::-webkit-scrollbar {
        width: 6px;
    }
    .scrollable-table::-webkit-scrollbar-thumb {
        background-color: rgba(0,0,0,0.1);
        border-radius: 10px;
    }
    .sensor-status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 8px rgba(0,0,0,0.1);
    }
    .sensor-status-active { background-color: #00f2fe; box-shadow: 0 0 10px rgba(0, 242, 254, 0.5); }
    .sensor-status-unreachable { background-color: #f9d423; box-shadow: 0 0 10px rgba(249, 212, 35, 0.5); }
    .sensor-status-error { background-color: #ff0844; box-shadow: 0 0 10px rgba(255, 8, 68, 0.5); }
    
    .metric-value {
        font-family: 'Outfit', sans-serif;
        font-weight: 700;
        letter-spacing: -0.5px;
    }
    .pb-glow-text {
        text-shadow: 0 0 15px rgba(13, 110, 253, 0.2);
    }
</style>

<!-- General Sensors -->
@if(!empty($groupedSensors['General']))
<h6 class="text-uppercase text-muted fw-bold small mb-3"><i class="bi bi-cpu-fill me-2"></i>System & Performance Sensors</h6>
<div class="row g-4 mb-5">
    @foreach($groupedSensors['General'] as $sensor)
        @php $latest = $sensor->sensorMetrics->first(); @endphp
        <div class="col-md-6 col-lg-4">
            <div class="card shadow-sm border-0 glass-card h-100">
                <div class="card-body d-flex flex-column">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h6 class="card-title mb-0 text-muted fw-bold small text-uppercase">
                                <span class="sensor-status-dot sensor-status-{{ $sensor->status ?? 'active' }}"></span>
                                {{ $sensor->name }}
                            </h6>
                            <span class="badge bg-light text-muted fw-normal interface-pill border">{{ $sensor->data_type }}</span>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            @if($latest)
                                <div class="text-muted x-small">Last polled: {{ $latest->recorded_at->diffForHumans() }}</div>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary small">No Data</span>
                            @endif
                            <a href="{{ route('admin.sensors.chart', $sensor) }}"
                               class="btn btn-outline-secondary py-0 px-1"
                               style="font-size:0.7rem;line-height:1.4"
                               title="View historical chart">
                                <i class="bi bi-graph-up"></i>
                            </a>
                        </div>
                    </div>
                    
                    <div class="mt-auto">
                        @if($latest)
                            @if($sensor->data_type === 'uptime')
                                {{-- UPTIME DISPLAY --}}
                                @php
                                    $rawVal = (float)$latest->value;
                                    $unit = strtolower(trim($sensor->unit));
                                    // sysUpTime is in centiseconds (1/100s)
                                    // If raw value is very large (e.g. > 10^7), it's likely centiseconds
                                    // If small, it might already be seconds. We assume centiseconds if no unit match.
                                    $isExplicitSeconds = in_array($unit, ['s', 'sec', 'seconds', 'second']);
                                    $totalSeconds = $isExplicitSeconds ? $rawVal : ($rawVal / 100);
                                    
                                    $days = (int)floor($totalSeconds / 86400);
                                    $hours = (int)floor(($totalSeconds % 86400) / 3600);
                                    $mins = (int)floor(($totalSeconds % 3600) / 60);
                                @endphp
                                <div class="text-center py-3">
                                    <div class="d-flex align-items-center justify-content-center gap-3 mb-2">
                                        <div class="text-center">
                                            <div class="display-6 fw-bold text-primary metric-value">{{ $days }}</div>
                                            <div class="text-muted small">days</div>
                                        </div>
                                        <span class="display-6 text-muted opacity-25">:</span>
                                        <div class="text-center">
                                            <div class="display-6 fw-bold text-primary metric-value">{{ $hours }}</div>
                                            <div class="text-muted small">hrs</div>
                                        </div>
                                        <span class="display-6 text-muted opacity-25">:</span>
                                        <div class="text-center">
                                            <div class="display-6 fw-bold text-primary metric-value">{{ $mins }}</div>
                                            <div class="text-muted small">min</div>
                                        </div>
                                    </div>
                                    <div class="text-muted small"><i class="bi bi-clock-history me-1"></i> System Uptime</div>
                                </div>
                            @elseif($sensor->data_type === 'boolean')
                                <div class="text-center py-3">
                                    <div class="display-5 fw-bold {{ $latest->value == 1 ? 'text-success' : 'text-danger' }}">
                                        <i class="bi bi-{{ $latest->value == 1 ? 'check-circle' : 'exclamation-circle' }}-fill"></i>
                                        {{ $latest->value == 1 ? 'ACTIVE' : 'INACTIVE' }}
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-3">
                                    <div class="display-5 fw-bold text-dark metric-value">
                                        {{ number_format($latest->value, ($latest->value == (int)$latest->value ? 0 : 2)) }}<span class="fs-4 text-muted ms-1">{{ $sensor->unit }}</span>
                                    </div>
                                </div>
                            @endif
                        @else
                            <div class="text-center py-4 text-muted opacity-50">
                                <i class="bi bi-hourglass-top fs-2 d-block mb-1"></i>
                                <div class="small">Waiting for poll...</div>
                            </div>
                        @endif
                    </div>

                    @if($sensor->graph_enabled)
                        <div class="mt-3" style="height: 60px;">
                            <canvas id="chart-sensor-{{ $sensor->id }}"></canvas>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif

<!-- Extension & Trunk Sensors -->
@if(!empty($groupedSensors['Extensions']) || !empty($groupedSensors['Trunks']))
<div class="row g-4 mb-5">
    @if(!empty($groupedSensors['Extensions']))
    <div class="col-lg-6">
        <h6 class="text-uppercase text-muted fw-bold small mb-3"><i class="bi bi-telephone-fill me-2"></i>PBX Extensions</h6>
        <div class="card shadow-sm border-0 glass-card">
            <div class="card-body p-0">
                <div class="table-responsive scrollable-table">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th class="ps-3">Extension</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Last Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($groupedSensors['Extensions'] as $sensor)
                                @php $latest = $sensor->sensorMetrics->first(); @endphp
                                <tr>
                                    <td class="ps-3 fw-bold">{{ $sensor->name }}</td>
                                    <td>
                                        @if($latest)
                                            <span class="badge bg-{{ $latest->value == 1 ? 'success' : 'danger' }}-subtle text-{{ $latest->value == 1 ? 'success' : 'danger' }} px-3 rounded-pill">
                                                <i class="bi bi-circle-fill me-1 x-small"></i> {{ $latest->value == 1 ? 'Registered' : 'Unavailable' }}
                                            </span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary px-3 rounded-pill">Unknown</span>
                                        @endif
                                    </td>
                                    <td class="text-end pe-3 text-muted x-small">
                                        {{ $latest ? $latest->recorded_at->diffForHumans() : 'Never' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif

    @if(!empty($groupedSensors['Trunks']))
    <div class="col-lg-6">
        <h6 class="text-uppercase text-muted fw-bold small mb-3"><i class="bi bi-shuffle me-2"></i>Trunk Lines</h6>
        <div class="card shadow-sm border-0 glass-card">
            <div class="card-body p-0">
                <div class="table-responsive scrollable-table">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="bg-light sticky-top">
                            <tr>
                                <th class="ps-3">Trunk Name</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Last Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($groupedSensors['Trunks'] as $sensor)
                                @php $latest = $sensor->sensorMetrics->first(); @endphp
                                <tr>
                                    <td class="ps-3 fw-bold">{{ $sensor->name }}</td>
                                    <td>
                                        @if($latest)
                                            <span class="badge bg-{{ $latest->value == 1 ? 'success' : 'danger' }}-subtle text-{{ $latest->value == 1 ? 'success' : 'danger' }} px-3 rounded-pill">
                                                <i class="bi bi-{{ $latest->value == 1 ? 'check' : 'x' }}-lg me-1"></i> {{ $latest->value == 1 ? 'Up' : 'Down' }}
                                            </span>
                                        @else
                                            <span class="badge bg-secondary-subtle text-secondary px-3 rounded-pill">Unknown</span>
                                        @endif
                                    </td>
                                    <td class="text-end pe-3 text-muted x-small">
                                        {{ $latest ? $latest->recorded_at->diffForHumans() : 'Never' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    @endif
</div>
@endif

<!-- Sophos VPN Table -->
@if(!empty($vpnGrouped))
<div class="row mb-5">
    <div class="col-12">
        <div class="d-flex align-items-center mb-3">
            <h6 class="text-uppercase text-muted fw-bold small mb-0"><i class="bi bi-shield-lock-fill me-2 text-primary"></i>Site-to-Site VPN Connections</h6>
            <span class="badge bg-primary-subtle text-primary ms-3">{{ count($vpnGrouped) }} Tunnels</span>
        </div>
        <div class="card shadow-sm border-0 glass-card">
            <div class="card-body p-0">
                <div class="table-responsive scrollable-table" style="max-height: 600px;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-dark text-white sticky-top">
                            <tr>
                                <th class="ps-4 py-3" style="width: 40%">Tunnel Name</th>
                                <th class="text-center py-3">Active Status</th>
                                <th class="text-center py-3">Connection Status</th>
                                <th class="text-end pe-4 py-3">Last Update</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($vpnGrouped as $vpnName => $sensors)
                                @php 
                                    $active = $sensors['Active'] ?? null;
                                    $conn = $sensors['Connection'] ?? null;
                                    $activeLatest = $active ? $active->sensorMetrics->first() : null;
                                    $connLatest = $conn ? $conn->sensorMetrics->first() : null;
                                @endphp
                                <tr>
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark">{{ $vpnName }}</div>
                                        <div class="x-small text-muted">IPSec Policy-based</div>
                                    </td>
                                    <td class="text-center py-3">
                                        @if($activeLatest)
                                            <div class="d-flex flex-column align-items-center">
                                                <i class="bi bi-circle-fill {{ $activeLatest->value == 1 ? 'text-success' : 'text-danger' }} mb-1" style="font-size: 0.8rem; filter: drop-shadow(0 0 5px {{ $activeLatest->value == 1 ? 'rgba(25,135,84,0.5)' : 'rgba(220,53,69,0.5)' }});"></i>
                                                <span class="x-small fw-bold {{ $activeLatest->value == 1 ? 'text-success' : 'text-danger' }}">
                                                    {{ $activeLatest->value == 1 ? 'ENABLED' : 'DISABLED' }}
                                                </span>
                                            </div>
                                        @else
                                            <span class="text-muted small">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-center py-3">
                                        @if($connLatest)
                                            <div class="d-flex flex-column align-items-center">
                                                <i class="bi bi-circle-fill {{ $connLatest->value == 1 ? 'text-success' : 'text-danger' }} mb-1" style="font-size: 0.8rem; filter: drop-shadow(0 0 5px {{ $connLatest->value == 1 ? 'rgba(25,135,84,0.5)' : 'rgba(220,53,69,0.5)' }});"></i>
                                                <span class="x-small fw-bold {{ $connLatest->value == 1 ? 'text-success' : 'text-danger' }}">
                                                    {{ $connLatest->value == 1 ? 'ESTABLISHED' : 'DISCONNECTED' }}
                                                </span>
                                            </div>
                                        @else
                                            <span class="text-muted small">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-end pe-4 py-3">
                                        <span class="badge bg-light text-muted fw-normal x-small border">
                                            {{ $connLatest ? $connLatest->recorded_at->diffForHumans() : ($activeLatest ? $activeLatest->recorded_at->diffForHumans() : 'Never') }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<!-- Switch Port Panel -->
@if(!empty($groupedSensors['Interfaces']) && ($host->type === 'switch' || count($groupedSensors['Interfaces']) > 4))
<div class="card shadow-sm border-0 mb-4 glass-card overflow-hidden">
    <div class="card-header bg-dark text-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="bi bi-ethernet me-2"></i>Switch Port Panel</h6>
        <div class="small">
            @php
                $upCount = 0;
                $totalPorts = 0;
                foreach($groupedSensors['Interfaces'] as $iface => $s) {
                    if (isset($s['Status'])) {
                        $totalPorts++;
                        if (($s['Status']->sensorMetrics->first()?->value ?? 0) == 1) $upCount++;
                    }
                }
            @endphp
            <span class="badge bg-success me-1">{{ $upCount }} UP</span>
            <span class="badge bg-secondary">{{ $totalPorts }} Total</span>
        </div>
    </div>
    <div class="card-body py-4 bg-light">
        <div class="d-flex flex-wrap gap-2 justify-content-center" id="snmpPortGrid">
            @php
                // Sort interfaces by index if possible
                $sortedIfaces = collect($groupedSensors['Interfaces'])->sortBy(function($sensors) {
                    return $sensors['Status']->interface_index ?? 999;
                });
            @endphp
            @foreach($sortedIfaces as $iface => $sensors)
                @if(isset($sensors['Status']))
                    @php
                        $statusSensor = $sensors['Status'];
                        $latestStatus = $statusSensor->sensorMetrics->first()?->value ?? 0;
                        $isUp = ($latestStatus == 1);
                        $colorClass = $isUp ? 'bg-success' : 'bg-secondary opacity-50';
                        $tooltip = "Port: $iface";
                        if ($statusSensor->description) {
                            $tooltip .= "\nDescription: " . $statusSensor->description;
                        }
                    @endphp
                    <div class="port-tile {{ $colorClass }} text-white rounded d-flex flex-column align-items-center justify-content-center position-relative"
                         style="width: 50px; height: 55px; cursor: pointer; border: 2px solid rgba(0,0,0,0.1); transition: all 0.2s;"
                         data-bs-toggle="tooltip" data-bs-placement="top" data-bs-html="true" title="{{ nl2br(e($tooltip)) }}"
                         onclick="scrollToInterface('{{ addslashes($iface) }}')">
                        <i class="bi bi-ethernet" style="font-size: 1.2rem;"></i>
                        <span style="font-size: 0.65rem; font-weight: bold; margin-top: 2px;">{{ Str::limit($iface, 6, '') }}</span>
                        @if($isUp)
                            <span class="position-absolute top-0 start-100 translate-middle p-1 bg-success border border-light rounded-circle" style="z-index: 2;"></span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
        <div class="mt-4 text-center text-muted small">
            <i class="bi bi-info-circle me-1"></i> Click a port to view its real-time traffic graphs and performance metrics below.
        </div>
    </div>
</div>

<script>
    function scrollToInterface(ifaceId) {
        const el = document.getElementById('interface-' + btoa(ifaceId).replace(/=/g, ''));
        if (el) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('border-primary');
            el.style.boxShadow = '0 0 15px rgba(13, 110, 253, 0.3)';
            setTimeout(() => {
                el.classList.remove('border-primary');
                el.style.boxShadow = '';
            }, 2000);
        }
    }
</script>
@endif

<!-- Interface Sensors -->
@if(!empty($groupedSensors['Interfaces']))
<h6 class="text-uppercase text-muted fw-bold small mb-3">Network Interfaces</h6>
<div class="row g-3">
    @foreach($groupedSensors['Interfaces'] as $iface => $sensors)
        <div class="col-12" id="interface-{{ str_replace('=', '', base64_encode($iface)) }}">
            <div class="card shadow-sm border-0 overflow-hidden">
                <div class="card-body p-0">
                    <div class="row g-0">
                        <div class="col-md-3 bg-light p-4 border-end d-flex flex-column justify-content-center">
                            <h5 class="fw-bold mb-1 text-dark">{{ $iface }}</h5>
                            @if(isset($sensors['Status']))
                                @php $statusVal = $sensors['Status']->sensorMetrics->first()?->value; @endphp
                                <span class="badge bg-{{ $statusVal == 1 ? 'success' : 'danger' }}-subtle text-{{ $statusVal == 1 ? 'success' : 'danger' }} d-inline-block align-self-start mb-3">
                                    <i class="bi bi-circle-fill me-1 small"></i> {{ $statusVal == 1 ? 'UP' : 'DOWN' }}
                                </span>
                            @endif
                            <div class="mt-auto">
                                @if(isset($sensors['Traffic In']))
                                    @php
                                        $inValBytes = $sensors['Traffic In']->sensorMetrics->first()?->value ?? 0;
                                        $inValBits = $inValBytes * 8;
                                        $inFormatted = $inValBits > 1000000 ? number_format($inValBits / 1000000, 2) . ' Mbps' : ($inValBits > 1000 ? number_format($inValBits / 1000, 2) . ' Kbps' : number_format($inValBits, 0) . ' bps');
                                    @endphp
                                    <div class="small text-muted mb-1">Inbound Traffic</div>
                                    <div class="h5 fw-bold sensor-value mb-3 text-info">
                                        {{ $inFormatted }}
                                    </div>
                                @endif
                                @if(isset($sensors['Traffic Out']))
                                    @php
                                        $outValBytes = $sensors['Traffic Out']->sensorMetrics->first()?->value ?? 0;
                                        $outValBits = $outValBytes * 8;
                                        $outFormatted = $outValBits > 1000000 ? number_format($outValBits / 1000000, 2) . ' Mbps' : ($outValBits > 1000 ? number_format($outValBits / 1000, 2) . ' Kbps' : number_format($outValBits, 0) . ' bps');
                                    @endphp
                                    <div class="small text-muted mb-1">Outbound Traffic</div>
                                    <div class="h5 fw-bold sensor-value text-primary">
                                        {{ $outFormatted }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="col-md-9 p-3">
                            <div class="row g-3">
                                @if(isset($sensors['Traffic In']))
                                <div class="col-md-6">
                                    <div class="text-muted small mb-2 fw-bold text-uppercase">Inbound Traffic</div>
                                    <div style="height: 100px;">
                                        <canvas id="chart-sensor-{{ $sensors['Traffic In']->id }}"></canvas>
                                    </div>
                                </div>
                                @endif
                                @if(isset($sensors['Traffic Out']))
                                <div class="col-md-6">
                                    <div class="text-muted small mb-2 fw-bold text-uppercase">Outbound Traffic</div>
                                    <div style="height: 100px;">
                                        <canvas id="chart-sensor-{{ $sensors['Traffic Out']->id }}"></canvas>
                                    </div>
                                </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
@endif

<!-- Interface Errors & Duplex -->
@if(!empty($errorIndexes))
<style>
    .err-cell-zero    { color: #6c757d; }
    .err-cell-warn    { color: #856404; background-color: #fff3cd; border-radius: 4px; padding: 2px 6px; }
    .err-cell-crit    { color: #842029; background-color: #f8d7da; border-radius: 4px; padding: 2px 6px; }
    .duplex-full      { background-color: #d1e7dd; color: #0f5132; }
    .duplex-half      { background-color: #f8d7da; color: #842029; }
    .duplex-unknown   { background-color: #e2e3e5; color: #41464b; }
</style>
<div class="mt-5 mb-4">
    <h6 class="text-uppercase text-muted fw-bold small mb-3">
        <i class="bi bi-exclamation-triangle-fill me-2 text-warning"></i>Interface Errors &amp; Duplex Status
    </h6>
    <div class="card shadow-sm border-0 glass-card overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="bg-dark text-white sticky-top">
                        <tr>
                            <th class="ps-4 py-3" style="min-width:130px;">Interface</th>
                            <th class="text-center py-3">In Errors/s</th>
                            <th class="text-center py-3">Out Errors/s</th>
                            <th class="text-center py-3">In Discards/s</th>
                            <th class="text-center py-3">Out Discards/s</th>
                            <th class="text-center py-3">Duplex</th>
                            <th class="text-end pe-4 py-3">Last Polled</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($errorIndexes as $idx)
                            @php
                                $ifName = $interfaceNamesByIndex[$idx] ?? ('Index ' . $idx);

                                $inErr   = $interfaceErrorSensors[$idx]['In Errors']    ?? null;
                                $outErr  = $interfaceErrorSensors[$idx]['Out Errors']   ?? null;
                                $inDisc  = $interfaceErrorSensors[$idx]['In Discards']  ?? null;
                                $outDisc = $interfaceErrorSensors[$idx]['Out Discards'] ?? null;
                                $duplexS = $interfaceDuplexSensors[$idx] ?? null;

                                $inErrVal   = $inErr   ? ($inErr->sensorMetrics->first()?->value   ?? null) : null;
                                $outErrVal  = $outErr  ? ($outErr->sensorMetrics->first()?->value  ?? null) : null;
                                $inDiscVal  = $inDisc  ? ($inDisc->sensorMetrics->first()?->value  ?? null) : null;
                                $outDiscVal = $outDisc ? ($outDisc->sensorMetrics->first()?->value ?? null) : null;
                                $duplexVal  = $duplexS ? ($duplexS->sensorMetrics->first()?->value ?? null) : null;

                                // Determine CSS class for an error/discard rate value
                                $errClass = function($v) {
                                    if ($v === null) return 'text-muted';
                                    if ($v >= 10)  return 'err-cell-crit fw-bold';
                                    if ($v >= 1)   return 'err-cell-warn fw-bold';
                                    return 'err-cell-zero';
                                };

                                $fmt = function($v) {
                                    if ($v === null) return '—';
                                    return number_format($v, $v < 1 ? 2 : 0);
                                };

                                // Duplex badge
                                if ($duplexVal === null) {
                                    $duplexLabel = '—';
                                    $duplexClass = '';
                                } elseif ((int)$duplexVal === 3) {
                                    $duplexLabel = 'Full';
                                    $duplexClass = 'duplex-full';
                                } elseif ((int)$duplexVal === 2) {
                                    $duplexLabel = 'Half';
                                    $duplexClass = 'duplex-half';
                                } else {
                                    $duplexLabel = 'Unknown';
                                    $duplexClass = 'duplex-unknown';
                                }

                                // Last polled: pick the most recent sensorMetrics timestamp among error sensors
                                $lastPolled = null;
                                foreach ([$inErr, $outErr, $inDisc, $outDisc, $duplexS] as $s) {
                                    if ($s) {
                                        $ts = $s->sensorMetrics->first()?->recorded_at;
                                        if ($ts && ($lastPolled === null || $ts->gt($lastPolled))) {
                                            $lastPolled = $ts;
                                        }
                                    }
                                }
                            @endphp
                            <tr>
                                <td class="ps-4 py-3 fw-bold text-dark">{{ $ifName }}</td>
                                <td class="text-center py-3">
                                    <span class="{{ $errClass($inErrVal) }}">{{ $fmt($inErrVal) }}</span>
                                </td>
                                <td class="text-center py-3">
                                    <span class="{{ $errClass($outErrVal) }}">{{ $fmt($outErrVal) }}</span>
                                </td>
                                <td class="text-center py-3">
                                    <span class="{{ $errClass($inDiscVal) }}">{{ $fmt($inDiscVal) }}</span>
                                </td>
                                <td class="text-center py-3">
                                    <span class="{{ $errClass($outDiscVal) }}">{{ $fmt($outDiscVal) }}</span>
                                </td>
                                <td class="text-center py-3">
                                    @if($duplexVal !== null)
                                        <span class="badge px-3 py-1 rounded-pill {{ $duplexClass }}">
                                            @if((int)$duplexVal === 2)
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>
                                            @elseif((int)$duplexVal === 3)
                                                <i class="bi bi-check-circle-fill me-1"></i>
                                            @endif
                                            {{ $duplexLabel }}
                                        </span>
                                    @else
                                        <span class="text-muted small">—</span>
                                    @endif
                                </td>
                                <td class="text-end pe-4 py-3 text-muted x-small">
                                    {{ $lastPolled ? $lastPolled->diffForHumans() : 'Never' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-transparent border-0 text-muted small py-2 px-4">
            <i class="bi bi-info-circle me-1"></i>
            Error/discard values are rates (per second). Color thresholds: <span class="err-cell-warn fw-bold px-1">1–9 = warning</span>, <span class="err-cell-crit fw-bold px-1">10+ = critical</span>.
            Duplex: <span class="badge duplex-full px-2">Full</span> = normal, <span class="badge duplex-half px-2">Half</span> = possible mismatch.
        </div>
    </div>
</div>
@endif

<!-- Assign MIB Modal -->
<div class="modal fade" id="assignMibModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('admin.network.monitoring.hosts.mib-assign', $host) }}" method="POST">
            @csrf
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-dark text-white py-3">
                    <h5 class="modal-title">Link Vendor MIB</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <p class="text-muted small mb-4">Select a MIB file that matches this device's manufacturer. This helps the discovery engine identify system sensors and interface descriptions properly.</p>
                    <div class="col-12">
                        <label class="form-label fw-bold small">Available MIBs</label>
                        <select name="mib_id" class="form-select" required>
                            <option value="">-- No MIB --</option>
                            @foreach($mibs as $mib)
                                <option value="{{ $mib->id }}" {{ $host->mib_id == $mib->id ? 'selected' : '' }}>
                                    {{ $mib->name }} ({{ basename($mib->file_path) }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 bg-light rounded-bottom">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Assignment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modals & Scripts remain similar but with improved logic -->
<div class="modal fade" id="addSensorModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('admin.network.monitoring.hosts.sensors.store', $host) }}" method="POST">
            @csrf
            <div class="modal-content border-0 shadow-lg">
                <div class="modal-header bg-primary text-white py-3">
                    <h5 class="modal-title">Add Custom SNMP Sensor</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-bold small">Sensor Name</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Core CPU Usage" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-bold small">OID (Object Identifier)</label>
                            <input type="text" name="oid" class="form-control" placeholder="1.3.6.1.4.1.9.2.1.57" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Data Type</label>
                            <select name="data_type" class="form-select" required>
                                <option value="gauge">Gauge (CPU, RAM)</option>
                                <option value="counter">Counter (Traffic)</option>
                                <option value="rate">Rate (Packets/sec)</option>
                                <option value="temperature">Temperature</option>
                                <option value="uptime">Uptime</option>
                                <option value="boolean">Boolean (Status)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-bold small">Unit</label>
                            <input type="text" name="unit" class="form-control" placeholder="e.g. %, bytes, °C">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mt-2">
                                <input class="form-check-input" type="checkbox" name="graph_enabled" value="1" id="graphSwitch" checked>
                                <label class="form-check-label fw-bold ms-2" for="graphSwitch">Enable Graphing</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer px-4 py-3 bg-light rounded-bottom">
                    <button type="button" class="btn btn-link link-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Add Sensor</button>
                </div>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-date-fns/dist/chartjs-adapter-date-fns.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@2"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.color = '#6c757d';

    // 1. Latency Chart
    const latencyCtx = document.getElementById('latencyChart').getContext('2d');
    const latencyData = @json($host->hostChecks ? $host->hostChecks->take(144)->sortBy('checked_at')->values() : []);

    new Chart(latencyCtx, {
        type: 'line',
        data: {
            labels: latencyData.map(d => new Date(d.checked_at)),
            datasets: [{
                label: 'Latency (ms)',
                data: latencyData.map(d => ({x: new Date(d.checked_at), y: d.latency_ms})),
                borderColor: '#0d6efd',
                backgroundColor: 'rgba(13, 110, 253, 0.05)',
                fill: true,
                tension: 0.4,
                pointRadius: 0,
                pointHoverRadius: 6,
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#212529',
                    padding: 12,
                    callbacks: {
                        label: ctx => `Latency: ${ctx.parsed.y} ms`
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grace: '10%',
                    grid: { color: 'rgba(0,0,0,0.03)', drawBorder: false },
                    ticks: { callback: v => v + ' ms' }
                },
                x: {
                    type: 'time',
                    time: { unit: 'hour' },
                    grid: { display: false }
                }
            }
        }
    });

    // 2. Sensor Metric Charts with threshold annotations
@foreach($host->snmpSensors->where('graph_enabled', true) as $sensor)
        (function() {
            const ctx = document.getElementById('chart-sensor-{{ $sensor->id }}');
            if (!ctx) return;

            @php
                $metrics = $sensor->sensorMetrics()
                    ->orderBy('recorded_at', 'desc')
                    ->limit(150)
                    ->get()
                    ->reverse()
                    ->map(fn($m) => ['t' => $m->recorded_at->toIso8601String(), 'y' => $m->value]);
            @endphp
            const sensorData = @json($metrics->values());
            const color = '{{ str_contains($sensor->name, "Out") ? "#6610f2" : "#0dcaf0" }}';
            const warnThreshold = {{ $sensor->warning_threshold !== null ? $sensor->warning_threshold : 'null' }};
            const critThreshold = {{ $sensor->critical_threshold !== null ? $sensor->critical_threshold : 'null' }};

            // Build threshold annotations
            const annotations = {};
            if (warnThreshold !== null) {
                annotations.warningLine = {
                    type: 'line',
                    yMin: warnThreshold,
                    yMax: warnThreshold,
                    borderColor: 'rgba(255, 193, 7, 0.6)',
                    borderWidth: 1,
                    borderDash: [4, 4],
                    label: {
                        display: false
                    }
                };
                annotations.warningBox = {
                    type: 'box',
                    yMin: warnThreshold,
                    yMax: critThreshold !== null ? critThreshold : undefined,
                    backgroundColor: 'rgba(255, 193, 7, 0.05)',
                    borderWidth: 0
                };
            }
            if (critThreshold !== null) {
                annotations.criticalLine = {
                    type: 'line',
                    yMin: critThreshold,
                    yMax: critThreshold,
                    borderColor: 'rgba(220, 53, 69, 0.6)',
                    borderWidth: 1,
                    borderDash: [4, 4],
                    label: {
                        display: false
                    }
                };
                annotations.criticalBox = {
                    type: 'box',
                    yMin: critThreshold,
                    backgroundColor: 'rgba(220, 53, 69, 0.05)',
                    borderWidth: 0
                };
            }

            new Chart(ctx.getContext('2d'), {
                type: 'line',
                data: {
                    datasets: [{
                        data: sensorData.map(d => ({x: new Date(d.t), y: d.y})),
                        borderColor: color,
                        backgroundColor: (c) => {
                            const chart = c.chart;
                            const {ctx, chartArea} = chart;
                            if (!chartArea) return null;
                            const gradient = ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                            gradient.addColorStop(0, 'rgba(255,255,255,0)');
                            gradient.addColorStop(1, color + '22');
                            return gradient;
                        },
                        fill: true,
                        borderWidth: 2,
                        tension: 0.4,
                        pointRadius: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { enabled: true },
                        annotation: {
                            annotations: annotations
                        }
                    },
                    scales: {
                        y: { display: false, beginAtZero: true },
                        x: { type: 'time', display: false }
                    }
                }
            });
        })();
    @endforeach
});
</script>
@endpush
@endsection
