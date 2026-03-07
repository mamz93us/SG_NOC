@extends('layouts.admin')
@section('content')

@php $editing = isset($printer); @endphp

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('admin.printers.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-printer-fill me-2 text-primary"></i>{{ $editing ? 'Edit Printer' : 'Add Printer' }}
    </h4>
</div>

<div class="card shadow-sm" style="max-width:780px">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('admin.printers.update', $printer) : route('admin.printers.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif

            <div class="row g-3">

                {{-- ── Name ── --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Printer Name <span class="text-danger">*</span></label>
                    <input type="text" name="printer_name" class="form-control @error('printer_name') is-invalid @enderror"
                           value="{{ old('printer_name', $printer->printer_name ?? '') }}" required maxlength="255">
                    @error('printer_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- ── Manufacturer + Model ── --}}
                <div class="col-md-6">
                    <label class="form-label">Manufacturer</label>
                    <input type="text" name="manufacturer" class="form-control"
                           value="{{ old('manufacturer', $printer->manufacturer ?? '') }}" maxlength="100"
                           placeholder="e.g. HP, Canon, Epson">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control"
                           value="{{ old('model', $printer->model ?? '') }}" maxlength="100"
                           placeholder="e.g. LaserJet Pro M404n">
                </div>

                {{-- ── Serial + Toner ── --}}
                <div class="col-md-6">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control font-monospace"
                           value="{{ old('serial_number', $printer->serial_number ?? '') }}" maxlength="100">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Toner Model</label>
                    <input type="text" name="toner_model" class="form-control"
                           value="{{ old('toner_model', $printer->toner_model ?? '') }}" maxlength="100"
                           placeholder="e.g. CF258A">
                </div>

                {{-- ── Network ── --}}
                <div class="col-md-6">
                    <label class="form-label">IP Address</label>
                    <input type="text" name="ip_address" id="pb_ip" class="form-control font-monospace"
                           value="{{ old('ip_address', $printer->ip_address ?? '') }}"
                           placeholder="192.168.1.100">
                    @error('ip_address')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">MAC Address</label>
                    <input type="text" name="mac_address" id="pb_mac" class="form-control font-monospace"
                           value="{{ old('mac_address', $printer->mac_address ?? '') }}" maxlength="20"
                           placeholder="AA:BB:CC:DD:EE:FF"
                           pattern="([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}"
                           title="Format: AA:BB:CC:DD:EE:FF or AA-BB-CC-DD-EE-FF"
                           autocomplete="off"
                           list="pb_macList">
                    <datalist id="pb_macList"></datalist>
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
                    <select name="branch_id" id="pb_branch" class="form-select"
                            data-current-floor="{{ old('floor_id', $printer->floor_id ?? '') }}"
                            data-current-office="{{ old('office_id', $printer->office_id ?? '') }}"
                            onchange="pbLoadFloors(this.value)">
                        <option value="">— None —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $printer->branch_id ?? '') == $b->id ? 'selected' : '' }}>
                            {{ $b->name }}
                        </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Floor</label>
                    <select name="floor_id" id="pb_floor" class="form-select" onchange="pbLoadOffices(this.value)">
                        <option value="">— None —</option>
                        {{-- Pre-populated via JS on DOMContentLoaded --}}
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Office / Room</label>
                    <select name="office_id" id="pb_office" class="form-select">
                        <option value="">— None —</option>
                        {{-- Pre-populated via JS on DOMContentLoaded --}}
                    </select>
                </div>

                {{-- ── Department ── --}}
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <div class="input-group">
                        <select name="department_id" id="pb_dept" class="form-select">
                            <option value="">— None —</option>
                            @foreach($departments as $d)
                            <option value="{{ $d->id }}" {{ old('department_id', $printer->department_id ?? '') == $d->id ? 'selected' : '' }}>
                                {{ $d->name }}
                            </option>
                            @endforeach
                        </select>
                        @can('manage-settings')
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#quickAddDeptModal"
                                title="Add new department">
                            <i class="bi bi-plus"></i>
                        </button>
                        @endcan
                    </div>
                </div>

                {{-- ── SNMP ── --}}
                <div class="col-md-3">
                    <label class="form-label">SNMP Community</label>
                    <input type="text" name="snmp_community" class="form-control font-monospace"
                           value="{{ old('snmp_community', $printer->snmp_community ?? '') }}" maxlength="100"
                           placeholder="public">
                </div>
                <div class="col-md-3">
                    <label class="form-label">SNMP Version</label>
                    <select name="snmp_version" class="form-select">
                        <option value="">— None —</option>
                        @foreach(['v1', 'v2c', 'v3'] as $v)
                        <option value="{{ $v }}" {{ old('snmp_version', $printer->snmp_version ?? '') == $v ? 'selected' : '' }}>{{ $v }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- ── Notes ── --}}
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="3">{{ old('notes', $printer->notes ?? '') }}</textarea>
                </div>

            </div>{{-- /row --}}

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save Changes' : 'Add Printer' }}</button>
                <a href="{{ route('admin.printers.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Quick-add Department Modal ────────────────────────────────── --}}
@can('manage-settings')
<div class="modal fade" id="quickAddDeptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Department</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="qdName" class="form-control form-control-sm"
                           maxlength="100" placeholder="e.g. Finance, HR, IT">
                </div>
                <div id="qdError" class="text-danger small d-none"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="pbQuickAddDept()">Add</button>
            </div>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script>
// ── Server-side URLs ─────────────────────────────────────────────────
const pb_floorsUrl  = '{{ route("admin.network.floors") }}';
const pb_officesUrl = '{{ route("admin.network.offices") }}';
const pb_macUrl     = '{{ route("admin.network.clients.mac-search") }}';
const pb_deptStore  = '{{ route("admin.settings.departments.store") }}';
const pb_csrf       = document.querySelector('meta[name="csrf-token"]')?.content || '';

// ── Cascading location dropdowns ──────────────────────────────────────

function pbLoadFloors(branchId, keepFloorId, keepOfficeId) {
    const floorSel  = document.getElementById('pb_floor');
    const officeSel = document.getElementById('pb_office');
    floorSel.innerHTML  = '<option value="">— None —</option>';
    officeSel.innerHTML = '<option value="">— None —</option>';
    if (!branchId) return;

    floorSel.disabled = true;
    fetch(`${pb_floorsUrl}?branch_id=${encodeURIComponent(branchId)}`)
        .then(r => r.json())
        .then(floors => {
            floors.forEach(f => {
                const sel = keepFloorId && String(f.id) === String(keepFloorId);
                floorSel.add(new Option(f.name, f.id, sel, sel));
            });
            floorSel.disabled = false;
            if (floorSel.value) pbLoadOffices(floorSel.value, keepOfficeId);
        })
        .catch(() => { floorSel.disabled = false; });
}

function pbLoadOffices(floorId, keepOfficeId) {
    const officeSel = document.getElementById('pb_office');
    officeSel.innerHTML = '<option value="">— None —</option>';
    if (!floorId) return;

    officeSel.disabled = true;
    fetch(`${pb_officesUrl}?floor_id=${encodeURIComponent(floorId)}`)
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
    const branchSel   = document.getElementById('pb_branch');
    const currentFloor  = branchSel.getAttribute('data-current-floor');
    const currentOffice = branchSel.getAttribute('data-current-office');
    if (branchSel.value) {
        pbLoadFloors(branchSel.value, currentFloor, currentOffice);
    }
});

// ── MAC autocomplete ──────────────────────────────────────────────────

const pb_macInput = document.getElementById('pb_mac');
const pb_macList  = document.getElementById('pb_macList');
const pb_ipInput  = document.getElementById('pb_ip');
let pb_macTimer;

if (pb_macInput) {
    pb_macInput.addEventListener('input', function () {
        clearTimeout(pb_macTimer);
        const q = this.value.trim();
        if (q.length < 3) { pb_macList.innerHTML = ''; return; }
        pb_macTimer = setTimeout(() => {
            fetch(`${pb_macUrl}?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(clients => {
                    pb_macList.innerHTML = clients.map(c => {
                        const label = [c.hostname, c.ip, c.manufacturer].filter(Boolean).join(' – ');
                        return `<option value="${c.mac}" data-ip="${c.ip || ''}">${c.mac}${label ? '  (' + label + ')' : ''}</option>`;
                    }).join('');
                });
        }, 300);
    });

    pb_macInput.addEventListener('change', function () {
        const opt = Array.from(pb_macList.options).find(o => o.value === this.value);
        if (opt && pb_ipInput && !pb_ipInput.value) {
            pb_ipInput.value = opt.getAttribute('data-ip') || '';
        }
    });
}

// ── Quick-add department (AJAX) ────────────────────────────────────────

function pbQuickAddDept() {
    const nameInput = document.getElementById('qdName');
    const errEl     = document.getElementById('qdError');
    const name      = nameInput.value.trim();
    errEl.classList.add('d-none');

    if (!name) {
        errEl.textContent = 'Name is required.';
        errEl.classList.remove('d-none');
        return;
    }

    fetch(pb_deptStore, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': pb_csrf,
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
        // Add to select and pick it
        const select = document.getElementById('pb_dept');
        const opt = new Option(data.name, data.id, true, true);
        select.add(opt);
        bootstrap.Modal.getInstance(document.getElementById('quickAddDeptModal')).hide();
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
