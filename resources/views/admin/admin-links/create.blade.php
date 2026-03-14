@extends('layouts.admin')

@section('content')

<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Add Admin Link</h1>
        <small class="text-muted">Create a new quick access link</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.admin-links.manage') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.admin-links.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           value="{{ old('name') }}" required>
                    @error('name') <span class="invalid-feedback">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                    <select name="category_id" class="form-select @error('category_id') is-invalid @enderror" required>
                        <option value="">Select category...</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ old('category_id') == $cat->id ? 'selected' : '' }}>
                                {{ $cat->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('category_id') <span class="invalid-feedback">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-12">
                    <label class="form-label fw-semibold">URL <span class="text-danger">*</span></label>
                    <input type="url" name="url" class="form-control @error('url') is-invalid @enderror"
                           value="{{ old('url') }}" placeholder="https://..." required>
                    @error('url') <span class="invalid-feedback">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" name="description" class="form-control @error('description') is-invalid @enderror"
                           value="{{ old('description') }}" placeholder="Brief description of this tool">
                    @error('description') <span class="invalid-feedback">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Icon</label>
                    <div class="input-group">
                        <span class="input-group-text">bi-</span>
                        <input type="text" name="icon" class="form-control @error('icon') is-invalid @enderror"
                               value="{{ old('icon') }}" placeholder="e.g. microsoft, globe, shield-lock">
                    </div>
                    <small class="text-muted">Bootstrap Icons name without bi- prefix. <a href="https://icons.getbootstrap.com" target="_blank" rel="noopener noreferrer">Browse icons</a></small>
                    @error('icon') <span class="invalid-feedback">{{ $message }}</span> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="{{ old('sort_order', 0) }}" min="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Status</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               {{ old('is_active', '1') ? 'checked' : '' }} id="isActive">
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Create Link
                </button>
                <a href="{{ route('admin.admin-links.manage') }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

@endsection
