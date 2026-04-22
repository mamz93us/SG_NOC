@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-hdd-network me-2 text-primary"></i>Network Switches
        </h4>
        <small class="text-muted">
            Unified view — Meraki, SNMP, QoS &amp; Assets
            @if($lastSync)
                &bull; Last Meraki sync: <span class="font-monospace">{{ \Carbon\Carbon::parse($lastSync)->diffForHumans() }}</span>
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-network-settings')
        <a href="{{ route('admin.settings.locations') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-building me-1"></i>Manage Locations
        </a>
        <form method="POST" action="{{ route('admin.network.switches.bulk-add-to-snmp') }}"
              onsubmit="return confirm('Create SNMP host stubs for every switch/router/firewall that doesn\'t have one yet?\n\nStubs start with polling disabled — you still need to supply credentials or run \'Sync SNMP from Configs\'.');">
            @csrf
            <button type="submit" class="btn btn-outline-warning btn-sm" title="Create a MonitoredHost stub for every switch-class device without one">
                <i class="bi bi-broadcast-pin me-1"></i>Add All to SNMP
            </button>
        </form>
        <form method="POST" action="{{ route('admin.network.switches.sync-snmp-from-configs') }}"
              onsubmit="return confirm('Parse every saved running-config and apply the SNMP community / v3 credentials to the matching SNMP host?\n\nThis will overwrite existing creds on MonitoredHost rows and enable polling where usable creds are found.');">
            @csrf
            <button type="submit" class="btn btn-outline-success btn-sm" title="Extract SNMP credentials from the latest running-config of each switch and push them onto its MonitoredHost">
                <i class="bi bi-magic me-1"></i>Sync SNMP from Configs
            </button>
        </form>
        <form method="POST" action="{{ route('admin.network.sync') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Meraki
            </button>
        </form>
        @endcan
    </div>
</div>

{{-- ── Source totals ── --}}
<div class="row g-2 mb-3">
    <div class="col-md col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="small text-muted">Total Switches</div>
                <div class="h5 mb-0">{{ $totals['all'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="small text-muted"><i class="bi bi-cloud text-primary me-1"></i>In Meraki</div>
                <div class="h5 mb-0">{{ $totals['meraki'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="small text-muted"><i class="bi bi-broadcast text-success me-1"></i>SNMP active</div>
                <div class="h5 mb-0">{{ $totals['snmp'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="small text-muted"><i class="bi bi-speedometer text-info me-1"></i>QoS polled</div>
                <div class="h5 mb-0">{{ $totals['qos'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md col-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="small text-muted"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Incomplete</div>
                <div class="h5 mb-0">{{ $totals['gaps'] }}</div>
            </div>
        </div>
    </div>
</div>


{{-- ── Filters ── --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="network" class="form-select form-select-sm">
            <option value="">All Networks</option>
            @foreach($networks as $net)
            <option value="{{ $net->network_id }}" {{ request('network') == $net->network_id ? 'selected' : '' }}>
                {{ $net->network_name ?: $net->network_id }}
            </option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="online"   {{ request('status') == 'online'   ? 'selected' : '' }}>Online</option>
            <option value="offline"  {{ request('status') == 'offline'  ? 'selected' : '' }}>Offline</option>
            <option value="alerting" {{ request('status') == 'alerting' ? 'selected' : '' }}>Alerting</option>
        </select>
    </div>
    <div class="col-auto">
        <select name="source" class="form-select form-select-sm">
            <option value="">All Sources</option>
            <option value="meraki" {{ request('source') == 'meraki' ? 'selected' : '' }}>In Meraki</option>
            <option value="snmp"   {{ request('source') == 'snmp'   ? 'selected' : '' }}>In SNMP (active)</option>
            <option value="qos"    {{ request('source') == 'qos'    ? 'selected' : '' }}>In QoS</option>
            <option value="manual" {{ request('source') == 'manual' ? 'selected' : '' }}>Manual only</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.switches') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($rows->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-hdd-network display-4 mb-3 d-block"></i>
            No switches found across any source. Run a Meraki sync or add an asset manually.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Model</th>
                        <th>Serial</th>
                        <th>IP</th>
                        <th>Network</th>
                        <th>Location</th>
                        <th class="text-center">Sources</th>
                        <th class="text-center">Ports</th>
                        <th class="text-center">Clients</th>
                        <th>Last Seen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $row)
                    @php
                        $statusBadge = match($row->status) {
                            'online'   => 'bg-success',
                            'offline'  => 'bg-danger',
                            'alerting' => 'bg-warning text-dark',
                            default    => 'bg-secondary',
                        };
                        $sw = $row->meraki_ref;
                        $snmp = $row->snmp_ref;
                    @endphp
                    <tr>
                        <td>
                            <span class="badge {{ $statusBadge }}">
                                <i class="bi bi-circle-fill me-1" style="font-size:8px"></i>{{ ucfirst($row->status) }}
                            </span>
                        </td>
                        <td class="fw-semibold">{{ $row->name ?: $row->serial }}</td>
                        <td>
                            @if($row->model)
                                <span class="badge bg-secondary">{{ $row->model }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="font-monospace small text-muted">{{ $row->serial ?: '—' }}</td>
                        <td class="font-monospace small">{{ $row->ip ?: '—' }}</td>
                        <td class="small text-muted">{{ $row->network_name ?: $row->network_id ?: '—' }}</td>

                        {{-- Location breadcrumb — only Meraki switches have a floor/rack assignment UI --}}
                        <td class="small">
                            @if($sw && ($sw->branch || $sw->floor || $sw->rack))
                                <span class="text-muted">{{ $sw->locationBreadcrumb() }}</span>
                                @can('manage-network-settings')
                                <button class="btn btn-link btn-sm p-0 ms-1 text-secondary"
                                        onclick="openAssignModal('{{ $sw->serial }}', '{{ addslashes($sw->name ?? $sw->serial) }}', {{ $sw->branch_id ?? 'null' }}, {{ $sw->floor_id ?? 'null' }}, {{ $sw->rack_id ?? 'null' }})"
                                        title="Change location">
                                    <i class="bi bi-pencil" style="font-size:11px"></i>
                                </button>
                                @endcan
                            @elseif($sw)
                                @can('manage-network-settings')
                                <button class="btn btn-link btn-sm p-0 text-decoration-none text-muted"
                                        onclick="openAssignModal('{{ $sw->serial }}', '{{ addslashes($sw->name ?? $sw->serial) }}', {{ $sw->branch_id ?? 'null' }}, {{ $sw->floor_id ?? 'null' }}, {{ $sw->rack_id ?? 'null' }})"
                                        title="Assign location">
                                    <i class="bi bi-geo-alt text-secondary"></i> <span class="small">Assign</span>
                                </button>
                                @else
                                <span class="text-muted">—</span>
                                @endcan
                            @elseif($row->branch)
                                <span class="text-muted">{{ $row->branch->name }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Source presence badges --}}
                        <td class="text-center small">
                            <span class="badge bg-dark me-1" title="Asset inventory">Assets</span>
                            @if($row->in_meraki)
                                <span class="badge bg-primary me-1" title="Synced from Meraki">Meraki</span>
                            @endif
                            @if($row->in_snmp)
                                @if($row->snmp_ready)
                                    <span class="badge bg-success me-1" title="SNMP polling enabled">SNMP</span>
                                @else
                                    <span class="badge bg-warning text-dark me-1" title="SNMP host registered but polling disabled — configure credentials">SNMP?</span>
                                @endif
                            @endif
                            @if($row->in_qos)
                                <span class="badge bg-info text-dark" title="QoS polled">QoS</span>
                            @endif
                        </td>

                        <td class="text-center"><span class="badge bg-secondary">{{ $row->port_count }}</span></td>
                        <td class="text-center"><span class="badge bg-info text-dark">{{ $row->clients }}</span></td>
                        <td class="small text-muted">
                            {{ $row->last_seen ? \Carbon\Carbon::parse($row->last_seen)->diffForHumans() : '—' }}
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                @if($sw)
                                <a href="{{ route('admin.network.switch-detail', $sw->serial) }}"
                                   class="btn btn-outline-primary" title="Meraki ports &amp; clients">
                                    <i class="bi bi-ethernet"></i>
                                </a>
                                @endif
                                @if($row->in_qos)
                                <a href="{{ route('admin.switch-qos.dashboard') }}?device_id={{ $row->id }}"
                                   class="btn btn-outline-info" title="QoS dashboard">
                                    <i class="bi bi-speedometer"></i>
                                </a>
                                @endif
                                @if($snmp)
                                <a href="{{ route('admin.network.monitoring.show', $snmp->id) }}"
                                   class="btn btn-outline-success" title="SNMP host">
                                    <i class="bi bi-broadcast"></i>
                                </a>
                                @else
                                    @can('manage-network-settings')
                                    @if($row->ip)
                                    <form method="POST" action="{{ route('admin.network.switches.add-to-snmp', $row->id) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-warning" title="Add this switch to SNMP monitoring">
                                            <i class="bi bi-broadcast-pin"></i>
                                        </button>
                                    </form>
                                    @endif
                                    @endcan
                                @endif
                                <a href="{{ route('admin.devices.show', $row->id) }}"
                                   class="btn btn-outline-secondary" title="Asset record">
                                    <i class="bi bi-box"></i>
                                </a>
                                @can('manage-network-settings')
                                @php
                                    $qeData = [
                                        'id'            => $row->id,
                                        'name'          => $row->name,
                                        'model'         => $row->model,
                                        'serial_number' => $row->serial,
                                        'mac_address'   => $row->mac,
                                        'ip_address'    => $row->ip,
                                        'network_id'    => $row->network_id,
                                        'branch_id'     => $row->device?->branch_id ?? ($sw?->branch_id),
                                        'floor_id'      => $row->device?->floor_id  ?? ($sw?->floor_id),
                                        'rack_id'       => $sw?->rack_id,
                                        'in_meraki'     => (bool) $sw,
                                    ];
                                @endphp
                                <button type="button"
                                        class="btn btn-outline-primary quick-edit-btn"
                                        title="Quick edit — name, model, serial, IP, MAC, network, location"
                                        data-row="{{ json_encode($qeData, JSON_UNESCAPED_SLASHES) }}">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @endcan
                                @can('manage-devices')
                                <a href="{{ route('admin.devices.edit', $row->id) }}"
                                   class="btn btn-outline-dark" title="Complete asset details (warranty, cost, location…)">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- QUICK-EDIT SWITCH MODAL                                         --}}
{{-- Writes canonical device row; NetworkSwitch + MonitoredHost      --}}
{{-- are synced server-side where they exist.                        --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
@can('manage-network-settings')
<div class="modal fade" id="quickEditSwitchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="quickEditSwitchForm" class="modal-content">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit Switch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Changes are written to the <strong>Assets</strong> record (canonical) and synced
                    to <strong>Meraki</strong> / <strong>SNMP</strong> views when a linked row exists.
                    Values may be overwritten on the next Meraki sync.
                </div>

                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="qe_name" class="form-control" required maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Model</label>
                        <input type="text" name="model" id="qe_model" class="form-control" maxlength="100"
                               placeholder="e.g. MS225-24P">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Serial Number</label>
                        <input type="text" name="serial_number" id="qe_serial" class="form-control font-monospace"
                               maxlength="100">
                    </div>
                    <div class="col-md-6" id="qe_networkWrap">
                        <label class="form-label fw-semibold">Network <span class="text-muted small">(Meraki only)</span></label>
                        <select name="network_id" id="qe_network" class="form-select">
                            <option value="">— None —</option>
                            @foreach($networks as $net)
                            <option value="{{ $net->network_id }}">
                                {{ $net->network_name ?: $net->network_id }}
                            </option>
                            @endforeach
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">IP Address</label>
                        <input type="text" name="ip_address" id="qe_ip" class="form-control font-monospace"
                               placeholder="192.168.1.1">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">MAC Address</label>
                        <input type="text" name="mac_address" id="qe_mac" class="form-control font-monospace"
                               maxlength="20" placeholder="AA:BB:CC:DD:EE:FF">
                    </div>
                </div>

                <hr>
                <p class="fw-semibold small text-muted mb-2"><i class="bi bi-geo-alt me-1"></i>Location</p>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Branch</label>
                        <select name="branch_id" id="qe_branch" class="form-select" onchange="qeUpdateFloorOptions()">
                            <option value="">— None —</option>
                            @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Floor</label>
                        <select name="floor_id" id="qe_floor" class="form-select" onchange="qeUpdateRackOptions()">
                            <option value="">— None —</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Rack <span class="text-muted small">(Meraki only)</span></label>
                        <select name="rack_id" id="qe_rack" class="form-select">
                            <option value="">— None —</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- ASSIGN LOCATION MODAL                                           --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
@can('manage-network-settings')
<div class="modal fade" id="assignLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="assignLocationForm" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>Assign Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Switch: <strong id="assignSwitchLabel"></strong>
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Branch</label>
                    <select name="branch_id" id="assignBranch" class="form-select" onchange="updateFloorOptions()">
                        <option value="">— None —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Floor</label>
                    <select name="floor_id" id="assignFloor" class="form-select" onchange="updateRackOptions()">
                        <option value="">— None —</option>
                        @foreach($floors as $f)
                        <option value="{{ $f->id }}" data-branch="{{ $f->branch_id }}">
                            {{ $f->branch?->name }} › {{ $f->name }}
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-1">
                    <label class="form-label fw-semibold">Rack</label>
                    <select name="rack_id" id="assignRack" class="form-select">
                        <option value="">— None —</option>
                        @foreach($racks as $r)
                        <option value="{{ $r->id }}" data-floor="{{ $r->floor_id }}">
                            {{ $r->floor?->branch?->name }} › {{ $r->floor?->name }} › {{ $r->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="form-text">Select as many or as few levels as you need.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Location</button>
            </div>
        </form>
    </div>
</div>
@endcan

@endsection

@push('scripts')
@can('manage-network-settings')
<script>
const allFloors = @json($floors->map(fn($f) => ['id' => $f->id, 'branch_id' => $f->branch_id, 'label' => ($f->branch?->name ?? '') . ' › ' . $f->name]));
const allRacks  = @json($racks->map(fn($r)  => ['id' => $r->id, 'floor_id'  => $r->floor_id,  'label' => ($r->floor?->branch?->name ?? '') . ' › ' . ($r->floor?->name ?? '') . ' › ' . $r->name]));

const baseAssignUrl = '{{ rtrim(url("admin/network/switches/__SERIAL__/assign-location"), "/") }}';
const baseQuickEditUrl = '{{ rtrim(url("admin/network/switches/__ID__/update"), "/") }}';

// ══════════════════════════════════════════════════════════════════════
// QUICK-EDIT SWITCH MODAL
// ══════════════════════════════════════════════════════════════════════
// Delegated click handler — each Edit button carries a data-row attribute
// with JSON-encoded values. We parse + pass into openQuickEdit().
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.quick-edit-btn');
    if (!btn) return;
    try {
        openQuickEdit(JSON.parse(btn.dataset.row));
    } catch (err) {
        console.error('Invalid quick-edit data:', err);
    }
});

function openQuickEdit(row) {
    const url = baseQuickEditUrl.replace('__ID__', row.id);
    const form = document.getElementById('quickEditSwitchForm');
    form.action = url;

    document.getElementById('qe_name').value   = row.name ?? '';
    document.getElementById('qe_model').value  = row.model ?? '';
    document.getElementById('qe_serial').value = row.serial_number ?? '';
    document.getElementById('qe_ip').value     = row.ip_address ?? '';
    document.getElementById('qe_mac').value    = row.mac_address ?? '';

    // Network dropdown — show only for Meraki-sourced switches; editing
    // it on a non-Meraki row has no canonical destination yet.
    const netWrap = document.getElementById('qe_networkWrap');
    const netSel  = document.getElementById('qe_network');
    if (row.in_meraki) {
        netWrap.style.display = '';
        netSel.value = row.network_id ?? '';
        netSel.disabled = false;
    } else {
        netWrap.style.display = 'none';
        netSel.value = '';
        netSel.disabled = true;
    }

    // Same caveat for rack — only Meraki rows have a rack assignment path.
    const rackSel = document.getElementById('qe_rack');
    rackSel.disabled = !row.in_meraki;

    document.getElementById('qe_branch').value = row.branch_id ?? '';
    qeUpdateFloorOptions(row.floor_id);
    qeUpdateRackOptions(row.rack_id);

    new bootstrap.Modal(document.getElementById('quickEditSwitchModal')).show();
}

function qeUpdateFloorOptions(selectedFloorId) {
    const branchId = parseInt(document.getElementById('qe_branch').value) || null;
    const floorSel = document.getElementById('qe_floor');
    const prevVal  = selectedFloorId !== undefined ? selectedFloorId : (parseInt(floorSel.value) || null);

    floorSel.innerHTML = '<option value="">— None —</option>';
    allFloors
        .filter(f => !branchId || f.branch_id === branchId)
        .forEach(f => {
            const opt = new Option(f.label, f.id);
            if (prevVal && f.id === prevVal) opt.selected = true;
            floorSel.appendChild(opt);
        });

    qeUpdateRackOptions(selectedFloorId !== undefined ? null : undefined);
}

function qeUpdateRackOptions(selectedRackId) {
    const floorId = parseInt(document.getElementById('qe_floor').value) || null;
    const rackSel = document.getElementById('qe_rack');
    const prevVal = selectedRackId !== undefined ? selectedRackId : (parseInt(rackSel.value) || null);

    rackSel.innerHTML = '<option value="">— None —</option>';
    allRacks
        .filter(r => !floorId || r.floor_id === floorId)
        .forEach(r => {
            const opt = new Option(r.label, r.id);
            if (prevVal && r.id === prevVal) opt.selected = true;
            rackSel.appendChild(opt);
        });
}


function openAssignModal(serial, name, branchId, floorId, rackId) {
    const url = baseAssignUrl.replace('__SERIAL__', serial);
    document.getElementById('assignLocationForm').action = url;
    document.getElementById('assignSwitchLabel').textContent = name;

    const bSel = document.getElementById('assignBranch');
    bSel.value = branchId || '';

    updateFloorOptions(floorId);
    updateRackOptions(rackId);

    new bootstrap.Modal(document.getElementById('assignLocationModal')).show();
}

function updateFloorOptions(selectedFloorId) {
    const branchId  = parseInt(document.getElementById('assignBranch').value) || null;
    const floorSel  = document.getElementById('assignFloor');
    const prevVal   = selectedFloorId !== undefined ? selectedFloorId : (parseInt(floorSel.value) || null);

    floorSel.innerHTML = '<option value="">— None —</option>';
    allFloors
        .filter(f => !branchId || f.branch_id === branchId)
        .forEach(f => {
            const opt = new Option(f.label, f.id);
            if (prevVal && f.id === prevVal) opt.selected = true;
            floorSel.appendChild(opt);
        });

    updateRackOptions(selectedFloorId !== undefined ? null : undefined);
}

function updateRackOptions(selectedRackId) {
    const floorId = parseInt(document.getElementById('assignFloor').value) || null;
    const rackSel = document.getElementById('assignRack');
    const prevVal = selectedRackId !== undefined ? selectedRackId : (parseInt(rackSel.value) || null);

    rackSel.innerHTML = '<option value="">— None —</option>';
    allRacks
        .filter(r => !floorId || r.floor_id === floorId)
        .forEach(r => {
            const opt = new Option(r.label, r.id);
            if (prevVal && r.id === prevVal) opt.selected = true;
            rackSel.appendChild(opt);
        });
}
</script>
@endcan
@endpush
