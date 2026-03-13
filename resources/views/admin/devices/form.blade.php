@extends('layouts.admin')
@section('content')

@php $editing = isset($device); @endphp

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-cpu me-2 text-primary"></i>{{ $editing ? 'Edit Device' : 'Add Device' }}
    </h4>
</div>

<div class="card shadow-sm" style="max-width:760px">
    <div class="card-body">
        <form method="POST" action="{{ $editing ? route('admin.devices.update', $device) : route('admin.devices.store') }}">
            @csrf
            @if($editing) @method('PUT') @endif

            <div class="row g-3">
 
                {{-- ── Asset Identification (Restored to top) ── --}}
                <div class="col-12">
                    <p class="fw-semibold small text-muted mb-0">
                        <i class="bi bi-boxes me-1"></i>Asset Identification
                    </p>
                </div>
                <div class="col-md-7">
                    <label class="form-label fw-semibold">Asset Code</label>
                    <div class="input-group">
                        <input type="text" name="asset_code" id="dv_asset_code"
                               class="form-control font-monospace @error('asset_code') is-invalid @enderror"
                               value="{{ old('asset_code', $device->asset_code ?? request('asset_code', '')) }}"
                               maxlength="50" placeholder="Auto-generating…"
                               oninput="dvUpdateQr(this.value)"
                               {{ $editing ? 'readonly' : '' }}>
                        @if(!$editing)
                        <button type="button" class="btn btn-outline-secondary" id="dv_genCode" title="Re-generate code">
                            <i class="bi bi-arrow-repeat"></i>
                        </button>
                        @endif
                    </div>
                    <div class="form-text small">
                        @if($editing)
                            Locked after creation.
                        @else
                            Auto-generated based on type. You can override it.
                        @endif
                    </div>
                    @error('asset_code')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
 
                    {{-- Condition + Status under asset code --}}
                    <div class="row g-2 mt-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Condition</label>
                            <select name="condition" class="form-select form-select-sm">
                                <option value="">— Not specified —</option>
                                @foreach(['new'=>'New','used'=>'Used','refurbished'=>'Refurbished','damaged'=>'Damaged'] as $v=>$l)
                                <option value="{{ $v }}" {{ old('condition', $device->condition ?? request('condition', 'new')) == $v ? 'selected' : '' }}>{{ $l }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select form-select-sm @error('status') is-invalid @enderror" required>
                                <option value="active"      {{ old('status', $device->status ?? 'active') == 'active'      ? 'selected' : '' }}>Active</option>
                                <option value="available"   {{ old('status', $device->status ?? '') == 'available'   ? 'selected' : '' }}>Available</option>
                                <option value="assigned"    {{ old('status', $device->status ?? '') == 'assigned'    ? 'selected' : '' }}>Assigned</option>
                                <option value="maintenance" {{ old('status', $device->status ?? '') == 'maintenance' ? 'selected' : '' }}>Maintenance</option>
                                <option value="retired"     {{ old('status', $device->status ?? '') == 'retired'     ? 'selected' : '' }}>Retired</option>
                            </select>
                        </div>
                    </div>
                </div>
 
                {{-- QR Code Preview --}}
                <div class="col-md-5 d-flex flex-column align-items-center justify-content-center">
                    <div id="dv_qrWrap" class="border rounded p-2 text-center bg-white" style="min-width:130px;min-height:130px">
                        <canvas id="dv_qrCanvas" style="display:none"></canvas>
                        <span id="dv_qrPlaceholder" class="text-muted small d-flex align-items-center justify-content-center h-100" style="min-height:100px">
                            <span><i class="bi bi-qr-code fs-1 text-muted opacity-25 d-block"></i>QR preview</span>
                        </span>
                    </div>
                    @if($editing && ($device->asset_code ?? ''))
                    <div class="mt-1">
                        <a href="{{ route('admin.devices.label', $device) }}" target="_blank" class="btn btn-sm btn-outline-secondary mt-1">
                            <i class="bi bi-printer me-1"></i>Print Label
                        </a>
                    </div>
                    @endif
                </div>
 
                <div class="col-12"><hr class="my-0"></div>

                {{-- ── Type ── --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select name="type" id="dv_type" class="form-select @error('type') is-invalid @enderror" required
                            onchange="dvTypeChanged(this.value)">
                        <optgroup label="Infrastructure">
                        @foreach(['ucm','switch','router','firewall','ap','printer','server','other'] as $t)
                        <option value="{{ $t }}" {{ old('type', $device->type ?? request('type', 'laptop')) == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                        </optgroup>
                        <optgroup label="User Equipment">
                        @foreach(['laptop','desktop','monitor','keyboard','mouse','headset','tablet'] as $t)
                        <option value="{{ $t }}" {{ old('type', $device->type ?? request('type', 'laptop')) == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                        @endforeach
                        </optgroup>
                    </select>
                    @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- ── Name ── --}}
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name', $device->name ?? request('name', '')) }}" required maxlength="255">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- ── Model + Manufacturer ── --}}
                <div class="col-md-6">
                    <label class="form-label">Model</label>
                    <div class="input-group">
                        <select name="device_model_id" id="dv_model" class="form-select">
                            <option value="">— None —</option>
                            @foreach($deviceModels ?? [] as $dm)
                            <option value="{{ $dm->id }}"
                                    data-type="{{ $dm->device_type ?? '' }}"
                                {{ old('device_model_id', $device->device_model_id ?? request('device_model_id', '')) == $dm->id ? 'selected' : '' }}>
                                {{ $dm->manufacturer ? $dm->manufacturer . ' ' . $dm->name : $dm->name }}
                            </option>
                            @endforeach
                        </select>
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#dvAddModelModal">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                    @if(request('az_manufacturer') || request('az_model'))
                    <div class="form-text text-info">
                        <i class="bi bi-microsoft me-1"></i>
                        Azure: <strong>{{ request('az_manufacturer') }} {{ request('az_model') }}</strong>
                        — select the matching model above or <a href="#" data-bs-toggle="modal" data-bs-target="#dvAddModelModal" id="dv_azPrefillModel">add it</a>.
                    </div>
                    @else
                    <div class="form-text">Select a model or <a href="#" data-bs-toggle="modal" data-bs-target="#dvAddModelModal">add a new one</a>.</div>
                    @endif
                </div>
                <div class="col-md-6">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_number" class="form-control font-monospace"
                           value="{{ old('serial_number', $device->serial_number ?? request('serial_number', '')) }}" maxlength="100">
                </div>

                {{-- ── Azure user hint ── --}}
                @if(request('az_upn'))
                <div class="col-12">
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="bi bi-microsoft me-1"></i>
                        <strong>Azure User:</strong> {{ request('az_upn') }}
                        — assign this device to the matching employee after creation.
                    </div>
                </div>
                @endif

                {{-- ── Network ── --}}
                <div class="col-12"><hr class="my-0"><p class="fw-semibold small text-muted mb-0 mt-2"><i class="bi bi-ethernet me-1"></i>Network</p></div>
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
                           autocomplete="off" list="dv_macList">
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
                        <option value="{{ $b->id }}" {{ old('branch_id', $device->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
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
                <div class="col-md-6">
                    <label class="form-label">Department</label>
                    <div class="input-group">
                        <select name="department_id" id="dv_dept" class="form-select">
                            <option value="">— None —</option>
                            @foreach($departments as $d)
                            <option value="{{ $d->id }}" {{ old('department_id', $device->department_id ?? '') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
                            @endforeach
                        </select>
                        @can('manage-settings')
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#dvQuickAddDeptModal">
                            <i class="bi bi-plus"></i>
                        </button>
                        @endcan
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Location Notes <span class="text-muted small">(optional)</span></label>
                    <input type="text" name="location_description" class="form-control"
                           value="{{ old('location_description', $device->location_description ?? '') }}" maxlength="255"
                           placeholder="e.g. Server room, under desk">
                </div>

                {{-- ── Warranty ── --}}
                <div class="col-12">
                    <hr class="my-0">
                    <p class="fw-semibold small text-muted mb-0 mt-2">
                        <i class="bi bi-shield-check me-1"></i>Warranty &amp; Purchase
                    </p>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control"
                           value="{{ old('purchase_date', isset($device->purchase_date) ? $device->purchase_date->format('Y-m-d') : '') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Warranty Expiry</label>
                    <input type="date" name="warranty_expiry" class="form-control"
                           value="{{ old('warranty_expiry', isset($device->warranty_expiry) ? $device->warranty_expiry->format('Y-m-d') : '') }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Purchase Cost</label>
                    <div class="input-group">
                        <span class="input-group-text small">SAR</span>
                        <input type="number" name="purchase_cost" class="form-control"
                               value="{{ old('purchase_cost', $device->purchase_cost ?? '') }}"
                               min="0" step="0.01" placeholder="0.00">
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Supplier</label>
                    <div class="input-group">
                        <select name="supplier_id" id="dv_supplier" class="form-select">
                            <option value="">— None —</option>
                            @foreach($suppliers ?? [] as $s)
                            <option value="{{ $s->id }}" {{ old('supplier_id', $device->supplier_id ?? '') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                            @endforeach
                        </select>
                        @can('manage-suppliers')
                        <button type="button" class="btn btn-outline-secondary"
                                data-bs-toggle="modal" data-bs-target="#dvAddSupplierModal" title="Add Supplier">
                            <i class="bi bi-plus"></i>
                        </button>
                        @endcan
                    </div>
                </div>

                {{-- ── Depreciation ── --}}
                <div class="col-md-6">
                    <label class="form-label">Depreciation Method</label>
                    <select name="depreciation_method" id="dv_deprMethod" class="form-select"
                            onchange="document.getElementById('dv_deprYearsWrap').classList.toggle('d-none', this.value !== 'straight_line')">
                        <option value="none"          {{ old('depreciation_method', $device->depreciation_method ?? 'none') == 'none'          ? 'selected' : '' }}>None</option>
                        <option value="straight_line" {{ old('depreciation_method', $device->depreciation_method ?? '') == 'straight_line' ? 'selected' : '' }}>Straight Line</option>
                    </select>
                </div>
                <div class="col-md-6 {{ old('depreciation_method', $device->depreciation_method ?? 'none') !== 'straight_line' ? 'd-none' : '' }}" id="dv_deprYearsWrap">
                    <label class="form-label">Useful Life (years)</label>
                    <input type="number" name="depreciation_years" class="form-control"
                           value="{{ old('depreciation_years', $device->depreciation_years ?? '') }}"
                           min="1" max="30" placeholder="e.g. 5">
                </div>

                {{-- ── Notes ── --}}
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $device->notes ?? '') }}</textarea>
                </div>

            </div>{{-- /row --}}

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">{{ $editing ? 'Save Changes' : 'Create Device' }}</button>
                <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

{{-- ── Add Model Modal ── --}}
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
                               placeholder="e.g. UCM6510" maxlength="255"
                               value="{{ request('az_model', '') }}">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Manufacturer</label>
                        <input type="text" id="dvAmManufacturer" class="form-control form-control-sm"
                               placeholder="e.g. Lenovo" maxlength="255"
                               value="{{ request('az_manufacturer', '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Device Type</label>
                        <select id="dvAmType" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach(['ucm','switch','router','firewall','ap','printer','server','laptop','desktop','monitor','keyboard','mouse','headset','tablet','other'] as $t)
                            <option value="{{ $t }}" {{ request('type') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
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

{{-- ── Quick-add Department Modal ── --}}
@can('manage-settings')
<div class="modal fade" id="dvQuickAddDeptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Department</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                <input type="text" id="dvQdName" class="form-control form-control-sm"
                       maxlength="100" placeholder="e.g. Finance, HR, IT">
                <div id="dvQdError" class="text-danger small d-none mt-1"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" onclick="dvQuickAddDept()">Add</button>
            </div>
        </div>
    </div>
</div>
@endcan

{{-- ── Quick-add Supplier Modal ── --}}
@can('manage-suppliers')
<div class="modal fade" id="dvAddSupplierModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-building me-1"></i>Add Supplier</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="dvSupError" class="alert alert-danger d-none py-2 small"></div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="dvSupName" class="form-control form-control-sm" maxlength="255" placeholder="e.g. Al-Jazeera Tech">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Contact Person</label>
                    <input type="text" id="dvSupContact" class="form-control form-control-sm" maxlength="255">
                </div>
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Email</label>
                    <input type="email" id="dvSupEmail" class="form-control form-control-sm" maxlength="255">
                </div>
                <div>
                    <label class="form-label small fw-semibold">Phone</label>
                    <input type="text" id="dvSupPhone" class="form-control form-control-sm" maxlength="30">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" id="dvSupSaveBtn">
                    <i class="bi bi-plus-lg me-1"></i>Create &amp; Select
                </button>
            </div>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/qrcode/build/qrcode.min.js"></script>
<script>
// ── Server-side URLs ──────────────────────────────────────────────────
const dv_floorsUrl   = '{{ route("admin.network.floors") }}';
const dv_officesUrl  = '{{ route("admin.network.offices") }}';
const dv_macUrl      = '{{ route("admin.network.clients.mac-search") }}';
const dv_deptStore   = '{{ route("admin.settings.departments.store") }}';
const dv_modelStore    = '{{ route("admin.devices.models.store") }}';
const dv_supplierStore = '{{ route("admin.itam.suppliers.store") }}';
const dv_genCodeUrl    = '{{ route("admin.devices.generate-code") }}';
const dv_csrf        = document.querySelector('meta[name="csrf-token"]')?.content || '';
const dv_editing     = {{ $editing ? 'true' : 'false' }};

// ── QR Code preview ──────────────────────────────────────────────────
function dvUpdateQr(code) {
    const canvas = document.getElementById('dv_qrCanvas');
    const placeholder = document.getElementById('dv_qrPlaceholder');
    if (!code || code.length < 3) {
        canvas.style.display = 'none';
        placeholder.style.display = 'flex';
        return;
    }
    QRCode.toCanvas(canvas, code, { width: 120, margin: 1 }, function(err) {
        if (!err) {
            canvas.style.display = 'block';
            placeholder.style.display = 'none';
        }
    });
}

// ── Auto-generate asset code ──────────────────────────────────────────
async function dvGenerateCode(type) {
    try {
        const r = await fetch(`${dv_genCodeUrl}?type=${encodeURIComponent(type)}`, {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': dv_csrf }
        });
        const d = await r.json();
        if (d.code) {
            document.getElementById('dv_asset_code').value = d.code;
            dvUpdateQr(d.code);
        }
    } catch(e) {}
}

function dvTypeChanged(type) {
    const codeInput = document.getElementById('dv_asset_code');
    if (!dv_editing && !codeInput.value) {
        dvGenerateCode(type);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const codeInput  = document.getElementById('dv_asset_code');
    const typeSelect = document.getElementById('dv_type');

    // Auto-generate on new device load
    if (!dv_editing && !codeInput.value) {
        dvGenerateCode(typeSelect ? typeSelect.value : 'other');
    } else if (codeInput.value) {
        dvUpdateQr(codeInput.value);
    }

    // Re-generate button
    const genBtn = document.getElementById('dv_genCode');
    if (genBtn) {
        genBtn.addEventListener('click', () => {
            dvGenerateCode(typeSelect ? typeSelect.value : 'other');
        });
    }

    // Depreciation years visibility
    const deprMethod = document.getElementById('dv_deprMethod');
    const deprWrap   = document.getElementById('dv_deprYearsWrap');
    if (deprMethod && deprWrap) {
        deprWrap.classList.toggle('d-none', deprMethod.value !== 'straight_line');
    }

    // Location dropdowns restore on edit
    const branchSel     = document.getElementById('dv_branch');
    const currentFloor  = branchSel.getAttribute('data-current-floor');
    const currentOffice = branchSel.getAttribute('data-current-office');
    if (branchSel.value) {
        dvLoadFloors(branchSel.value, currentFloor, currentOffice);
    }

    // Azure: pre-fill Add Model modal
    const azPrefill = document.getElementById('dv_azPrefillModel');
    if (azPrefill) {
        azPrefill.addEventListener('click', e => { e.preventDefault(); });
    }
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

// ── MAC autocomplete ──────────────────────────────────────────────────
const dv_macInput = document.getElementById('dv_mac');
const dv_macList  = document.getElementById('dv_macList');
const dv_ipInput  = document.getElementById('dv_ip');
let dv_macTimer;

if (dv_macInput) {
    dv_macInput.addEventListener('input', function() {
        clearTimeout(dv_macTimer);
        const q = this.value.trim();
        if (q.length < 3) { dv_macList.innerHTML = ''; return; }
        dv_macTimer = setTimeout(() => {
            fetch(`${dv_macUrl}?q=${encodeURIComponent(q)}`)
                .then(r => r.json())
                .then(clients => {
                    dv_macList.innerHTML = clients.map(c => {
                        const label = [c.hostname, c.ip, c.manufacturer].filter(Boolean).join(' – ');
                        return `<option value="${c.mac}">${c.mac}${label ? '  (' + label + ')' : ''}</option>`;
                    }).join('');
                });
        }, 300);
    });
    dv_macInput.addEventListener('change', function() {
        const opt = Array.from(dv_macList.options).find(o => o.value === this.value);
        if (opt && dv_ipInput && !dv_ipInput.value) {
            dv_ipInput.value = opt.getAttribute('data-ip') || '';
        }
    });
}

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

    const select = document.getElementById('dv_model');
    const label  = data.manufacturer ? `${data.manufacturer} ${data.name}` : data.name;
    select.add(new Option(label, data.id, true, true));
    bootstrap.Modal.getInstance(document.getElementById('dvAddModelModal')).hide();
    ['dvAmName','dvAmManufacturer','dvAmFirmware'].forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('dvAmType').value = '';
});

// ── Quick-add department ──────────────────────────────────────────────
function dvQuickAddDept() {
    const nameInput = document.getElementById('dvQdName');
    const errEl     = document.getElementById('dvQdError');
    const name      = nameInput.value.trim();
    errEl.classList.add('d-none');
    if (!name) { errEl.textContent = 'Name is required.'; errEl.classList.remove('d-none'); return; }

    fetch(dv_deptStore, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': dv_csrf, 'Accept': 'application/json' },
        body: JSON.stringify({ name }),
    })
    .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
    .then(({ ok, data }) => {
        if (!ok) {
            errEl.textContent = Object.values(data.errors || {}).flat()[0] || 'Error.';
            errEl.classList.remove('d-none');
            return;
        }
        document.getElementById('dv_dept').add(new Option(data.name, data.id, true, true));
        bootstrap.Modal.getInstance(document.getElementById('dvQuickAddDeptModal')).hide();
        nameInput.value = '';
    });
}
</script>
@endpush

@endsection
