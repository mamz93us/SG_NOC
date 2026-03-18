@php $p = $prefix ?? 'add'; @endphp

<div class="row g-3">
    <div class="col-md-7">
        <label class="form-label fw-semibold">Model Name <span class="text-danger">*</span></label>
        <input type="text" name="{{ $p }}_name" class="form-control"
               placeholder="e.g. UCM6510, MS225-24P" maxlength="255" required>
    </div>
    <div class="col-md-5">
        <label class="form-label">Manufacturer</label>
        <input type="text" name="{{ $p }}_manufacturer" class="form-control"
               placeholder="e.g. Grandstream, Cisco" maxlength="255">
    </div>
    <div class="col-md-6">
        <label class="form-label">Device Type</label>
        <select name="{{ $p }}_device_type" class="form-select">
            <option value="">— None —</option>
            @foreach(\App\Models\AssetType::cached() as $at)
            <option value="{{ $at->slug }}">{{ $at->label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="form-label">Latest Firmware</label>
        <input type="text" name="{{ $p }}_latest_firmware" class="form-control font-monospace"
               placeholder="e.g. 1.0.23.29" maxlength="100">
    </div>
    <div class="col-12">
        <label class="form-label">Release Notes <span class="text-muted small">(optional)</span></label>
        <textarea name="{{ $p }}_release_notes" class="form-control" rows="2"
                  placeholder="Brief notes about the latest firmware release…"></textarea>
    </div>
</div>
