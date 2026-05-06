{{-- Shared form fields for NAS create/edit. $nas is null on create. --}}

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-info-circle me-1"></i>Client Identity</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label fw-semibold">NAS / CIDR <span class="text-danger">*</span></label>
                <input type="text" name="nasname" class="form-control font-monospace"
                       value="{{ old('nasname', $nas?->nasname) }}"
                       placeholder="10.10.4.5  or  10.10.4.0/24" required>
                <small class="text-muted">Single IP or CIDR. FreeRADIUS drops requests from any other source.</small>
                @error('nasname') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Short Name <span class="text-danger">*</span></label>
                <input type="text" name="shortname" class="form-control"
                       value="{{ old('shortname', $nas?->shortname) }}"
                       placeholder="jed-core-sw1" required>
                <small class="text-muted">Human-readable identifier shown in logs.</small>
                @error('shortname') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Vendor / Type <span class="text-danger">*</span></label>
                <select name="type" class="form-select" required>
                    @foreach(['cisco','aruba','meraki','mikrotik','other'] as $t)
                        <option value="{{ $t }}" @selected(old('type', $nas?->type ?? 'other') === $t)>{{ ucfirst($t) }}</option>
                    @endforeach
                </select>
                @error('type') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-shield-lock me-1"></i>Shared Secret</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">
                    Secret
                    @if($nas)
                        <span class="text-muted small fw-normal">(leave blank to keep current)</span>
                    @else
                        <span class="text-danger">*</span>
                    @endif
                </label>
                <input type="text" name="secret" class="form-control font-monospace"
                       value="{{ old('secret') }}"
                       placeholder="@if($nas){{ $nas->maskedSecret() }}@else min 6 chars @endif"
                       autocomplete="off" {{ $nas ? '' : 'required' }}>
                <small class="text-muted">Must match the secret configured on the switch / AP.</small>
                @error('secret') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Branch (drives default VLAN policy)</label>
                <select name="branch_id" class="form-select">
                    <option value="">— No branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $nas?->branch_id) == $b->id)>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-9">
                <label class="form-label fw-semibold">Description</label>
                <input type="text" name="description" class="form-control"
                       value="{{ old('description', $nas?->description) }}"
                       placeholder="e.g. Core switch on JED 3rd floor IDF">
                @error('description') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check form-switch">
                    <input type="hidden" name="is_active" value="0">
                    <input type="checkbox" name="is_active" id="is_active" value="1"
                           class="form-check-input"
                           @checked(old('is_active', $nas?->is_active ?? true))>
                    <label class="form-check-label" for="is_active">Active</label>
                </div>
            </div>
        </div>
    </div>
</div>
