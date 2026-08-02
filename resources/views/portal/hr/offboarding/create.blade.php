@extends('layouts.hr')

@section('title', 'New Termination Request')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-dash-fill me-2 text-danger"></i>New Termination Request
        </h4>
        <small class="text-muted">The manager decides what happens to the mailbox, laptop and assets</small>
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

<div class="alert alert-info d-flex gap-2">
    <i class="bi bi-info-circle fs-5"></i>
    <div class="small">
        <strong>What happens next:</strong> submitting this emails the employee's manager a decision form
        (mailbox handling, laptop backup, asset return). Nothing is disabled or deleted until the manager
        responds — and the final account deletion only runs after the retention period.
    </div>
</div>

<form method="POST" action="{{ route('portal.hr.offboarding.store') }}" class="card shadow-sm border-0">
    @csrf
    <div class="card-body">

        <h6 class="fw-bold text-uppercase text-muted small mb-3">Who is leaving</h6>

        @include('portal.hr._employee_picker', [
            'selected'     => $employee,
            'hiddenFields' => ['employee_id' => 'id', 'upn' => 'email'],
        ])

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Full name <span class="text-danger">*</span></label>
                <input type="text" name="employee_name" class="form-control" required maxlength="200"
                       value="{{ old('employee_name', $employee?->name) }}">
                <div class="form-text">Shown to the manager on the decision form.</div>
            </div>

            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Branch</label>
                <select name="branch_id" class="form-select">
                    <option value="">— Use the employee's branch —</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" @selected(old('branch_id', $employee?->branch_id) == $b->id)>
                            {{ $b->name }}
                        </option>
                    @endforeach
                </select>
            </div>

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
        </div>

        <hr class="my-4">

        <h6 class="fw-bold text-uppercase text-muted small mb-3">Manager (receives the decision form)</h6>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Manager email <span class="text-danger">*</span></label>
                <input type="email" name="manager_email" class="form-control" required maxlength="200"
                       value="{{ old('manager_email', $employee?->manager?->email) }}">
                <div class="form-text">This is who decides on mailbox, laptop and assets.</div>
            </div>

            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">Manager name</label>
                <input type="text" name="manager_name" class="form-control" maxlength="200"
                       value="{{ old('manager_name', $employee?->manager?->name) }}">
            </div>
        </div>

        <hr class="my-4">

        <h6 class="fw-bold text-uppercase text-muted small mb-3">Reference</h6>

        <div class="row g-3">
            <div class="col-12 col-md-6">
                <label class="form-label fw-semibold">HR reference</label>
                <input type="text" name="hr_reference" class="form-control" maxlength="100"
                       placeholder="e.g. HR-OFF-2026-012" value="{{ old('hr_reference') }}">
            </div>

            <div class="col-12">
                <label class="form-label fw-semibold">Notes for IT</label>
                <textarea name="notes" class="form-control" rows="3" maxlength="1000"
                          placeholder="Anything IT should know — handover, disputes, early cut-off…">{{ old('notes') }}</textarea>
            </div>
        </div>
    </div>

    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
        <a href="{{ route('portal.hr.offboarding.index') }}" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-danger">
            <i class="bi bi-send me-1"></i>Raise Termination Request
        </button>
    </div>
</form>
@endsection
