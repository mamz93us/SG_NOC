@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-building-add me-2 text-primary"></i>Add Branch</h4>
        <small class="text-muted">Create a new organisational branch</small>
    </div>
    <a href="{{ route('admin.branches.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<form action="{{ route('admin.branches.store') }}" method="POST">
    @csrf

    {{-- Basic Details --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent">
            <strong><i class="bi bi-info-circle me-1"></i>Branch Details</strong>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Branch ID <span class="text-danger">*</span></label>
                    <input type="number" name="id" class="form-control" value="{{ old('id') }}" required placeholder="e.g. 1">
                    @error('id') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="{{ old('name') }}" required placeholder="e.g. Cairo HQ">
                    @error('name') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Main Phone Number</label>
                    <input type="text" name="phone_number" class="form-control" value="{{ old('phone_number') }}" placeholder="+20 2 1234 5678">
                    @error('phone_number') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>
    </div>

    {{-- Phone & UCM Configuration --}}
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-transparent">
            <strong><i class="bi bi-telephone-fill me-1 text-primary"></i>Phone & UCM Configuration</strong>
            <div class="text-muted small mt-1">Branch-specific UCM server, extension range, and profile templates. Leave blank to use global defaults from Settings.</div>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">UCM Server</label>
                    <select name="ucm_server_id" class="form-select">
                        <option value="">— Use Global Default —</option>
                        @foreach($ucmServers as $ucm)
                        <option value="{{ $ucm->id }}" {{ old('ucm_server_id') == $ucm->id ? 'selected' : '' }}>
                            {{ $ucm->name }} <span class="text-muted">({{ $ucm->url }})</span>
                        </option>
                        @endforeach
                    </select>
                    @error('ucm_server_id') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Extension Range Start</label>
                    <input type="number" name="ext_range_start" class="form-control"
                           value="{{ old('ext_range_start') }}" placeholder="e.g. 1000" min="1">
                    @error('ext_range_start') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Extension Range End</label>
                    <input type="number" name="ext_range_end" class="form-control"
                           value="{{ old('ext_range_end') }}" placeholder="e.g. 1999" min="1">
                    @error('ext_range_end') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Office Location Template</label>
                    <input type="text" name="profile_office_template" class="form-control"
                           value="{{ old('profile_office_template') }}"
                           placeholder="{branch_name}, Floor 3">
                    <small class="text-muted">Variables: <code>{branch_name}</code>, <code>{branch_phone}</code>, <code>{extension}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{upn}</code></small>
                    @error('profile_office_template') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Business Phone Template</label>
                    <input type="text" name="profile_phone_template" class="form-control"
                           value="{{ old('profile_phone_template') }}"
                           placeholder="{branch_phone} EXT {extension}">
                    <small class="text-muted">Same variables as above.</small>
                    @error('profile_phone_template') <span class="text-danger small">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Save Branch</button>
        <a href="{{ route('admin.branches.index') }}" class="btn btn-secondary">Cancel</a>
    </div>
</form>
@endsection
