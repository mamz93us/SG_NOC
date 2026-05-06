@extends('layouts.admin')
@section('title', 'Add MAC to RADIUS')

@section('content')
<div class="container-fluid py-4" style="max-width:760px">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-fingerprint me-2 text-primary"></i>Add MAC to RADIUS</h4>
            <small class="text-muted">Manually register a single MAC address (creates a row in <code>device_macs</code> with source=manual).</small>
        </div>
        <a href="{{ route('admin.radius.macs.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <div class="alert alert-info border-0 small mb-4">
        <i class="bi bi-info-circle me-1"></i>
        Most MACs reach RADIUS via automated paths — Intune for Windows PCs,
        and the <strong>Sync from Inventory</strong> button for phones / APs / printers.
        Use this form only for one-off rogue devices, lab gear, or contractor
        laptops not in any inventory.
    </div>

    <form action="{{ route('admin.radius.macs.store') }}" method="POST">
        @csrf

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-fingerprint me-1"></i>MAC Address</strong>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-7">
                        <label class="form-label fw-semibold">MAC <span class="text-danger">*</span></label>
                        <input type="text" name="mac_address" class="form-control font-monospace"
                               value="{{ old('mac_address') }}"
                               placeholder="AA:BB:CC:DD:EE:FF" required autofocus>
                        <small class="text-muted">Any common format: colon, dash, dot, or no separator. Saved as <code>AA:BB:CC:DD:EE:FF</code>.</small>
                        @error('mac_address') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-5">
                        <label class="form-label fw-semibold">Adapter Type <span class="text-danger">*</span></label>
                        <select name="adapter_type" class="form-select" required>
                            @foreach(['ethernet','wifi','usb_ethernet','management','virtual'] as $a)
                                <option value="{{ $a }}" @selected(old('adapter_type', 'ethernet') === $a)>{{ $a }}</option>
                            @endforeach
                        </select>
                        <small class="text-muted">Determines which VLAN policy row matches.</small>
                        @error('adapter_type') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-link-45deg me-1"></i>Optional: Link to Asset</strong>
                <div class="text-muted small mt-1">Linking to an existing device pulls in its branch + type, which the VLAN policy uses.</div>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label fw-semibold">Device</label>
                        <select name="device_id" class="form-select">
                            <option value="">— Unlinked (manual MAC) —</option>
                            @foreach($devices as $d)
                                <option value="{{ $d->id }}" @selected((int) old('device_id') === $d->id)>
                                    {{ $d->name }} ({{ $d->type }})
                                    @if($d->branch_id)
                                        — branch #{{ $d->branch_id }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                        @error('device_id') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" class="form-control"
                               value="{{ old('notes') }}"
                               maxlength="500"
                               placeholder="e.g. contractor laptop, allowed until 2026-06-01">
                        @error('notes') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-success">
                <i class="bi bi-check-lg me-1"></i>Add to Registry
            </button>
            <a href="{{ route('admin.radius.macs.index') }}" class="btn btn-secondary">Cancel</a>
        </div>
    </form>

</div>
@endsection
