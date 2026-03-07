@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-hdd-network me-2 text-primary"></i>Network Switches
        </h4>
        <small class="text-muted">
            All Meraki MS switches
            @if($lastSync)
                &bull; Last sync: <span class="font-monospace">{{ \Carbon\Carbon::parse($lastSync)->diffForHumans() }}</span>
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-network-settings')
        <a href="{{ route('admin.settings.locations') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-building me-1"></i>Manage Locations
        </a>
        <form method="POST" action="{{ route('admin.network.sync') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Now
            </button>
        </form>
        @endcan
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
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.switches') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($switches->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-hdd-network display-4 mb-3 d-block"></i>
            No switches found. Run a sync or check your Meraki settings.
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
                        <th class="text-center">Ports</th>
                        <th class="text-center">Clients</th>
                        <th>Last Seen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($switches as $sw)
                    <tr>
                        <td>
                            <span class="badge {{ $sw->statusBadgeClass() }}">
                                <i class="bi bi-circle-fill me-1" style="font-size:8px"></i>{{ ucfirst($sw->status) }}
                            </span>
                        </td>
                        <td class="fw-semibold">{{ $sw->name ?: $sw->serial }}</td>
                        <td><span class="badge bg-secondary">{{ $sw->model }}</span></td>
                        <td class="font-monospace small text-muted">{{ $sw->serial }}</td>
                        <td class="font-monospace small">{{ $sw->lan_ip ?: '-' }}</td>
                        <td class="small text-muted">{{ $sw->network_name ?: $sw->network_id ?: '-' }}</td>

                        {{-- Location breadcrumb --}}
                        <td class="small">
                            @if($sw->branch || $sw->floor || $sw->rack)
                                <span class="text-muted">{{ $sw->locationBreadcrumb() }}</span>
                            @else
                                @can('manage-network-settings')
                                <button class="btn btn-link btn-sm p-0 text-decoration-none text-muted"
                                        onclick="openAssignModal('{{ $sw->serial }}', '{{ addslashes($sw->name ?? $sw->serial) }}', {{ $sw->branch_id ?? 'null' }}, {{ $sw->floor_id ?? 'null' }}, {{ $sw->rack_id ?? 'null' }})"
                                        title="Assign location">
                                    <i class="bi bi-geo-alt text-secondary"></i> <span class="small">Assign</span>
                                </button>
                                @else
                                <span class="text-muted">—</span>
                                @endcan
                            @endif
                            @can('manage-network-settings')
                            @if($sw->branch || $sw->floor || $sw->rack)
                            <button class="btn btn-link btn-sm p-0 ms-1 text-secondary"
                                    onclick="openAssignModal('{{ $sw->serial }}', '{{ addslashes($sw->name ?? $sw->serial) }}', {{ $sw->branch_id ?? 'null' }}, {{ $sw->floor_id ?? 'null' }}, {{ $sw->rack_id ?? 'null' }})"
                                    title="Change location">
                                <i class="bi bi-pencil" style="font-size:11px"></i>
                            </button>
                            @endif
                            @endcan
                        </td>

                        <td class="text-center"><span class="badge bg-secondary">{{ $sw->port_count }}</span></td>
                        <td class="text-center"><span class="badge bg-info text-dark">{{ $sw->clients_count }}</span></td>
                        <td class="small text-muted">
                            {{ $sw->last_reported_at ? $sw->last_reported_at->diffForHumans() : '-' }}
                        </td>
                        <td>
                            <a href="{{ route('admin.network.switch-detail', $sw->serial) }}"
                               class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-ethernet"></i>
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
