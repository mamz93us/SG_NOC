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
                    <div class="input-group">
                        <select name="device_model_id" id="dv_model" class="form-select">
                            <option value="">— None —</option>
                            @foreach($deviceModels ?? [] as $dm)
                            <option value="{{ $dm->id }}"
                                    data-type="{{ $dm->device_type ?? '' }}"
                                {{ old('device_model_id', $device->device_model_id ?? '') == $dm->id ? 'selected' : '' }}>
                                {{ $dm->manufacturer ? $dm->manufacturer . ' ' . $dm->name : $dm->name }}
                            </option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-outline-secondary"
                                title="Add new model"
                                data-bs-toggle="modal" data-bs-target="#dvAddModelModal">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                    <div class="form-text text-muted">Select a model or
                        <a href="#" data-bs-toggle="modal" data-bs-target="#dvAddModelModal">add a new one</a>.
                    </div>
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

                {{-- ── ITAM / Asset Management ── --}}
                <div class="col-12">
                    <hr class="my-0">
                    <p class="fw-semibold small text-muted mb-0 mt-2">
                        <i class="bi bi-boxes me-1"></i>Asset Management
                    </p>
                </div>

                {{-- Asset Code --}}
                <div class="col-md-6">
                    <label class="form-label">Asset Code</label>
                    <div class="input-group">
                        <input type="text" name="asset_code" id="dv_asset_code"
                               class="form-control font-monospace @error('asset_code') is-invalid @enderror"
                               value="{{ old('asset_code', $device->asset_code ?? '') }}"
                               maxlength="50" placeholder="Auto-generated on save">
                        <button type="button" class="btn btn-outline-secondary" id="dv_genCode" title="Generate code now">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                    </div>
                    <div class="form-text">Leave blank to auto-generate when saved.</div>
                    @error('asset_code')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>

                {{-- Condition --}}
                <div class="col-md-6">
                    <label class="form-label">Condition</label>
                    <select name="condition" class="form-select">
                        <option value="">— Not specified —</option>
                        @foreach(['new'=>'New','used'=>'Used','refurbished'=>'Refurbished','damaged'=>'Damaged'] as $v=>$l)
                        <option value="{{ $v }}" {{ old('condition', $device->condition ?? '') == $v ? 'selected' : '' }}>{{ $l }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Purchase Cost + Supplier --}}
                <div class="col-md-6">
                    <label class="form-label">Purchase Cost</label>
                    <div class="input-group">
                        <span class="input-group-text">SAR</span>
                        <input type="number" name="purchase_cost" class="form-control"
                               value="{{ old('purchase_cost', $device->purchase_cost ?? '') }}"
                               min="0" step="0.01" placeholder="0.00">
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($suppliers ?? [] as $s)
                        <option value="{{ $s->id }}" {{ old('supplier_id', $device->supplier_id ?? '') == $s->id ? 'selected' : '' }}>
                            {{ $s->name }}
                        </option>
                        @endforeach
                    </select>
                </div>

                {{-- Depreciation --}}
                <div class="col-md-6">
                    <label class="form-label">Depreciation Method</label>
                    <select name="depreciation_method" id="dv_deprMethod" class="form-select"
                            onchange="document.getElementById('dv_deprYearsWrap').classList.toggle('d-none', this.value !== 'straight_line')">
                        <option value="none"          {{ old('depreciation_method', $device->depreciation_method ?? 'none') == 'none'          ? 'selected' : '' }}>None</option>
                        <option value="straight_line" {{ old('depreciation_method', $device->depreciation_method ?? '') == 'straight_line' ? 'selected' : '' }}>Straight Line</option>
                    </select>
                </div>
                <div class="col-md-6" id="dv_deprYearsWrap"
                     class="{{ old('depreciation_method', $device->depreciation_method ?? 'none') !== 'straight_line' ? 'd-none' : '' }}">
                    <label class="form-label">Useful Life (years)</label>
                    <input type="number" name="depreciation_years" class="form-control"
                           value="{{ old('depreciation_years', $device->depreciation_years ?? '') }}"
                           min="1" max="30" placeholder="e.g. 5">
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

{{-- ── Add Model Modal ─────────────────────────────────────────── --}}
<div class="modal fade" id="dvAddModelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-collection me-1"></i>Add Device Model</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="dvAmError" class="alert alert-danger d-none py-2"></div>
                <div class="row g-2">
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Model Name <span class="text-danger">*</span></label>
                        <input type="text" id="dvAmName" class="form-control form-control-sm"
                               placeholder="e.g. UCM6510" maxlength="255">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Manufacturer</label>
                        <input type="text" id="dvAmManufacturer" class="form-control form-control-sm"
                               placeholder="e.g. Grandstream" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Device Type</label>
                        <select id="dvAmType" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach(['ucm','switch','router','firewall','ap','printer','server','laptop','desktop','monitor','keyboard','mouse','headset','tablet','other'] as $t)
                            <option value="{{ $t }}">{{ ucfirst($t) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Latest Firmware</label>
                        <input type="text" id="dvAmFirmware" class="form-control form-control-sm font-monospace"
                               placeholder="e.g. 1.0.23.29" maxlength="100">
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="dvAmSaveBtn">
                    <i class="bi bi-plus-lg me-1"></i>Create &amp; Select
                </button>
            </div>
        </div>
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
const dv_floorsUrl    = '{{ route("admin.network.floors") }}';
const dv_officesUrl   = '{{ route("admin.network.offices") }}';
const dv_macUrl       = '{{ route("admin.network.clients.mac-search") }}';
const dv_deptStore    = '{{ route("admin.settings.departments.store") }}';
const dv_modelStore   = '{{ route("admin.devices.models.store") }}';
const dv_csrf         = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Depreciation years visibility on load ────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const deprMethod = document.getElementById('dv_deprMethod');
    const deprWrap   = document.getElementById('dv_deprYearsWrap');
    if (deprMethod && deprWrap) {
        deprWrap.classList.toggle('d-none', deprMethod.value !== 'straight_line');
    }

    // Asset code generator button
    const genBtn = document.getElementById('dv_genCode');
    const codeInput = document.getElementById('dv_asset_code');
    const typeSelect = document.querySelector('[name="type"]');
    if (genBtn && codeInput) {
        genBtn.addEventListener('click', function() {
            const type = typeSelect ? typeSelect.value : 'other';
            fetch(`{{ route('admin.devices.generate-code') }}?type=${encodeURIComponent(type)}`, {
                headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': dv_csrf }
            })
            .then(r => r.json())
            .then(d => { if (d.code) codeInput.value = d.code; })
            .catch(() => {});
        });
    }
});

// ── Add Model (AJAX) ──────────────────────────────────────────────────
document.getElementById('dvAmSaveBtn').addEventListener('click', async function() {
    const name         = document.getElementById('dvAmName').value.trim();
    const manufacturer = document.getElementById('dvAmManufacturer').value.trim();
    const device_type  = document.getElementById('dvAmType').value;
    const firmware     = document.getElementById('dvAmFirmware').value.trim();
    const errEl        = document.getElementById('dvAmError');
    errEl.classList.add('d-none');

    if (!name) {
        errEl.textContent = 'Model name is required.';
        errEl.classList.remove('d-none');
        return;
    }

    const res = await fetch(dv_modelStore, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': dv_csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ name, manufacturer: manufacturer || null, device_type: device_type || null, latest_firmware: firmware || null }),
    });
    const data = await res.json();
    if (!res.ok && res.status !== 201) {
        const msgs = Object.values(data.errors || {}).flat();
        errEl.textContent = msgs[0] || data.message || 'Error creating model.';
        errEl.classList.remove('d-none');
        return;
    }

    // Add to select and auto-select
    const select = document.getElementById('dv_model');
    const opt = new Option(data.name, data.id, true, true);
    select.add(opt);
    bootstrap.Modal.getInstance(document.getElementById('dvAddModelModal')).hide();

    // Clear form
    ['dvAmName','dvAmManufacturer','dvAmFirmware'].forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('dvAmType').value = '';
});

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
