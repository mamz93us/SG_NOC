@extends('layouts.hr')

@section('title', 'New Change Request')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-pencil-square me-2 text-info"></i>New Change Request
        </h4>
        <small class="text-muted">Edit the fields you want changed — only what differs is sent to IT</small>
    </div>
    <a href="{{ route('portal.hr.employee-update.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h6 class="fw-bold text-uppercase text-muted small mb-3">Pick the employee</h6>
        @include('portal.hr._employee_picker', [
            'selected'     => $employee,
            'hiddenFields' => ['employee_id' => 'id'],
        ])
        @unless($employee)
            <div class="text-muted small">
                <i class="bi bi-arrow-up me-1"></i>Choose someone to load their current details.
            </div>
        @endunless
    </div>
</div>

@if($employee)

    @if($pending)
        <div class="alert alert-warning d-flex gap-2">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div class="small">
                Request <strong>#{{ $pending->id }}</strong> for {{ $employee->name }} is still open
                ({{ ucfirst(str_replace('_', ' ', $pending->status)) }}).
                Wait for IT to action it before raising another — otherwise the second change would be
                calculated against out-of-date values.
            </div>
        </div>
    @endif

    <div class="alert alert-info d-flex gap-2">
        <i class="bi bi-info-circle fs-5"></i>
        <div class="small">
            Nothing is changed when you submit — IT reviews the request first, then applies it and syncs
            it to Microsoft 365. Work email / UPN is not editable here: changing it affects the mailbox,
            sign-in and email signature, so raise that with IT directly.
        </div>
    </div>

    <form method="POST" action="{{ route('portal.hr.employee-update.store') }}" class="card shadow-sm border-0">
        @csrf
        <input type="hidden" name="employee_id" value="{{ $employee->id }}">

        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                <span class="avatar-circle" style="width:48px;height:48px;font-size:18px;">
                    {{ strtoupper(substr($employee->name, 0, 1)) }}
                </span>
                <div>
                    <div class="fw-bold">{{ $employee->name }}</div>
                    <div class="text-muted small">
                        {{ $employee->email }}
                        @if($employee->oracle_emp_no) · #{{ $employee->oracle_emp_no }} @endif
                    </div>
                </div>
            </div>

            <h6 class="fw-bold text-uppercase text-muted small mb-3">Requested values</h6>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Full Name</label>
                    <input type="text" name="name" class="form-control" maxlength="255"
                           value="{{ old('name', $employee->name) }}">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Job Title</label>
                    <input type="text" name="job_title" class="form-control" maxlength="255"
                           value="{{ old('job_title', $employee->job_title) }}">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Department</label>
                    <select name="department_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($departments as $d)
                            <option value="{{ $d->id }}" @selected(old('department_id', $employee->department_id) == $d->id)>
                                {{ $d->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id', $employee->branch_id) == $b->id)>
                                {{ $b->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Manager</label>
                    <select name="manager_id" class="form-select">
                        <option value="">— None —</option>
                        @foreach($managers as $m)
                            <option value="{{ $m->id }}" @selected(old('manager_id', $employee->manager_id) == $m->id)>
                                {{ $m->name }}@if($m->job_title) — {{ $m->job_title }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Office Location</label>
                    <input type="text" name="office_location" class="form-control" maxlength="255"
                           value="{{ old('office_location', $employee->office_location) }}">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Mobile Phone</label>
                    <input type="text" name="mobile_phone" class="form-control" maxlength="50"
                           value="{{ old('mobile_phone', $employee->mobile_phone) }}">
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Work Phone</label>
                    <input type="text" name="work_phone" class="form-control" maxlength="50"
                           value="{{ old('work_phone', $employee->work_phone) }}">
                </div>
            </div>

            <hr class="my-4">

            <div>
                <label class="form-label fw-semibold">Reason for the change <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="3" required maxlength="1000"
                          placeholder="e.g. Promoted to Service Director effective 1 September — per HR letter #1234.">{{ old('reason') }}</textarea>
                <div class="form-text">IT sees this when reviewing. Be specific — it is the audit trail.</div>
            </div>
        </div>

        <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
            <a href="{{ route('portal.hr.employee-update.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary" @disabled($pending)>
                <i class="bi bi-send me-1"></i>Submit for IT Approval
            </button>
        </div>
    </form>
@endif

@push('scripts')
<script>
// Selecting an employee reloads the page so the form can be pre-filled with
// that person's current values server-side — the diff is computed against them.
document.addEventListener('hr:employee-selected', function (e) {
    const url = new URL(window.location.href);
    url.searchParams.set('employee', e.detail.id);
    window.location.href = url.toString();
});
</script>
@endpush
@endsection
