@extends('layouts.admin')

@section('content')

{{-- ── Header ── --}}
<div class="d-flex justify-content-between align-items-start mb-3">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <a href="{{ route('admin.network.switches') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <span class="badge {{ $switch->statusBadgeClass() }} fs-6">
                <i class="bi bi-circle-fill me-1" style="font-size:9px"></i>{{ ucfirst($switch->status) }}
            </span>
            <h4 class="mb-0 fw-bold">{{ $switch->name ?: $switch->serial }}</h4>
            <span class="badge bg-secondary">{{ $switch->model }}</span>
        </div>
        <small class="text-muted">
            Serial: <code>{{ $switch->serial }}</code>
            @if($switch->lan_ip) &bull; IP: <code>{{ $switch->lan_ip }}</code>@endif
            @if($switch->mac) &bull; MAC: <code>{{ $switch->mac }}</code>@endif
            @if($switch->firmware) &bull; Firmware: <code>{{ $switch->firmware }}</code>@endif
            @if($switch->last_reported_at) &bull; Last seen: {{ $switch->last_reported_at->diffForHumans() }}@endif
        </small>
        {{-- Location breadcrumb --}}
        <div class="mt-1 small">
            <i class="bi bi-geo-alt text-secondary me-1"></i>
            <span class="text-muted">{{ $switch->locationBreadcrumb() }}</span>
            @can('manage-network-settings')
            <button class="btn btn-link btn-sm p-0 ms-1 text-secondary"
                    data-bs-toggle="modal" data-bs-target="#assignLocationModal"
                    title="Assign / change location">
                <i class="bi bi-pencil" style="font-size:11px"></i>
            </button>
            @endcan
        </div>
    </div>
    {{-- Session flash --}}
    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-1 px-3 small mb-0" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
    </div>
    @endif
</div>

{{-- ── Port Legend ── --}}
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center small">
    <span class="fw-semibold text-muted me-1">Legend:</span>
    <span class="d-inline-flex align-items-center gap-1">
        <span style="width:18px;height:18px;border-radius:3px;background:#198754;display:inline-block;"></span> Connected
    </span>
    <span class="d-inline-flex align-items-center gap-1">
        <span style="width:18px;height:18px;border-radius:3px;background:#6c757d;opacity:.5;display:inline-block;"></span> Disconnected
    </span>
    <span class="d-inline-flex align-items-center gap-1">
        <span style="width:18px;height:18px;border-radius:3px;background:#dee2e6;display:inline-block;border:1px solid #adb5bd"></span> Disabled
    </span>
    <span class="d-inline-flex align-items-center gap-1 ms-2 text-muted">
        <i class="bi bi-arrow-up-circle-fill text-warning"></i> = Manual uplink port
        @can('manage-network-settings')<small class="text-muted">(click ↑ on tile to toggle)</small>@endcan
    </span>
</div>

{{-- ── Port Grid Visualisation ── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex align-items-center justify-content-between py-2">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-ethernet me-2"></i>Port Panel</h6>
        <small class="text-muted">
            {{ $ports->where('status', 'Connected')->count() }} connected /
            {{ $ports->count() }} total
        </small>
    </div>
    <div class="card-body py-3">
        @if($ports->isEmpty())
        <div class="text-center text-muted py-3">
            <i class="bi bi-ethernet display-4 d-block mb-2"></i>No port data. Run a sync to fetch port information.
        </div>
        @else
        {{-- Switch body mockup: two rows of ports (top & bottom alternating) --}}
        <div class="d-flex flex-wrap gap-1 justify-content-start" id="portGrid">
            @foreach($ports as $port)
            @php
                $tileBg    = $port->tileBgClass();
                $textColor = str_contains($tileBg, 'bg-success') ? 'text-white' : 'text-dark';
                $tooltip   = $port->label();
                if ($port->client_mac) $tooltip .= ' | ' . $port->client_mac;
                if ($port->speed) $tooltip .= ' | ' . $port->speedLabel();
                if ($port->vlan) $tooltip .= ' | VLAN ' . $port->vlan;
            @endphp
            @php $isManualUplink = $switch->isManualUplink($port->port_id); @endphp
            <div class="port-tile {{ $tileBg }} {{ $textColor }} rounded position-relative"
                 style="width:42px;height:48px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;font-size:10px;font-weight:600;border:1px solid rgba(0,0,0,.1);"
                 data-bs-toggle="tooltip" data-bs-placement="top" title="{{ $tooltip }}"
                 data-port-id="{{ $port->port_id }}"
                 data-manual-uplink="{{ $isManualUplink ? '1' : '0' }}"
                 onclick="showPortDetail({{ $port->id }})">
                @can('manage-network-settings')
                <span class="position-absolute"
                      style="top:1px;right:2px;line-height:1;cursor:pointer;z-index:5;"
                      onclick="event.stopPropagation(); toggleUplink('{{ $port->port_id }}', this)"
                      title="{{ $isManualUplink ? 'Remove uplink' : 'Mark as uplink' }}">
                    <i class="bi {{ $isManualUplink ? 'bi-arrow-up-circle-fill text-warning' : 'bi-arrow-up-circle opacity-25' }}" style="font-size:9px"></i>
                </span>
                @endcan
                @if($isManualUplink)
                <i class="bi bi-arrow-up-circle port-main-icon" style="font-size:14px"></i>
                @else
                <i class="bi bi-ethernet port-main-icon" style="font-size:14px;opacity:.8"></i>
                @endif
                <div style="font-size:9px;line-height:1;margin-top:2px">{{ $port->port_id }}</div>
            </div>
            @endforeach
        </div>

        {{-- Port detail panel (shown on click) --}}
        <div id="portDetailPanel" class="mt-3 collapse">
            <div class="card border-primary">
                <div class="card-body py-2 small" id="portDetailContent">
                    <span class="text-muted">Click a port to see details.</span>
                </div>
            </div>
        </div>

        {{-- Serialised port data for JS ── --}}
        <script id="portData" type="application/json">
        {!! json_encode($ports->map(fn($p) => [
            'id'              => $p->id,
            'port_id'         => $p->port_id,
            'name'            => $p->name,
            'enabled'         => $p->enabled,
            'type'            => $p->type,
            'vlan'            => $p->vlan,
            'allowed_vlans'   => $p->allowed_vlans,
            'poe_enabled'     => $p->poe_enabled,
            'is_uplink'       => $p->is_uplink,
            'manual_uplink'   => $switch->isManualUplink($p->port_id),
            'status'          => $p->status,
            'speed'           => $p->speedLabel(),
            'duplex'          => $p->duplex,
            'client_mac'      => $p->client_mac,
            'client_hostname' => $p->client_hostname,
        ])->values()) !!}
        </script>
        @endif
    </div>
</div>

{{-- ── Summary stats ── --}}
<div class="row g-3 mb-4">
    @php
        $connected    = $ports->where('status', 'Connected')->count();
        $disconnected = $ports->where('status', 'Disconnected')->count();
        $uplinkIds    = array_map('strval', $switch->uplink_port_ids ?? []);
        $uplinks      = $ports->filter(fn($p) => in_array((string)$p->port_id, $uplinkIds))->count();
        $poe          = $ports->where('poe_enabled', true)->count();
    @endphp
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-success mb-0">{{ $connected }}</div>
                <div class="small text-muted">Connected</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-secondary mb-0">{{ $disconnected }}</div>
                <div class="small text-muted">Disconnected</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-primary mb-0 uplink-count">{{ $uplinks }}</div>
                <div class="small text-muted">Manual Uplinks</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-2">
                <div class="h3 fw-bold text-warning mb-0">{{ $poe }}</div>
                <div class="small text-muted">PoE Ports</div>
            </div>
        </div>
    </div>
</div>

{{-- ── Port detail table ── --}}
@if($ports->isNotEmpty())
<div class="card shadow-sm mb-4">
    <div class="card-header py-2">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-table me-2"></i>Port Details</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:400px;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Port</th>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Speed</th>
                        <th>Type</th>
                        <th>VLAN</th>
                        <th>PoE</th>
                        <th>Client MAC</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ports as $port)
                    <tr>
                        <td class="font-monospace fw-semibold">
                            @if($switch->isManualUplink($port->port_id))<i class="bi bi-arrow-up-circle-fill text-warning me-1" title="Manual uplink"></i>@endif
                            {{ $port->port_id }}
                        </td>
                        <td>{{ $port->name ?: '-' }}</td>
                        <td>
                            <span class="badge {{ $port->status === 'Connected' ? 'bg-success' : ($port->enabled ? 'bg-secondary' : 'bg-secondary bg-opacity-50') }} small">
                                {{ $port->status ?: ($port->enabled ? 'Unknown' : 'Disabled') }}
                            </span>
                        </td>
                        <td class="font-monospace small">{{ $port->isConnected() ? $port->speedLabel() : '-' }}</td>
                        <td class="text-muted small">{{ $port->type ?: '-' }}</td>
                        <td>
                            @if($port->vlan)
                            <span class="badge bg-info text-dark small">{{ $port->vlan }}</span>
                            @else
                            <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            @if($port->poe_enabled)
                            <i class="bi bi-lightning-fill text-warning" title="PoE enabled"></i>
                            @else
                            <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="font-monospace text-muted small">{{ $port->client_mac ?: '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- ── Connected Clients ── --}}
@if($clients->isNotEmpty())
<div class="card shadow-sm">
    <div class="card-header py-2 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-laptop me-2"></i>Connected Clients (last 24 h)</h6>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge bg-success small">{{ $clients->where('status', 'Online')->count() }} online</span>
            <span class="badge bg-secondary">{{ $clients->count() }} total</span>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive" style="max-height:350px;overflow-y:auto">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light sticky-top">
                    <tr>
                        <th>Status</th>
                        <th>Hostname / Description</th>
                        <th>IP</th>
                        <th>MAC</th>
                        <th>Manufacturer</th>
                        <th>VLAN</th>
                        <th>Port</th>
                        <th class="text-end">Usage</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($clients as $client)
                    <tr>
                        {{-- Status: statusLabel() returns "Online"/"Offline"/"Unknown" --}}
                        <td>
                            <span class="badge {{ $client->statusBadgeClass() }} small">
                                {{ $client->statusLabel() }}
                            </span>
                        </td>

                        {{-- Hostname + description --}}
                        <td>
                            <span class="fw-semibold">{{ $client->hostname ?: '-' }}</span>
                            @if($client->description && $client->description !== $client->hostname)
                                <br><span class="text-muted" style="font-size:10px">{{ $client->description }}</span>
                            @endif
                        </td>

                        <td class="font-monospace">{{ $client->ip ?: '-' }}</td>
                        <td class="font-monospace text-muted small">{{ $client->mac }}</td>
                        <td class="text-muted">{{ $client->manufacturer ?: '-' }}</td>

                        <td>
                            @if($client->vlan)
                                <span class="badge bg-info text-dark small">{{ $client->vlan }}</span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>

                        {{-- Port — clickable if we have a port_id --}}
                        <td class="font-monospace small">
                            @if($client->port_id)
                                <button class="btn btn-link btn-sm p-0 font-monospace small text-decoration-none"
                                        onclick="showPortDetail({{ $ports->firstWhere('port_id', $client->port_id)?->id ?? 'null' }})"
                                        title="Show port {{ $client->port_id }} details">
                                    <i class="bi bi-ethernet me-1 text-primary"></i>{{ $client->port_id }}
                                </button>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>

                        <td class="font-monospace small text-end">{{ $client->usageLabel() }}</td>
                        <td class="text-muted small">{{ $client->last_seen ? $client->last_seen->diffForHumans() : '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- ASSIGN LOCATION MODAL                                           --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
@can('manage-network-settings')
<div class="modal fade" id="assignLocationModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST"
              action="{{ route('admin.network.switches.assign-location', $switch->serial) }}"
              class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-geo-alt me-2"></i>Assign Location</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Switch: <strong>{{ $switch->name ?: $switch->serial }}</strong>
                </p>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Branch</label>
                    <select name="branch_id" id="detailAssignBranch" class="form-select"
                            onchange="detailUpdateFloors()">
                        <option value="">— None —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ $switch->branch_id == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Floor</label>
                    <select name="floor_id" id="detailAssignFloor" class="form-select"
                            onchange="detailUpdateRacks()">
                        <option value="">— None —</option>
                        @foreach($floors as $f)
                        <option value="{{ $f->id }}"
                                data-branch="{{ $f->branch_id }}"
                                {{ $switch->floor_id == $f->id ? 'selected' : '' }}>
                            {{ $f->branch?->name }} › {{ $f->name }}
                        </option>
                        @endforeach
                    </select>
                </div>

                <div class="mb-1">
                    <label class="form-label fw-semibold">Rack</label>
                    <select name="rack_id" id="detailAssignRack" class="form-select">
                        <option value="">— None —</option>
                        @foreach($racks as $r)
                        <option value="{{ $r->id }}"
                                data-floor="{{ $r->floor_id }}"
                                {{ $switch->rack_id == $r->id ? 'selected' : '' }}>
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
<script>
// Initialise Bootstrap tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
});

// Port data from server
const portData = JSON.parse(document.getElementById('portData')?.textContent || '[]');

function showPortDetail(portId) {
    const port = portData.find(p => p.id === portId);
    if (!port) return;

    const panel   = document.getElementById('portDetailPanel');
    const content = document.getElementById('portDetailContent');

    const status = port.status || (port.enabled ? 'Unknown' : 'Disabled');
    const vlans  = port.allowed_vlans || (port.vlan ? String(port.vlan) : '—');

    content.innerHTML = `
        <div class="row g-2">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted ps-0" style="width:40%">Port</th><td class="font-monospace fw-bold">${port.port_id}</td></tr>
                    <tr><th class="text-muted ps-0">Name</th><td>${port.name || '—'}</td></tr>
                    <tr><th class="text-muted ps-0">Status</th><td><span class="badge ${status === 'Connected' ? 'bg-success' : 'bg-secondary'}">${status}</span></td></tr>
                    <tr><th class="text-muted ps-0">Speed</th><td class="font-monospace">${port.status === 'Connected' ? port.speed : '—'}</td></tr>
                    <tr><th class="text-muted ps-0">Duplex</th><td>${port.duplex || '—'}</td></tr>
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0 small">
                    <tr><th class="text-muted ps-0" style="width:40%">Type</th><td>${port.type || '—'}</td></tr>
                    <tr><th class="text-muted ps-0">VLAN</th><td class="font-monospace">${port.vlan || '—'}</td></tr>
                    <tr><th class="text-muted ps-0">Allowed VLANs</th><td class="font-monospace small">${vlans}</td></tr>
                    <tr><th class="text-muted ps-0">PoE</th><td>${port.poe_enabled ? '<i class="bi bi-lightning-fill text-warning"></i> Enabled' : '—'}</td></tr>
                    <tr><th class="text-muted ps-0">Uplink</th><td>${port.manual_uplink ? '<i class="bi bi-arrow-up-circle-fill text-warning"></i> Manual uplink' : (port.is_uplink ? '<span class="text-muted small">Meraki flag only</span>' : '—')}</td></tr>
                </table>
            </div>
            ${port.client_mac ? `
            <div class="col-12 mt-1">
                <div class="alert alert-success py-1 small mb-0">
                    <i class="bi bi-laptop me-1"></i>
                    Client: <code>${port.client_mac}</code>
                    ${port.client_hostname ? '&nbsp;&bull;&nbsp;' + port.client_hostname : ''}
                </div>
            </div>` : ''}
        </div>
    `;

    if (!panel.classList.contains('show')) {
        new bootstrap.Collapse(panel, { show: true });
    }

    // Highlight selected port tile
    document.querySelectorAll('.port-tile').forEach(el => el.style.outline = '');
    const tile = document.querySelector(`.port-tile[data-port-id="${port.port_id}"]`);
    if (tile) tile.style.outline = '3px solid #0d6efd';
}

// ── Uplink port toggle (AJAX) ────────────────────────────────────
@can('manage-network-settings')
const uplinkPatchUrl = '{{ route("admin.network.switches.uplink-ports", $switch->serial) }}';

function toggleUplink(portId, btn) {
    const tile     = btn.closest('.port-tile');
    const isUplink = tile.getAttribute('data-manual-uplink') === '1';
    const newState = !isUplink;

    fetch(uplinkPatchUrl, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
        },
        body: JSON.stringify({ port_id: portId, checked: newState }),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;

        // Update tile state
        tile.setAttribute('data-manual-uplink', newState ? '1' : '0');

        // Update toggle icon
        const icon = btn.querySelector('i');
        icon.className = newState
            ? 'bi bi-arrow-up-circle-fill text-warning'
            : 'bi bi-arrow-up-circle opacity-25';
        btn.title = newState ? 'Remove uplink' : 'Mark as uplink';

        // Update main port icon inside tile
        const mainIcon = tile.querySelector('.port-main-icon');
        if (mainIcon) {
            mainIcon.className = newState
                ? 'bi bi-arrow-up-circle port-main-icon'
                : 'bi bi-ethernet port-main-icon';
            mainIcon.style.opacity = newState ? '1' : '.8';
        }

        // Update uplink count stat card
        const countEl = document.querySelector('.uplink-count');
        if (countEl) {
            const count = document.querySelectorAll('.port-tile[data-manual-uplink="1"]').length;
            countEl.textContent = count;
        }

        // Also update portData for the detail panel
        const pd = portData.find(p => p.port_id === portId);
        if (pd) pd.manual_uplink = newState;
    })
    .catch(err => console.error('Uplink toggle failed:', err));
}
@endcan

// ── Location modal cascading dropdowns ──────────────────────────
@can('manage-network-settings')
const detailAllFloors = @json($floors->map(fn($f) => ['id' => $f->id, 'branch_id' => $f->branch_id]));
const detailAllRacks  = @json($racks->map(fn($r)  => ['id' => $r->id, 'floor_id'  => $r->floor_id]));

function detailUpdateFloors() {
    const branchId = parseInt(document.getElementById('detailAssignBranch').value) || null;
    const floorSel = document.getElementById('detailAssignFloor');
    // Show only floors for selected branch (or all if none selected)
    Array.from(floorSel.options).forEach(opt => {
        if (!opt.value) return; // keep "None"
        const floorBranch = parseInt(opt.getAttribute('data-branch')) || null;
        opt.hidden = branchId ? (floorBranch !== branchId) : false;
        if (opt.hidden && opt.selected) { opt.selected = false; floorSel.value = ''; }
    });
    detailUpdateRacks();
}

function detailUpdateRacks() {
    const floorId = parseInt(document.getElementById('detailAssignFloor').value) || null;
    const rackSel = document.getElementById('detailAssignRack');
    Array.from(rackSel.options).forEach(opt => {
        if (!opt.value) return;
        const rackFloor = parseInt(opt.getAttribute('data-floor')) || null;
        opt.hidden = floorId ? (rackFloor !== floorId) : false;
        if (opt.hidden && opt.selected) { opt.selected = false; rackSel.value = ''; }
    });
}

// Run on load to filter dropdowns to current switch location
detailUpdateFloors();
@endcan
</script>
@endpush
