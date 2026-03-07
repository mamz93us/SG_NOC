@extends('layouts.admin')

@section('content')
<style>
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

<div class="mb-4">
    <a href="{{ route('admin.network.monitoring.show', $host) }}" class="btn btn-link link-secondary ps-0">
        <i class="bi bi-arrow-left me-1"></i> Back to {{ $host->name }}
    </a>
    <h2 class="h3 mt-2 mb-0 fw-bold">{{ $host->name }} &mdash; Settings</h2>
    <p class="text-muted small mt-1">Manage MIB assignments, sensors, and discovery for this device.</p>
</div>

<div class="row g-4">
    {{-- LEFT COLUMN: MIB & Discovery --}}
    <div class="col-lg-5">
        {{-- MIB Assignment --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-dark text-white py-3">
                <h6 class="card-title mb-0 fw-bold"><i class="bi bi-file-earmark-code me-2"></i> MIB Assignment</h6>
            </div>
            <div class="card-body">
                @if($host->mib)
                    <div class="d-flex align-items-center mb-3">
                        <i class="bi bi-file-earmark-code fs-3 text-primary me-3"></i>
                        <div>
                            <div class="fw-bold">{{ $host->mib->name }}</div>
                            <div class="text-muted small">{{ basename($host->mib->file_path) }}</div>
                        </div>
                    </div>
                    <a href="{{ route('admin.network.monitoring.mibs.view', $host->mib) }}" class="btn btn-outline-info btn-sm w-100 mb-2">
                        <i class="bi bi-eye me-1"></i> Preview MIB OIDs
                    </a>
                @else
                    <div class="text-center py-3 text-muted">
                        <i class="bi bi-file-earmark-x fs-2 opacity-25 d-block mb-2"></i>
                        No custom MIB linked to this device.
                    </div>
                @endif

                <form action="{{ route('admin.network.monitoring.hosts.mib-assign', $host) }}" method="POST" class="mt-3">
                    @csrf
                    <label class="form-label fw-bold small">Assign / Change MIB</label>
                    <div class="input-group">
                        <select name="mib_id" class="form-select form-select-sm">
                            <option value="">-- No MIB --</option>
                            @foreach($mibs as $mib)
                                <option value="{{ $mib->id }}" {{ $host->mib_id == $mib->id ? 'selected' : '' }}>
                                    {{ $mib->name }} ({{ basename($mib->file_path) }})
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- Discovery Actions --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h6 class="card-title mb-0 fw-bold text-muted text-uppercase small">Auto-Discovery</h6>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Automatically detect system sensors and network interfaces on this device via SNMP.</p>
                <div class="d-flex gap-2">
                    <form action="{{ route('admin.network.monitoring.hosts.discover-device', $host) }}" method="POST" class="flex-fill">
                        @csrf
                        <button type="submit" class="btn btn-outline-info w-100">
                            <i class="bi bi-search me-1"></i> Discover Device
                        </button>
                    </form>
                    <form action="{{ route('admin.network.monitoring.hosts.discover-interfaces', $host) }}" method="POST" class="flex-fill">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-hdd-network me-1"></i> Discover Interfaces
                        </button>
                    </form>
                </div>
            </div>
        </div>

        {{-- MIB Object Explorer --}}
        @if(!empty($discoveredObjects))
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h6 class="card-title mb-0 fw-bold text-muted text-uppercase small">MIB Object Explorer</h6>
                <span class="badge bg-info-subtle text-info">{{ count($discoveredObjects) }} Objects</span>
            </div>
            <div class="card-body pt-0">
                <div class="alert alert-warning py-1 small mb-2 border-0" style="background:rgba(255,193,7,0.1)">
                    <i class="bi bi-info-circle me-1"></i> Select objects to add them as monitored sensors.
                </div>
                <div class="overflow-auto border rounded bg-white" style="max-height: 400px;">
                    <form action="{{ route('admin.network.monitoring.hosts.mib-sensors.store', $host) }}" method="POST" id="mibSensorsForm">
                        @csrf
                        <table class="table table-sm table-hover mb-0 small">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th width="30"></th>
                                    <th>Object Name</th>
                                    <th>ID</th>
                                    <th width="80">Type</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($discoveredObjects as $index => $obj)
                                <tr>
                                    <td>
                                        <input type="checkbox" name="sensors[{{ $index }}][enabled]" value="1" class="form-check-input ms-1" {{ (str_contains($obj['name'], 'Entry') || str_contains($obj['name'], 'Table')) ? 'disabled' : '' }}>
                                        <input type="hidden" name="sensors[{{ $index }}][oid]" value="{{ $obj['oid_suffix'] }}">
                                        <input type="hidden" name="sensors[{{ $index }}][name]" value="{{ $obj['name'] }}">
                                        <input type="hidden" name="sensors[{{ $index }}][unit]" value="{{ $obj['units'] }}">
                                    </td>
                                    <td>
                                        <div class="fw-bold text-dark">{{ $obj['name'] }}</div>
                                        @if($obj['units']) <span class="badge bg-secondary-subtle text-secondary x-small">{{ $obj['units'] }}</span> @endif
                                    </td>
                                    <td class="text-muted font-monospace small">{{ $obj['oid_suffix'] }}</td>
                                    <td>
                                        @php
                                            $syntax = strtolower($obj['syntax'] ?? '');
                                            $defaultType = 'gauge';
                                            if (str_contains($syntax, 'counter')) $defaultType = 'counter';
                                            elseif (str_contains($syntax, 'timeticks')) $defaultType = 'uptime';
                                            elseif (str_contains($syntax, 'truthvalue')) $defaultType = 'boolean';
                                        @endphp
                                        <select name="sensors[{{ $index }}][data_type]" class="form-select form-select-sm py-0 x-small border-{{ $obj['syntax'] ? 'info-subtle' : 'light' }}" style="height:22px">
                                            <option value="gauge" {{ $defaultType == 'gauge' ? 'selected' : '' }}>Gauge</option>
                                            <option value="counter" {{ $defaultType == 'counter' ? 'selected' : '' }}>Counter</option>
                                            <option value="uptime" {{ $defaultType == 'uptime' ? 'selected' : '' }}>Uptime</option>
                                            <option value="boolean" {{ $defaultType == 'boolean' ? 'selected' : '' }}>Boolean</option>
                                        </select>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </form>
                </div>
                <button type="submit" form="mibSensorsForm" class="btn btn-primary btn-sm w-100 mt-2 shadow-sm">
                    <i class="bi bi-plus-circle me-1"></i> Add Selected Sensors
                </button>
            </div>
        </div>
        @endif
    </div>

    {{-- RIGHT COLUMN: Sensors --}}
    <div class="col-lg-7">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="card-title mb-0 fw-bold">SNMP Sensors</h6>
                    <span class="text-muted small">{{ $host->snmpSensors->count() }} total sensors</span>
                </div>
                <button class="btn btn-primary btn-sm px-3" data-bs-toggle="modal" data-bs-target="#addSensorModal">
                    <i class="bi bi-plus-lg me-1"></i> Add Sensor
                </button>
            </div>
            <div class="card-body p-0">
                @if($host->snmpSensors->isEmpty())
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-cpu fs-1 opacity-25 d-block mb-2"></i>
                        <p>No sensors configured. Use "Discover Device" or add sensors manually.</p>
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle small">
                            <thead class="table-light">
                                <tr>
                                    <th>Sensor</th>
                                    <th>OID</th>
                                    <th>Type</th>
                                    <th>Group</th>
                                    <th>Status</th>
                                    <th width="40"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($host->snmpSensors as $sensor)
                                <tr>
                                    <td>
                                        <span class="sensor-status-dot sensor-status-{{ $sensor->status ?? 'active' }}"></span>
                                        <span class="fw-semibold">{{ $sensor->name ?: 'Unnamed' }}</span>
                                        @if($sensor->unit)
                                            <span class="text-muted">({{ $sensor->unit }})</span>
                                        @endif
                                    </td>
                                    <td><code class="text-muted small">{{ $sensor->oid }}</code></td>
                                    <td><span class="badge bg-light text-dark border">{{ $sensor->data_type }}</span></td>
                                    <td class="text-muted">{{ $sensor->sensor_group ?? '-' }}</td>
                                    <td>
                                        @php
                                            $sColors = ['active' => 'success', 'unreachable' => 'warning', 'error' => 'danger'];
                                            $sc = $sColors[$sensor->status ?? 'active'] ?? 'secondary';
                                        @endphp
                                        <span class="badge bg-{{ $sc }}-subtle text-{{ $sc }}">{{ ucfirst($sensor->status ?? 'active') }}</span>
                                    </td>
                                    <td>
                                        <form action="{{ route('admin.network.monitoring.hosts.sensors.destroy', [$host, $sensor]) }}" method="POST" class="d-inline" onsubmit="return confirm('Remove this sensor?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-link text-danger p-0"><i class="bi bi-trash-fill"></i></button>
                                        </form>
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

{{-- Add Sensor Modal --}}
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
@endsection
