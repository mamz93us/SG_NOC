@extends('layouts.admin')
@section('content')

<div class="mb-4">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-telephone-fill me-2 text-primary"></i>{{ isset($landline) ? 'Edit' : 'Add' }} Landline
    </h4>
    <small class="text-muted">
        <a href="{{ route('admin.telecom.landlines.index') }}" class="text-decoration-none">Landlines</a> / {{ isset($landline) ? 'Edit' : 'New' }}
    </small>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ isset($landline) ? route('admin.telecom.landlines.update', $landline) : route('admin.telecom.landlines.store') }}">
            @csrf
            @if(isset($landline)) @method('PUT') @endif

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" class="form-select" required>
                        <option value="">Select Branch</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $landline->branch_id ?? '') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    @error('branch_id') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Phone Number <span class="text-danger">*</span></label>
                    <input type="text" name="phone_number" class="form-control font-monospace" value="{{ old('phone_number', $landline->phone_number ?? '') }}" required placeholder="e.g. 0123456789">
                    @error('phone_number') <small class="text-danger">{{ $message }}</small> @enderror
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Provider</label>
                    <input type="text" name="provider" class="form-control" value="{{ old('provider', $landline->provider ?? '') }}" placeholder="e.g. STC, Mobily">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">FXO Port</label>
                    <input type="text" name="fxo_port" class="form-control" value="{{ old('fxo_port', $landline->fxo_port ?? '') }}" placeholder="e.g. 3">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Gateway (UCM Server)</label>
                    <select name="gateway_id" class="form-select">
                        <option value="">None</option>
                        @foreach($gateways as $g)
                        <option value="{{ $g->id }}" {{ old('gateway_id', $landline->gateway_id ?? '') == $g->id ? 'selected' : '' }}>{{ $g->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        @foreach($statuses as $key => $label)
                        <option value="{{ $key }}" {{ old('status', $landline->status ?? 'active') == $key ? 'selected' : '' }}>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2">{{ old('notes', $landline->notes ?? '') }}</textarea>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>{{ isset($landline) ? 'Update' : 'Create' }}
                </button>
                <a href="{{ route('admin.telecom.landlines.index') }}" class="btn btn-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
