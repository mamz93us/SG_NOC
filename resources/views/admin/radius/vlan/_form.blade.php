{{-- Shared form fields for VLAN policy create/edit. $policy is null on create. --}}

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-funnel me-1"></i>Match Conditions</strong>
        <div class="text-muted small mt-1">Most-specific match wins on a tie; lower priority value wins between equals.</div>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                <select name="branch_id" class="form-select" required>
                    <option value="">— Select branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $policy?->branch_id) == $b->id)>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
                @error('branch_id') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Adapter Type</label>
                <select name="adapter_type" class="form-select">
                    @foreach(['any','ethernet','wifi','usb_ethernet','management','virtual'] as $a)
                        <option value="{{ $a }}" @selected(old('adapter_type', $policy?->adapter_type ?? 'any') === $a)>
                            {{ $a === 'any' ? 'Any (catch-all)' : $a }}
                        </option>
                    @endforeach
                </select>
                <small class="text-muted">Match against <code>device_macs.adapter_type</code>.</small>
                @error('adapter_type') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>

            <div class="col-md-4">
                <label class="form-label fw-semibold">Device Type (optional)</label>
                <input type="text" name="device_type" class="form-control"
                       value="{{ old('device_type', $policy?->device_type) }}"
                       placeholder="phone, printer, ap, switch, pc, ...">
                <small class="text-muted">Match against <code>devices.type</code>. Leave blank for any.</small>
                @error('device_type') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-diagram-3 me-1"></i>VLAN Assignment</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label fw-semibold">VLAN ID <span class="text-danger">*</span></label>
                <input type="number" name="vlan_id" class="form-control"
                       value="{{ old('vlan_id', $policy?->vlan_id) }}" min="1" max="4094" required>
                @error('vlan_id') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-3">
                <label class="form-label fw-semibold">Priority</label>
                <input type="number" name="priority" class="form-control"
                       value="{{ old('priority', $policy?->priority ?? 100) }}" min="1" max="65535">
                <small class="text-muted">Lower wins on ties.</small>
                @error('priority') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
            <div class="col-md-6">
                <label class="form-label fw-semibold">Description</label>
                <input type="text" name="description" class="form-control"
                       value="{{ old('description', $policy?->description) }}"
                       placeholder="e.g. JED Wi-Fi clients → corporate VLAN">
                @error('description') <span class="text-danger small">{{ $message }}</span> @enderror
            </div>
        </div>
    </div>
</div>
