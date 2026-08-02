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
                            <label class="form-label small fw-semibold">Gender</label>
                            <select name="gender" class="form-select @error('gender') is-invalid @enderror">
                                <option value="">— Unspecified —</option>
                                @foreach(['male'=>'Male','female'=>'Female'] as $val => $label)
                                <option value="{{ $val }}" {{ old('gender', $employee->gender ?? '') === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Used to pick the male/female signature template.</div>
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

                        {{-- ── Contact information (NOC = source of truth, auto-synced to Azure AD) ── --}}
                        <div class="col-12">
                            <hr class="my-2">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-person-vcard text-primary"></i>
                                <span class="fw-semibold">Contact Information</span>
                                <small class="text-muted">— edited here, pushed to Azure AD on save</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone</label>
                            <input type="text" name="mobile_phone" class="form-control"
                                   value="{{ old('mobile_phone', $employee->mobile_phone ?? '') }}" placeholder="+20 …">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Business Phone</label>
                            <input type="text" name="work_phone" class="form-control"
                                   value="{{ old('work_phone', $employee->work_phone ?? '') }}" placeholder="+20 2 …">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Extension <small class="text-muted">(&rarr; Azure fax field / signature)</small></label>
                            <input type="text" name="extension_number" class="form-control"
                                   value="{{ old('extension_number', $employee->extension_number ?? '') }}" placeholder="e.g. 1708">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Company</label>
                            <input type="text" name="company" class="form-control"
                                   value="{{ old('company', $employee->company ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Office Location</label>
                            <input type="text" name="office_location" class="form-control"
                                   value="{{ old('office_location', $employee->office_location ?? '') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">City</label>
                            <input type="text" name="city" class="form-control"
                                   value="{{ old('city', $employee->city ?? '') }}">
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Street Address</label>
                            <input type="text" name="street_address" class="form-control"
                                   value="{{ old('street_address', $employee->street_address ?? '') }}">
                        </div>

                        @if($isEdit)
                        <div class="col-12">
                            <hr class="my-2">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-pen text-primary"></i>
                                <span class="fw-semibold">Signature roles</span>
                                <small class="text-muted">— extra classic-Outlook signatures for a person with more than one role on this mailbox</small>
                            </div>
                            <p class="text-muted small mb-2">
                                Each role adds a second signature the user can pick from the Outlook Signature dropdown.
                                Only the <strong>job title</strong> and <strong>department</strong> change; the employee's own title/department
                                above stays the default. New Outlook / OWA / mobile always show the default role.
                            </p>

                            <div id="sig-roles">
                                @foreach($employee->signatureRoles as $role)
                                <div class="row g-2 align-items-end mb-2 sig-role-row">
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold mb-1">Label <small class="text-muted">(shown in Outlook)</small></label>
                                        <input type="text" name="signature_roles[][label]" class="form-control form-control-sm" value="{{ $role->label }}" placeholder="e.g. Sales Manager">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold mb-1">Job title</label>
                                        <input type="text" name="signature_roles[][job_title]" class="form-control form-control-sm" value="{{ $role->job_title }}">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold mb-1">Department</label>
                                        <input type="text" name="signature_roles[][department]" class="form-control form-control-sm" value="{{ $role->department }}">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger btn-sm w-100 sig-role-remove" title="Remove"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                                @endforeach
                            </div>

                            <button type="button" id="sig-role-add" class="btn btn-outline-secondary btn-sm mt-1">
                                <i class="bi bi-plus-circle me-1"></i>Add role
                            </button>

                            <template id="sig-role-template">
                                <div class="row g-2 align-items-end mb-2 sig-role-row">
                                    <div class="col-md-3">
                                        <label class="form-label small fw-semibold mb-1">Label <small class="text-muted">(shown in Outlook)</small></label>
                                        <input type="text" name="signature_roles[][label]" class="form-control form-control-sm" placeholder="e.g. Sales Manager">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold mb-1">Job title</label>
                                        <input type="text" name="signature_roles[][job_title]" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-semibold mb-1">Department</label>
                                        <input type="text" name="signature_roles[][department]" class="form-control form-control-sm">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-outline-danger btn-sm w-100 sig-role-remove" title="Remove"><i class="bi bi-x-lg"></i></button>
                                    </div>
                                </div>
                            </template>
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

@if($isEdit)
@push('scripts')
<script>
(function () {
    var container = document.getElementById('sig-roles');
    var tpl = document.getElementById('sig-role-template');
    var addBtn = document.getElementById('sig-role-add');
    if (!container || !tpl || !addBtn) return;

    addBtn.addEventListener('click', function () {
        container.appendChild(tpl.content.cloneNode(true));
    });

    // Event delegation for remove buttons (covers pre-filled + newly added rows).
    container.addEventListener('click', function (e) {
        var btn = e.target.closest('.sig-role-remove');
        if (!btn) return;
        var row = btn.closest('.sig-role-row');
        if (row) row.remove();
    });
})();
</script>
@endpush
@endif
@endsection
