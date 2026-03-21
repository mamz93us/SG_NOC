@extends('layouts.admin')
@section('content')

@php $editing = isset($driver); @endphp

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/admin/printers/drivers" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-hdd-fill me-2 text-primary"></i>{{ $editing ? 'Edit Driver' : 'Add Printer Driver' }}
    </h4>
</div>

<form method="POST"
      action="{{ $editing ? '/admin/printers/drivers/' . $driver->id : '/admin/printers/drivers' }}"
      enctype="multipart/form-data">
    @csrf
    @if($editing) @method('PUT') @endif

    <div class="card shadow-sm mb-4" style="max-width:800px">
        <div class="card-header py-2">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-printer me-2"></i>Scope — What this driver applies to</h6>
        </div>
        <div class="card-body">
            <div class="mb-3">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="scope_type" id="scope_printer"
                           value="printer" {{ (old('scope_type', $editing && $driver->printer_id ? 'printer' : 'pattern') === 'printer') ? 'checked' : '' }}
                           onchange="toggleScope()">
                    <label class="form-check-label" for="scope_printer">
                        <strong>Link to specific printer</strong>
                    </label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="radio" name="scope_type" id="scope_pattern"
                           value="pattern" {{ (old('scope_type', $editing && !$driver->printer_id ? 'pattern' : (!$editing ? 'printer' : '')) === 'pattern') ? 'checked' : '' }}
                           onchange="toggleScope()">
                    <label class="form-check-label" for="scope_pattern">
                        <strong>Match by manufacturer / model pattern</strong>
                    </label>
                </div>
            </div>

            {{-- Option A: Specific printer --}}
            <div id="scope_printer_fields">
                <label class="form-label fw-semibold">Printer</label>
                <select name="printer_id" class="form-select @error('printer_id') is-invalid @enderror"
                        id="printerSelect">
                    <option value="">— Select Printer —</option>
                    @foreach($printers as $p)
                    <option value="{{ $p->id }}"
                        {{ old('printer_id', $selectedPrinterId ?? ($driver->printer_id ?? '')) == $p->id ? 'selected' : '' }}>
                        {{ $p->printer_name }}
                        @if($p->branch) ({{ $p->branch->name }}) @endif
                    </option>
                    @endforeach
                </select>
                @error('printer_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Option B: Pattern match --}}
            <div id="scope_pattern_fields" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Manufacturer</label>
                    <input type="text" name="manufacturer" class="form-control"
                           value="{{ old('manufacturer', $driver->manufacturer ?? '') }}"
                           placeholder="e.g. HP, Canon, Kyocera" maxlength="100">
                    <div class="form-text">Leave blank to match any manufacturer.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Model Pattern</label>
                    <input type="text" name="model_pattern" class="form-control"
                           value="{{ old('model_pattern', $driver->model_pattern ?? '') }}"
                           placeholder="e.g. LaserJet Pro M404*" maxlength="200">
                    <div class="form-text">
                        Use <code>*</code> as wildcard. E.g. <code>LaserJet Pro M404*</code> matches all M404 variants.
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm mb-4" style="max-width:800px">
        <div class="card-header py-2">
            <h6 class="mb-0 fw-semibold"><i class="bi bi-cpu me-2"></i>Driver Details</h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <div class="col-12">
                    <label class="form-label fw-semibold">
                        Driver Name <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="driver_name"
                           class="form-control font-monospace @error('driver_name') is-invalid @enderror"
                           value="{{ old('driver_name', $driver->driver_name ?? '') }}"
                           placeholder="e.g. HP LaserJet Pro M404 PCL-6"
                           maxlength="255" required>
                    <div class="form-text">
                        Copy the <strong>exact</strong> driver name from Device Manager → Driver tab or INF file.
                        Example: <code>HP LaserJet Pro M404 PCL-6</code>.
                        Used in <code>/m</code> flag of printui.dll and <code>Add-Printer -DriverName</code>.
                    </div>
                    @error('driver_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">OS Type <span class="text-danger">*</span></label>
                    <select name="os_type" class="form-select @error('os_type') is-invalid @enderror" required>
                        @foreach(['windows_x64' => 'Windows 64-bit', 'windows_x86' => 'Windows 32-bit', 'mac' => 'macOS', 'universal' => 'Universal (all OS)'] as $val => $label)
                        <option value="{{ $val }}" {{ old('os_type', $driver->os_type ?? 'windows_x64') === $val ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                        @endforeach
                    </select>
                    @error('os_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Version</label>
                    <input type="text" name="version" class="form-control"
                           value="{{ old('version', $driver->version ?? '') }}"
                           placeholder="e.g. 2.1.0.2412" maxlength="50">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">INF Path <span class="text-muted fw-normal">(optional)</span></label>
                    <input type="text" name="inf_path" class="form-control font-monospace"
                           value="{{ old('inf_path', $driver->inf_path ?? '') }}"
                           placeholder="e.g. HP_M404\hpcu265v.inf"
                           maxlength="500">
                    <div class="form-text">
                        Path to the <code>.inf</code> file <strong>inside</strong> the uploaded zip.
                        Example: <code>HP_M404\hpcu265v.inf</code>.
                        Leave blank if the <code>.inf</code> is in the zip root (will scan with <code>*.inf</code>).
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Driver Package (ZIP)</label>
                    <input type="file" name="driver_file"
                           class="form-control @error('driver_file') is-invalid @enderror"
                           accept=".zip">
                    <div class="form-text">
                        Upload a <code>.zip</code> containing the driver INF and supporting files. Max 200 MB.
                        The file is stored securely and served only via authenticated download.
                        @if($editing && $driver->driver_file_path)
                        <br><strong>Current file:</strong> {{ $driver->original_filename ?? basename($driver->driver_file_path) }}
                        — <a href="/admin/printers/drivers/{{ $driver->id }}/download">Download</a>
                        @endif
                    </div>
                    @error('driver_file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Any additional notes about this driver">{{ old('notes', $driver->notes ?? '') }}</textarea>
                </div>

                <div class="col-12">
                    <div class="form-check form-switch">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" class="form-check-input"
                               id="isActive"
                               {{ old('is_active', $driver->is_active ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <div class="d-flex gap-2" style="max-width:800px">
        <button type="submit" class="btn btn-primary">
            {{ $editing ? 'Save Changes' : 'Add Driver' }}
        </button>
        <a href="/admin/printers/drivers" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
function toggleScope() {
    const isPrinter = document.getElementById('scope_printer').checked;
    document.getElementById('scope_printer_fields').style.display = isPrinter ? '' : 'none';
    document.getElementById('scope_pattern_fields').style.display = isPrinter ? 'none' : '';
    // Clear hidden field when switching
    if (isPrinter) {
        document.querySelector('[name="manufacturer"]').value = '';
        document.querySelector('[name="model_pattern"]').value = '';
    } else {
        document.getElementById('printerSelect').value = '';
    }
}
// Init on load
document.addEventListener('DOMContentLoaded', toggleScope);
</script>
@endpush

@endsection
