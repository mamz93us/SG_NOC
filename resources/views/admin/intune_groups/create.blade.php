@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-collection me-2 text-primary"></i>New Intune Group</h4>
        <small class="text-muted">Creates an Azure AD security group and tracks it in the system</small>
    </div>
    <a href="{{ route('admin.intune-groups.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm" style="max-width:640px;">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.intune-groups.store') }}">
            @csrf

            <div class="mb-3">
                <label class="form-label fw-semibold">Group Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                    value="{{ old('name') }}" placeholder="e.g. SG-Printers-MainBranch" required maxlength="150">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                <div class="form-text">This will be the Azure AD group display name.</div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Description</label>
                <textarea name="description" class="form-control @error('description') is-invalid @enderror"
                    rows="2" placeholder="Optional description" maxlength="500">{{ old('description') }}</textarea>
                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold">Group Type <span class="text-danger">*</span></label>
                <select name="group_type" class="form-select @error('group_type') is-invalid @enderror" required>
                    <option value="">Select type…</option>
                    @foreach(['printer' => 'Printer', 'policy' => 'Policy', 'device' => 'Device', 'compliance' => 'Compliance'] as $val => $label)
                    <option value="{{ $val }}" {{ old('group_type') === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                @error('group_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Branch <small class="text-muted">(optional)</small></label>
                    <select name="branch_id" class="form-select form-select-sm">
                        <option value="">— Any Branch —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Department <small class="text-muted">(optional)</small></label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">— Any Department —</option>
                        @foreach($departments as $d)
                        <option value="{{ $d->id }}" {{ old('department_id') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2">
                <a href="{{ route('admin.intune-groups.index') }}" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary"><i class="bi bi-cloud-check me-1"></i>Create Group in Azure AD</button>
            </div>
        </form>
    </div>
</div>

@endsection
