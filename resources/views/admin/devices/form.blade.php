@extends('layouts.admin')
@section('content')

@php $editing = isset($device); @endphp

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-cpu me-2 text-primary"></i>{{ $editing ? 'Edit Device' : 'Add Device' }}
    </h4>
</div>

<div class="card shadow-sm" style="max-width:720px">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('admin.devices.update', $device) : route('admin.devices.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif

            <div class="row g-3">

                {{-- ── Type + Status ── --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select name="type" class="form-select @error('type') is-invalid @enderror" required>
                        <optgroup label="Infrastructure">
                        @foreach(['ucm','switch','router','firewall','ap','printer','server','other'] as $t)
                        <option value="{{ $t }}" {{ old('type', $device->type ?? '') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                        </optgroup>
                        <optgroup label="User Equipment">
                        @foreach(['laptop','desktop','monitor','keyboard','mouse','headset','tablet'] as $t)
                        <option value="{{ $t }}" {{ old('type', $device->type ?? '') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                        </optgroup>
                    </select>
                    @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                        <option value="active"      {{ old('status', $device->status ?? 'active') == 'active'      ? 'selected' : '' }}>Active</option>
                        <option value="available"   {{ old('status', $device->status ?? '') == 'available'   ? 'selected' : '' }}>Available (ready to assign)</option>
                        <option value="assigned"    {{ old('status', $device->status ?? '') == 'assigned'    ? 'selected' : '' }}>Assigned</option>
                        <option value="maintenance" {{ old('status', $device->status ?? '') == 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                        <option value="retired"     {{ old('status', $device->status ?? '') == 'retired'     ? 'selected' : '' }}>Retired</option>
                    </select>
                    @error('status')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- ── Name ── --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $device->name ?? '') }}" required maxlength="255">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- ── Model + Serial ── --}}
                <div class="col-md-6">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control"
                           value="{{ old('model', $device->model ?? '') }}" maxlength="255">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control font-monospace"
                           value="{{ old('serial_number', $device->serial_number ?? '') }}" maxlength="100">
                </div>

                {{-- ── Network ── --}}
                <div class="col-md-6">
                    <label class="form-label">IP Address</label>
                    <input type="text" name="ip_address" id="dv_ip" class="form-control font-monospace"
                           value="{{ old('ip_address', $device->ip_address ?? '') }}" placeholder="192.168.1.1">
                    @error('ip_address')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">MAC Address</label>
                    <input type="text" name="mac_address" id="dv_mac" class="form-control font-monospace"
                           value="{{ old('mac_address', $device->mac_address ?? '') }}" maxlength="20"
                           placeholder="AA:BB:CC:DD:EE:FF"
                           pattern="([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}"
                           title="Format: AA:BB:CC:DD:EE:FF or AA-BB-CC-DD-EE-FF"
                           autocomplete="off"
                           list="dv_macList">
                    <datalist id="dv_macList"></datalist>
                    <div class="form-text">Type 3+ chars to search Meraki clients by MAC / IP / hostname.</div>
                </div>

                {{-- ── Location ── --}}
                <div class="col-12">
                    <hr class="my-0">
                    <p class="fw-semibold small text-muted mb-0 mt-2">
                        <i class="bi bi-geo-alt me-1"></i>Location
                    </p>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Branch</label>
                    <select name="branch_id" id="dv_branch" class="form-select"
                            data-current-floor="{{ old('floor_id', $device->floor_id ?? '') }}"
                            data-current-office="{{ old('office_id', $device->office_id ?? '') }}"
                            onchange="dvLoadFloors(this.value)">
                        <option value="">— None —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $device->branch_id ?? '') == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Floor</label>
                    <select name="floor_id" id="dv_floor" class="form-select" onchange="dvLoadOffices(this.value)">
                        <option value="">— None —</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Office / Room</label>
                    <select name="office_id" id="dv_office" class="form-select">
                        <option value="">— None —</option>
                    </select>
                </div>

                {{-- ── Department ── --}}
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <div class="input-group">
                        <select name="department_id" id="dv_dept" class="form-select">
                            <option value="">— None —</option>
                            @foreach($departments as $d)
                            <option value="{{ $d->id }}" {{ old('department_id', $device->department_id ?? '') == $d->id ? 'selected' : '' }}>
                                {{ $d->name }}
                            </option>
                            @endforeach
                        </select>
                        @can('manage-settings')
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#dvQuickAddDeptModal"
                                title="Add new department">
                            <i class="bi bi-plus"></i>
                        </button>
                        @endcan
                    </div>
                </div>

                {{-- ── Location description (free text, kept for legacy) ── --}}
                <div class="col-md-6">
                    <label class="form-label">Location Notes <span class="text-muted small">(optional free text)</span></label>
                    <input type="text" name="location_description" class="form-control"
                           value="{{ old('location_description', $device->location_description ?? '') }}" maxlength="255"
                           placeholder="e.g. Server room, under desk">
                </div>

                {{-- ── Warranty ── --}}
                <div class="col-12">
                    <hr class="my-0">
                    <p class="fw-semibold small text-muted mb-0 mt-2">
                        <i class="bi bi-shield-check me-1"></i>Warranty Tracking
                    </p>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control"
                           value="{{ old('purchase_date', isset($device->purchase_date) ? $device->purchase_date->format('Y-m-d') : '') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Warranty Expiry</label>
                    <input type="date" name="warranty_expiry" class="form-control"
                           value="{{ old('warranty_expiry', isset($device->warranty_expiry) ? $device->warranty_expiry->format('Y-m-d') : '') }}">
                </div>

                {{-- ── Firmware ── --}}
                <div class="col-12">
                    <hr class="my-0">
                    <p class="fw-semibold small text-muted mb-0 mt-2">
                        <i class="bi bi-cpu-fill me-1"></i>Firmware Information
                    </p>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Installed Firmware Version</label>
                    <input type="text" name="firmware_version" class="form-control"
                           value="{{ old('firmware_version', $device->firmware_version ?? '') }}" maxlength="100" placeholder="e.g. 15.2(4)E7">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Latest Available Firmware</label>
                    <input type="text" name="latest_firmware" class="form-control"
                           value="{{ old('latest_firmware', $device->latest_firmware ?? '') }}" maxlength="100" placeholder="e.g. 15.2(4)E10">
                </div>

                {{-- ── Notes ── --}}
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3">{{ old('notes', $device->notes ?? '') }}</textarea>
                </div>

            </div>{{-- /row --}}

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save Changes' : 'Create Device' }}</button>
                <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Quick-add Department Modal ────────────────────────────────── --}}
@can('manage-settings')
<div class="modal fade" id="dvQuickAddDeptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Department</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="dvQdName" class="form-control form-control-sm"
                           maxlength="100" placeholder="e.g. Finance, HR, IT">
                </div>
                <div id="dvQdError" class="text-danger small d-none"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="dvQuickAddDept()">Add</button>
            </div>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script>
// ── Server-side URLs ─────────────────────────────────────────────────
const dv_floorsUrl  = '{{ route("admin.network.floors") }}';
const dv_officesUrl = '{{ route("admin.network.offices") }}';
const dv_macUrl     = '{{ route("admin.network.clients.mac-search") }}';
const dv_deptStore  = '{{ route("admin.settings.departments.store") }}';
const dv_csrf       = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Cascading location dropdowns ──────────────────────────────────────

function dvLoadFloors(branchId, keepFloorId, keepOfficeId) {
    const floorSel  = document.getElementById('dv_floor');
    const officeSel = document.getElementById('dv_office');
    floorSel.innerHTML  = '<option value="">— None —</option>';
    officeSel.innerHTML = '<option value="">— None —</option>';
    if (!branchId) return;

    floorSel.disabled = true;
    fetch(`${dv_floorsUrl}?branch_id=${encodeURIComponent(branchId)}`)
        .then(r => r.json())
        .then(floors => {
            floors.forEach(f => {
                const sel = keepFloorId && String(f.id) === String(keepFloorId);
                floorSel.add(new Option(f.name, f.id, sel, sel));
            });
            floorSel.disabled = false;
            if (floorSel.value) dvLoadOffices(floorSel.value, keepOfficeId);
        })
        .catch(() => { floorSel.disabled = false; });
}

function dvLoadOffices(floorId, keepOfficeId) {
    const officeSel = document.getElementById('dv_office');
    officeSel.innerHTML = '<option value="">— None —</option>';
    if (!floorId) return;

    officeSel.disabled = true;
    fetch(`${dv_officesUrl}?floor_id=${encodeURIComponent(floorId)}`)
        .then(r => r.json())
        .then(offices => {
            offices.forEach(o => {
                const sel = keepOfficeId && String(o.id) === String(keepOfficeId);
                officeSel.add(new Option(o.name, o.id, sel, sel));
            });
            officeSel.disabled = false;
        })
        .catch(() => { officeSel.disabled = false; });
}

// Populate on page load for edit mode
document.addEventListener('DOMContentLoaded', () => {
    const branchSel   = document.getElementById('dv_branch');
    const currentFloor  = branchSel.getAttribute('data-current-floor');
    const currentOffice = branchSel.getAttribute('data-current-office');
    if (branchSel.value) {
        dvLoadFloors(branchSel.value, currentFloor, currentOffice);
    }
});

// ── MAC autocomplete ──────────────────────────────────────────────────

const dv_macInput = document.getElementById('dv_mac');
const dv_macList  = document.getElementById('dv_macList');
const dv_ipInput  = document.getElementById('dv_ip');
let dv_macTimer;

if (dv_macInput) {
    dv_macInput.addEventListener('input', function () {
        clearTimeout(dv_macTimer);
        const q = this.value.trim();
        if (q.length < 3) { dv_macList.innerHTML = ''; return; }
        dv_macTimer = setTimeout(() => {
            fetch(`${dv_macUrl}?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(clients => {
                    dv_macList.innerHTML = clients.map(c => {
                        const label = [c.hostname, c.ip, c.manufacturer].filter(Boolean).join(' – ');
                        return `<option value="${c.mac}" data-ip="${c.ip || ''}">${c.mac}${label ? '  (' + label + ')' : ''}</option>`;
                    }).join('');
                });
        }, 300);
    });

    dv_macInput.addEventListener('change', function () {
        const opt = Array.from(dv_macList.options).find(o => o.value === this.value);
        if (opt && dv_ipInput && !dv_ipInput.value) {
            dv_ipInput.value = opt.getAttribute('data-ip') || '';
        }
    });
}

// ── Quick-add department (AJAX) ────────────────────────────────────────

function dvQuickAddDept() {
    const nameInput = document.getElementById('dvQdName');
    const errEl     = document.getElementById('dvQdError');
    const name      = nameInput.value.trim();
    errEl.classList.add('d-none');

    if (!name) {
        errEl.textContent = 'Name is required.';
        errEl.classList.remove('d-none');
        return;
    }

    fetch(dv_deptStore, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': dv_csrf,
            'Accept': 'application/json',
        },
        body: JSON.stringify({ name }),
    })
    .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
    .then(({ ok, data }) => {
        if (!ok) {
            const msgs = Object.values(data.errors || {}).flat();
            errEl.textContent = msgs[0] || 'Error creating department.';
            errEl.classList.remove('d-none');
            return;
        }
        const select = document.getElementById('dv_dept');
        const opt = new Option(data.name, data.id, true, true);
        select.add(opt);
        bootstrap.Modal.getInstance(document.getElementById('dvQuickAddDeptModal')).hide();
        nameInput.value = '';
    })
    .catch(() => {
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.remove('d-none');
    });
}
</script>
@endpush

@endsection
