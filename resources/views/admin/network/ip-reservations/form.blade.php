@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-hdd-rack me-2 text-primary"></i>{{ isset($reservation) ? 'Edit' : 'Reserve' }} IP Address
    </h4>
    <small class="text-muted">
        <a href="{{ route('admin.network.ip-reservations.index') }}" class="text-decoration-none">IP Reservations</a> / {{ isset($reservation) ? 'Edit' : 'New' }}
    </small>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ isset($reservation) ? route('admin.network.ip-reservations.update', $reservation) : route('admin.network.ip-reservations.store') }}">
            @csrf
            @if(isset($reservation)) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">Select Branch</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $reservation->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">IP Address <span class="text-danger">*</span></label>
                    <input type="text" name="ip_address" class="form-control font-monospace" value="{{ old('ip_address', $reservation->ip_address ?? '') }}" required placeholder="e.g. 10.2.1.150">
                    @error('ip_address') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Subnet</label>
                    <input type="text" name="subnet" class="form-control font-monospace" value="{{ old('subnet', $reservation->subnet ?? '') }}" placeholder="e.g. 255.255.255.0 or /24">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">VLAN</label>
                    <input type="number" name="vlan" class="form-control" value="{{ old('vlan', $reservation->vlan ?? '') }}" min="0" max="4094">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Device Type</label>
                    <select name="device_type" class="form-select">
                        <option value="">Select Type</option>
                        @foreach($deviceTypes as $key => $label)
                        <option value="{{ $key }}" {{ old('device_type', $reservation->device_type ?? '') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Device Name</label>
                    <input type="text" name="device_name" class="form-control" value="{{ old('device_name', $reservation->device_name ?? '') }}" placeholder="e.g. Switch RYD_3">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">MAC Address</label>
                    <input type="text" name="mac_address" class="form-control font-monospace" value="{{ old('mac_address', $reservation->mac_address ?? '') }}" placeholder="e.g. 6c:c3:b2:84:27:5f">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Assigned To</label>
                    <input type="text" name="assigned_to" class="form-control" value="{{ old('assigned_to', $reservation->assigned_to ?? '') }}" placeholder="Person or system">
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $reservation->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ isset($reservation) ? 'Update' : 'Reserve' }}
                </button>
                <a href="{{ route('admin.network.ip-reservations.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
