@extends('layouts.admin')
@section('content')

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-boxes me-2 text-primary"></i>Batch Add Devices
    </h4>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('admin.devices.batch-store') }}">
                    @csrf

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Device Type</label>
                            <select name="type" id="batch_type" class="form-select @error('type') is-invalid @enderror" required onchange="filterModels(this.value)">
                                <option value="monitor" selected>Monitor</option>
                                <option value="laptop">Laptop</option>
                                <option value="desktop">Desktop</option>
                                <option value="keyboard">Keyboard</option>
                                <option value="mouse">Mouse</option>
                                <option value="headset">Headset</option>
                                <option value="tablet">Tablet</option>
                                <option value="other">Other</option>
                            </select>
                            @error('type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Name Prefix</label>
                            <input type="text" name="prefix" class="form-control @error('prefix') is-invalid @enderror" 
                                   value="{{ old('prefix', 'Monitor') }}" required maxlength="50">
                            <div class="form-text small">Devices will be named like: <i>Prefix 1, Prefix 2...</i></div>
                            @error('prefix')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Model</label>
                            <div class="input-group">
                                <select name="device_model_id" id="batch_model" class="form-select">
                                    <option value="">— None —</option>
                                    @foreach($deviceModels as $dm)
                                    <option value="{{ $dm->id }}" 
                                            data-type="{{ $dm->device_type ?? '' }}"
                                            {{ old('device_model_id') == $dm->id ? 'selected' : '' }}>
                                        {{ $dm->manufacturer ? $dm->manufacturer . ' ' . $dm->name : $dm->name }}
                                    </option>
                                    @endforeach
                                </select>
                                <button type="button" class="btn btn-outline-secondary"
                                        data-bs-toggle="modal" data-bs-target="#dvAddModelModal">
                                    <i class="bi bi-plus"></i>
                                </button>
                            </div>
                            <div class="form-text small">Select a model or <a href="#" data-bs-toggle="modal" data-bs-target="#dvAddModelModal">add a new one</a>.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($branches as $b)
                                <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select" required>
                                <option value="available" selected>Available</option>
                                <option value="active">Active</option>
                                <option value="maintenance">Maintenance</option>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Condition</label>
                            <select name="condition" class="form-select" required>
                                <option value="new" selected>New</option>
                                <option value="used">Used</option>
                                <option value="refurbished">Refurbished</option>
                            </select>
                        </div>

                        <div class="col-12 text-center py-2">
                            <hr>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-bold"><i class="bi bi-upc-scan me-1"></i>Serial Numbers (one per line)</label>
                            <textarea name="serials" class="form-control font-monospace @error('serials') is-invalid @enderror" 
                                      rows="10" placeholder="Paste serial numbers here..." required>{{ old('serials') }}</textarea>
                            <div class="form-text small">Quantity is determined by the number of serial numbers entered.</div>
                            @error('serials')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-12"><hr></div>

                        <div class="col-md-4">
                            <label class="form-label">Purchase Date</label>
                            <input type="date" name="purchase_date" class="form-control" value="{{ old('purchase_date') }}">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Purchase Cost (per unit)</label>
                            <div class="input-group">
                                <span class="input-group-text small">SAR</span>
                                <input type="number" name="purchase_cost" class="form-control" 
                                       value="{{ old('purchase_cost') }}" min="0" step="0.01">
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($suppliers as $s)
                                <option value="{{ $s->id }}" {{ old('supplier_id') == $s->id ? 'selected' : '' }}>{{ $s->name }}</option>
                                @endforeach
                            </select>
                        </div>

                    </div>

                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="bi bi-plus-circle me-1"></i>Add All Devices
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 bg-light shadow-none">
            <div class="card-body">
                <h6 class="fw-bold"><i class="bi bi-info-circle me-2 text-primary"></i>Batch Addition</h6>
                <p class="small text-muted mb-0">
                    This tool allows you to add multiple items of the same type and model quickly.
                </p>
                <ul class="small text-muted mt-2 ps-3">
                    <li>Asset codes will be auto-generated based on the type.</li>
                    <li>The name will be <b>Prefix 1, Prefix 2, etc.</b></li>
                    <li>Serial numbers must be unique.</li>
                    <li>Purchase cost is applied to <b>each</b> item individually.</li>
                </ul>
            </div>
        </div>
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
                               placeholder="e.g. UCM6510" maxlength="255">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Manufacturer</label>
                        <input type="text" id="dvAmManufacturer" class="form-control form-control-sm"
                               placeholder="e.g. Lenovo" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Device Type</label>
                        <select id="dvAmType" class="form-select form-select-sm">
                            <option value="">— None —</option>
                            @foreach(\App\Models\AssetType::cached() as $at)
                            <option value="{{ $at->slug }}">{{ $at->label }}</option>
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

@push('scripts')
<script>
const dv_modelStore = '{{ route("admin.devices.models.store") }}';
const dv_csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

function filterModels(type) {
    const modelSel = document.getElementById('batch_model');
    const options  = modelSel.querySelectorAll('option');
    let firstFound = false;

    options.forEach(opt => {
        if (!opt.value) return; // Keep "None"
        const optType = opt.getAttribute('data-type');
        if (!optType || optType === type) {
            opt.style.display = '';
            if (!firstFound) {
                firstFound = true;
            }
        } else {
            opt.style.display = 'none';
            if (opt.selected) modelSel.value = '';
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

    const select = document.getElementById('batch_model');
    const label  = data.manufacturer ? `${data.manufacturer} ${data.name}` : data.name;
    const newOpt = new Option(label, data.id, true, true);
    newOpt.setAttribute('data-type', device_type || '');
    select.add(newOpt);
    
    bootstrap.Modal.getInstance(document.getElementById('dvAddModelModal')).hide();
    ['dvAmName','dvAmManufacturer','dvAmFirmware'].forEach(id => { document.getElementById(id).value = ''; });
    document.getElementById('dvAmType').value = '';
    
    filterModels(document.getElementById('batch_type').value);
});

document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('batch_type').value;
    if (type) filterModels(type);

    const modelSel = document.getElementById('batch_model');
    const prefixInput = document.querySelector('input[name="prefix"]');
    
    modelSel.addEventListener('change', function() {
        const text = this.options[this.selectedIndex].text;
        if (text && text !== '— None —' && (prefixInput.value === 'Monitor' || prefixInput.value === '')) {
            prefixInput.value = text;
        }
    });
});
</script>
@endpush

@endsection
