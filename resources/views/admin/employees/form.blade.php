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

                        {{-- ── Oracle HR & reporting lines ── --}}
                        @php
                            // Picker text for one person. It starts with the id, which is what the controller reads back.
                            $pickerLabel = fn ($e) => $e
                                ? $e->id.' · '.$e->name
                                    .($e->oracle_emp_no ? ' · Oracle '.$e->oracle_emp_no : '')
                                    .($e->branch ? ' · '.$e->branch->name : '')
                                    .($e->status !== 'active' ? ' · '.str_replace('_', ' ', $e->status) : '')
                                : '';
                        @endphp
                        <div class="col-12" id="hr-reporting" style="scroll-margin-top:5rem">
                            <hr class="my-2">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-diagram-3 text-primary"></i>
                                <span class="fw-semibold">Oracle HR &amp; Reporting Lines</span>
                                <small class="text-muted">— applying an Oracle HR import overwrites the Oracle fields</small>
                            </div>
                            @if($isEdit && $employee->linkedPrimary)
                            <div class="alert alert-info small py-2 mb-0 mt-2">
                                <i class="bi bi-link-45deg me-1"></i>
                                This mailbox is linked to
                                <a href="{{ route('admin.employees.show', $employee->linkedPrimary->id) }}">{{ $employee->linkedPrimary->name }}</a>.
                                Attendance and the home-portal assistant read the Oracle number and reporting lines from that record, and Entra takes the department from it —
                                <a href="{{ route('admin.employees.edit', $employee->linkedPrimary->id) }}#hr-reporting">edit them there</a>.
                            </div>
                            @endif
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold">Oracle Employee No</label>
                            <input type="text" name="oracle_emp_no" maxlength="50" autocomplete="off"
                                   class="form-control font-monospace @error('oracle_emp_no') is-invalid @enderror"
                                   value="{{ old('oracle_emp_no', $employee->oracle_emp_no ?? '') }}">
                            @error('oracle_emp_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Matches their fingerprint punches — a change re-matches attendance.</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Oracle Department</label>
                            <input type="text" name="oracle_department" id="oracleDepartment" list="oracle-department-options" maxlength="255" autocomplete="off"
                                   class="form-control @error('oracle_department') is-invalid @enderror"
                                   value="{{ old('oracle_department', $employee->oracle_department ?? '') }}">
                            @error('oracle_department')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Entra's department when set; otherwise the Department above.</div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Oracle Dept No</label>
                            <input type="text" name="oracle_dept_no" id="oracleDeptNo" maxlength="50" autocomplete="off"
                                   class="form-control font-monospace @error('oracle_dept_no') is-invalid @enderror"
                                   value="{{ old('oracle_dept_no', $employee->oracle_dept_no ?? '') }}">
                            @error('oracle_dept_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Manager</label>
                            <input type="text" name="manager" list="reporting-options" autocomplete="off" placeholder="Type a name or Oracle no…"
                                   class="form-control @error('manager') is-invalid @enderror"
                                   value="{{ old('manager', $pickerLabel($employee->manager ?? null)) }}">
                            @error('manager')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Supervisor</label>
                            <input type="text" name="supervisor" list="reporting-options" autocomplete="off" placeholder="Type a name or Oracle no…"
                                   class="form-control @error('supervisor') is-invalid @enderror"
                                   value="{{ old('supervisor', $pickerLabel($employee->supervisor ?? null)) }}">
                            @error('supervisor')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12 form-text mt-1">Clear a field to remove the manager or supervisor.</div>
                        <datalist id="reporting-options">
                            @foreach($employeeOptions as $option)
                            <option value="{{ $pickerLabel($option) }}"></option>
                            @endforeach
                        </datalist>
                        <datalist id="oracle-department-options">
                            @foreach($oracleDepartments as $deptName => $deptNo)
                            <option value="{{ $deptName }}">{{ $deptNo ? '#'.$deptNo : '' }}</option>
                            @endforeach
                        </datalist>
                        @push('scripts')
                        <script>
                        (function () {
                            // A known Oracle department fills in its number, where the name only ever carries one.
                            const numbers = @json((object) $oracleDepartments);
                            const name = document.getElementById('oracleDepartment');
                            const number = document.getElementById('oracleDeptNo');
                            name?.addEventListener('change', function () {
                                const known = numbers[this.value.trim()];
                                if (known) number.value = known;
                            });
                        })();
                        </script>
                        @endpush

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

        @if($isEdit)
        {{-- Signature roles — saved independently of the profile above (own save/remove) --}}
        <div class="card shadow-sm border-0 mt-3">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi bi-pen text-primary"></i>
                    <span class="fw-semibold">Signature roles</span>
                    <small class="text-muted">— extra classic-Outlook signatures for a person with more than one role on this mailbox</small>
                </div>
                <p class="text-muted small mb-3">
                    Each role adds a second signature the user can pick from the Outlook Signature dropdown.
                    <strong>Set a job title and/or department</strong> — that is what makes it differ from the default.
                    The <em>label</em> is only the name shown in Outlook's menu; it does <strong>not</strong> appear in the signature.
                    Each role is saved on its own (the profile above is separate). New Outlook / OWA / mobile always show the default role.
                </p>

                @forelse($employee->signatureRoles as $role)
                <div class="row g-2 align-items-end mb-2">
                    <form method="POST" action="{{ route('admin.employees.signature-roles.update', [$employee->id, $role->id]) }}" class="col-md-11">
                        @csrf @method('PUT')
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label small fw-semibold mb-1">Label</label>
                                <input type="text" name="label" class="form-control form-control-sm" value="{{ $role->label }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">Job title</label>
                                <input type="text" name="job_title" class="form-control form-control-sm" value="{{ $role->job_title }}">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label small fw-semibold mb-1">Department</label>
                                <select name="department" class="form-select form-select-sm">
                                    <option value="">— none —</option>
                                    @foreach($departments as $dept)
                                        <option value="{{ $dept->name }}" @selected($role->department === $dept->name)>{{ $dept->name }}</option>
                                    @endforeach
                                    @if($role->department && ! $departments->contains('name', $role->department))
                                        <option value="{{ $role->department }}" selected>{{ $role->department }} (custom)</option>
                                    @endif
                                </select>
                            </div>
                            <div class="col-md-1">
                                <button type="submit" class="btn btn-primary btn-sm w-100" title="Save this role"><i class="bi bi-check-lg"></i></button>
                            </div>
                        </div>
                    </form>
                    <div class="col-md-1">
                        <form method="POST" action="{{ route('admin.employees.signature-roles.destroy', [$employee->id, $role->id]) }}"
                              onsubmit="return confirm('Remove the &quot;{{ $role->label }}&quot; signature role?');">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm w-100" title="Remove"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                </div>
                @empty
                <p class="text-muted small mb-2">No extra roles yet.</p>
                @endforelse

                <hr class="my-3">
                {{-- Add a new role --}}
                <form method="POST" action="{{ route('admin.employees.signature-roles.store', $employee->id) }}">
                    @csrf
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold mb-1">Label <small class="text-muted">(Outlook menu name)</small></label>
                            <input type="text" name="label" class="form-control form-control-sm" placeholder="e.g. Sales Manager" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Job title</label>
                            <input type="text" name="job_title" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small fw-semibold mb-1">Department</label>
                            <select name="department" class="form-select form-select-sm">
                                <option value="">— none —</option>
                                @foreach($departments as $dept)
                                    <option value="{{ $dept->name }}">{{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-success btn-sm w-100" title="Add role"><i class="bi bi-plus-lg"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
