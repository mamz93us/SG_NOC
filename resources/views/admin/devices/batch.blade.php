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
                            <label class="form-label">Model</label>
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

@push('scripts')
<script>
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
                // opt.selected = true; // Optional: auto-select first matching
                firstFound = true;
            }
        } else {
            opt.style.display = 'none';
            if (opt.selected) modelSel.value = '';
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const type = document.getElementById('batch_type').value;
    if (type) filterModels(type);
});
</script>
@endpush

@endsection
