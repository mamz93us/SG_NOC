@extends('layouts.admin')
@section('content')

@php $isEdit = isset($employee); @endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-badge-fill me-2 text-primary"></i>
            {{ $isEdit ? 'Edit Employee' : 'Add Employee' }}
        </h4>
    </div>
    <a href="{{ $isEdit ? route('admin.employees.show', $employee->id) : route('admin.employees.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST" action="{{ $isEdit ? route('admin.employees.update', $employee->id) : route('admin.employees.store') }}">
                    @csrf
                    @if($isEdit) @method('PUT') @endif

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                                   value="{{ old('name', $employee->name ?? '') }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                                   value="{{ old('email', $employee->email ?? '') }}">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Job Title</label>
                            <input type="text" name="job_title" class="form-control"
                                   value="{{ old('job_title', $employee->job_title ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Status <span class="text-danger">*</span></label>
                            <select name="status" class="form-select @error('status') is-invalid @enderror" required>
                                @foreach(['active'=>'Active','on_leave'=>'On Leave','terminated'=>'Terminated'] as $val => $label)
                                <option value="{{ $val }}" {{ old('status', $employee->status ?? 'active') === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Branch</label>
                            <select name="branch_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ old('branch_id', $employee->branch_id ?? '') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Department</label>
                            <select name="department_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" {{ old('department_id', $employee->department_id ?? '') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Manager</label>
                            <select name="manager_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($managers as $mgr)
                                <option value="{{ $mgr->id }}" {{ old('manager_id', $employee->manager_id ?? '') == $mgr->id ? 'selected' : '' }}>{{ $mgr->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Azure ID <small class="text-muted">(optional)</small></label>
                            <input type="text" name="azure_id" class="form-control font-monospace"
                                   value="{{ old('azure_id', $employee->azure_id ?? '') }}" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Hired Date</label>
                            <input type="date" name="hired_date" class="form-control"
                                   value="{{ old('hired_date', isset($employee) && $employee->hired_date ? $employee->hired_date->format('Y-m-d') : '') }}">
                        </div>
                        @if($isEdit)
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Terminated Date</label>
                            <input type="date" name="terminated_date" class="form-control"
                                   value="{{ old('terminated_date', isset($employee) && $employee->terminated_date ? $employee->terminated_date->format('Y-m-d') : '') }}">
                        </div>
                        @endif
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="3">{{ old('notes', $employee->notes ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>{{ $isEdit ? 'Save Changes' : 'Create Employee' }}
                        </button>
                        <a href="{{ $isEdit ? route('admin.employees.show', $employee->id) : route('admin.employees.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
