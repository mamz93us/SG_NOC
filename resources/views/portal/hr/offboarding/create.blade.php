@extends('layouts.hr')

@section('title', 'New Termination Request')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-dash-fill me-2 text-danger"></i>New Termination Request
        </h4>
        <small class="text-muted">Pick the employee, then enter the termination details</small>
    </div>
    <a href="{{ route('portal.hr.offboarding.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if(! ($settings->offboarding_enabled ?? false))
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-2"></i>
        Offboarding is currently switched off in NOC settings. Contact IT before raising a termination.
    </div>
@endif

{{-- Step 1 — pick the employee --}}
<div class="card shadow-sm border-0 mb-3">
    <div class="card-body">
        <h6 class="fw-bold text-uppercase text-muted small mb-3">Who is leaving</h6>
        @include('portal.hr._employee_picker', [
            'pickerId'     => 'emp',
            'selected'     => $employee,
            'label'        => 'Employee',
            'hiddenFields' => ['employee_id' => 'id'],
            'help'         => 'Their details are pulled from the employee record — you only enter the termination details.',
        ])
    </div>
</div>

@if($employee)

    {{-- Step 2 — their record, read only --}}
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-person-vcard me-2"></i>Employee record</span>
            <span class="badge bg-secondary">Read only</span>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-center gap-3 mb-3 pb-3 border-bottom">
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

            @php
                $rows = [
                    'Job Title'   => $employee->job_title,
                    'Department'  => $employee->department?->name,
                    'Branch'      => $employee->branch?->name,
                    'Manager'     => $employee->manager?->name,
                    'Supervisor'  => $employee->supervisor?->name,
                    'Extension'   => $employee->extension_number,
                    'Mobile'      => $employee->mobile_phone,
                    'Hired'       => $employee->hired_date?->format('M j, Y'),
                ];
            @endphp

            <div class="row g-3 small">
                @foreach($rows as $label => $value)
                    <div class="col-6 col-md-3">
                        <div class="text-muted">{{ $label }}</div>
                        <div class="fw-semibold">{{ $value ?: '—' }}</div>
                    </div>
                @endforeach
            </div>

            @if($assetCount > 0)
                <div class="alert alert-warning small mt-3 mb-0 d-flex gap-2">
                    <i class="bi bi-box-seam"></i>
                    <div>
                        <strong>{{ $assetCount }}</strong>
                        {{ \Illuminate\Support\Str::plural('item', $assetCount) }} still assigned.
                        The manager decides on the decision form what is returned, transferred or scrapped.
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- Step 3 — termination details, the only thing HR fills in --}}
    <form method="POST" action="{{ route('portal.hr.offboarding.store') }}" class="card shadow-sm border-0">
        @csrf
        <input type="hidden" name="employee_id" value="{{ $employee->id }}">

        <div class="card-header bg-transparent fw-semibold">
            <i class="bi bi-calendar-x me-2"></i>Termination details
        </div>

        <div class="card-body">
            <div class="alert alert-info d-flex gap-2 small">
                <i class="bi bi-info-circle fs-5"></i>
                <div>
                    <strong>What happens next:</strong> submitting this emails
                    @if($managerMissing)
                        the manager you pick below
                    @else
                        <strong>{{ $employee->manager->name }}</strong> ({{ $employee->manager->email }})
                    @endif
                    a decision form covering mailbox handling, laptop backup and asset return. Nothing is
                    disabled or deleted until they respond — and the final account deletion only runs
                    after the retention period.
                </div>
            </div>

            <div class="row g-3">
                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Last working day <span class="text-danger">*</span></label>
                    <input type="date" name="last_day" class="form-control" required
                           min="{{ now()->toDateString() }}"
                           value="{{ old('last_day') }}">
                    <div class="form-text">The date access is cut off. Must be today or later.</div>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Reason</label>
                    <select name="reason" class="form-select">
                        <option value="">— Select —</option>
                        @foreach(['resignation' => 'Resignation', 'termination' => 'Termination', 'end_of_contract' => 'End of contract', 'retirement' => 'Retirement', 'other' => 'Other'] as $val => $label)
                            <option value="{{ $val }}" @selected(old('reason') === $val)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-12 col-md-6">
                    <label class="form-label fw-semibold">Oracle Employee ID</label>
                    <input type="text" name="hr_reference" class="form-control" maxlength="50"
                           placeholder="Not set on this employee record"
                           value="{{ old('hr_reference', $employee?->oracle_emp_no) }}"
                           {{ $employee?->oracle_emp_no ? 'readonly' : '' }}>
                    <div class="form-text">
                        @if($employee?->oracle_emp_no)
                            Taken from {{ $employee->name }}'s record.
                        @else
                            This employee has no Oracle number on file — add it if you have it.
                        @endif
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Notes for IT</label>
                    <textarea name="notes" class="form-control" rows="3" maxlength="1000"
                              placeholder="Anything IT should know — handover, disputes, early cut-off…">{{ old('notes') }}</textarea>
                </div>
            </div>

            {{-- The decision form is emailed to the manager, so a missing one is a
                 hard blocker. Otherwise it is an optional override. --}}
            <hr class="my-4">

            @if($managerMissing)
                <div class="alert alert-danger d-flex gap-2 small">
                    <i class="bi bi-exclamation-octagon fs-5"></i>
                    <div>
                        <strong>No manager on file for {{ $employee->name }}.</strong>
                        The decision form has nowhere to go — pick who should receive it.
                    </div>
                </div>
                @include('portal.hr._employee_picker', [
                    'pickerId'     => 'mgr',
                    'selected'     => null,
                    'label'        => 'Send the decision form to',
                    'required'     => true,
                    'help'         => 'Usually the leaver’s line manager or their department head.',
                    'hiddenFields' => ['manager_override_id' => 'id'],
                ])
            @else
                <div>
                    <button class="btn btn-sm btn-outline-secondary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#mgrOverride">
                        <i class="bi bi-pencil me-1"></i>Send the decision form to someone else
                    </button>
                    <div class="collapse mt-3" id="mgrOverride">
                        @include('portal.hr._employee_picker', [
                            'pickerId'     => 'mgr',
                            'selected'     => null,
                            'label'        => 'Send the decision form to',
                            'required'     => false,
                            'help'         => 'Leave blank to use ' . $employee->manager->name . '.',
                            'hiddenFields' => ['manager_override_id' => 'id'],
                        ])
                    </div>
                </div>
            @endif
        </div>

        <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
            <a href="{{ route('portal.hr.offboarding.index') }}" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-danger">
                <i class="bi bi-send me-1"></i>Raise Termination Request
            </button>
        </div>
    </form>
@endif

@push('scripts')
<script>
// Picking the employee reloads the page so their record can be rendered
// server-side, read-only. Only the 'emp' picker navigates — the manager
// override picker just fills its hidden field.
document.addEventListener('hr:employee-selected', function (e) {
    if (e.detail.picker !== 'emp') return;
    const url = new URL(window.location.href);
    url.searchParams.set('employee', e.detail.employee.id);
    window.location.href = url.toString();
});
</script>
@endpush
@endsection
