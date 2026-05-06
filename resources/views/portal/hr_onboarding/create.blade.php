@extends('layouts.portal')

@section('title', 'New Onboarding Request')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-plus-fill me-2 text-primary"></i>New Onboarding Request
        </h4>
        <small class="text-muted">Submit a new hire for IT to provision</small>
    </div>
    <a href="{{ route('portal.hr.onboarding.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

@if ($errors->any())
<div class="alert alert-danger">
    <strong><i class="bi bi-exclamation-circle me-1"></i>Please fix the following:</strong>
    <ul class="mb-0 mt-1 ps-3">
        @foreach ($errors->all() as $err)
            <li>{{ $err }}</li>
        @endforeach
    </ul>
</div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('portal.hr.onboarding.store') }}">
                    @csrf

                    {{-- ── Employee Identity ── --}}
                    <p class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">
                        <i class="bi bi-person me-1"></i>Employee Identity
                    </p>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" id="firstName" class="form-control form-control-sm" value="{{ old('first_name') }}" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" id="lastName" class="form-control form-control-sm" value="{{ old('last_name') }}" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label small fw-semibold">Email Domain <span class="text-danger">*</span></label>
                            @if($upnDomains->count() > 0)
                                <select name="upn_domain" id="domainSelect" class="form-select form-select-sm" required>
                                    @foreach($upnDomains as $d)
                                    <option value="{{ $d->domain }}"
                                        {{ old('upn_domain', $upnDomains->firstWhere('is_primary', true)?->domain) === $d->domain ? 'selected' : '' }}>
                                        {{ $d->domain }}{{ $d->is_primary ? ' (primary)' : '' }}
                                    </option>
                                    @endforeach
                                </select>
                            @else
                                <input type="text" name="upn_domain" id="domainSelect" class="form-control form-control-sm"
                                       value="{{ old('upn_domain', $settings->upn_domain ?? '') }}"
                                       placeholder="e.g. samirgroup.com" required>
                                <div class="form-text text-warning">No domains configured — ask IT to set one in Settings → Domains.</div>
                            @endif
                            <div class="form-text">Preview: <strong id="upnPreview" class="text-primary">—</strong></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone</label>
                            <input type="text" name="mobile_phone" class="form-control form-control-sm" value="{{ old('mobile_phone') }}" placeholder="+966XXXXXXXXX">
                            <div class="form-text">Used on Azure profile.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">HR Reference</label>
                            <input type="text" name="hr_reference" class="form-control form-control-sm" value="{{ old('hr_reference') }}" placeholder="e.g. HR-2026-0045">
                            <div class="form-text">Internal HR ticket / employee number.</div>
                        </div>
                    </div>

                    {{-- ── Employment Details ── --}}
                    <p class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">
                        <i class="bi bi-briefcase me-1"></i>Employment Details
                    </p>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Job Title</label>
                            <input type="text" name="job_title" class="form-control form-control-sm" value="{{ old('job_title') }}" placeholder="e.g. Network Engineer">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Department</label>
                            <select name="department_id" class="form-select form-select-sm">
                                <option value="">— Select Department —</option>
                                @foreach($departments as $dept)
                                <option value="{{ $dept->id }}" {{ old('department_id') == $dept->id ? 'selected' : '' }}>{{ $dept->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Branch</label>
                            <select name="branch_id" class="form-select form-select-sm">
                                <option value="">— Select Branch —</option>
                                @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Controls extension range, UCM server, and office location.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Suggested Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="suggested_start_date" class="form-control form-control-sm"
                                   value="{{ old('suggested_start_date') }}"
                                   min="{{ now()->toDateString() }}" required>
                            <div class="form-text">The date IT should have accounts ready by.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">Initial Password</label>
                            <input type="text" name="initial_password" class="form-control form-control-sm" value="{{ old('initial_password') }}" placeholder="Leave blank for IT to auto-generate">
                            <div class="form-text">Leave blank — IT will generate a secure password and email the new hire.</div>
                        </div>
                    </div>

                    {{-- ── Manager Contact ── --}}
                    <p class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">
                        <i class="bi bi-envelope me-1"></i>Reporting Manager
                        <span class="badge bg-warning text-dark ms-1" style="font-size:.65rem">Required</span>
                    </p>
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        After IT approval, the manager will receive a form link to choose laptop type,
                        extension, floor, internet level, and group memberships.
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Manager Email <span class="text-danger">*</span></label>
                            <input type="email" name="manager_email" class="form-control form-control-sm" value="{{ old('manager_email') }}" placeholder="manager@company.com" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Manager Name</label>
                            <input type="text" name="manager_name" class="form-control form-control-sm" value="{{ old('manager_name') }}" placeholder="e.g. Ahmed Al-Rashidi">
                        </div>
                    </div>

                    {{-- ── Notes ── --}}
                    <p class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">
                        <i class="bi bi-chat-left-text me-1"></i>Notes for IT
                    </p>
                    <div class="mb-4">
                        <textarea name="description" class="form-control form-control-sm" rows="3"
                                  placeholder="Any special requirements (licenses, group memberships, equipment preferences)...">{{ old('description') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Submit Request
                        </button>
                        <a href="{{ route('portal.hr.onboarding.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Sidebar info --}}
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-info-circle me-1"></i>What happens next?
            </div>
            <div class="card-body small text-muted">
                <ol class="ps-3 mb-0">
                    <li class="mb-2"><strong>You submit</strong> this form with the new hire's basic details.</li>
                    <li class="mb-2"><strong>IT reviews and approves</strong> the onboarding request.</li>
                    <li class="mb-2">An email goes to the <strong>reporting manager</strong> with a link to pick laptop, extension, floor, internet level, and group memberships.</li>
                    <li class="mb-2">After the manager submits, <strong>IT provisions</strong> the Azure account, extension, licenses, and sends welcome emails.</li>
                    <li class="mb-0">You can <a href="{{ route('portal.hr.onboarding.index') }}">track progress</a> here anytime.</li>
                </ol>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-shield-check me-1"></i>Privacy note
            </div>
            <div class="card-body small text-muted">
                Initial passwords are never stored in HR records — IT generates a secure password
                and shares it with the new employee only.
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const firstNameEl = document.getElementById('firstName');
const lastNameEl  = document.getElementById('lastName');
const domainEl    = document.getElementById('domainSelect');
const upnPreview  = document.getElementById('upnPreview');

function sanitize(s) {
    return s.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]/g, '');
}

function renderUpn() {
    const first = sanitize(firstNameEl.value.trim());
    const last  = sanitize(lastNameEl.value.trim());
    const domain = (domainEl.tagName === 'SELECT')
        ? (domainEl.options[domainEl.selectedIndex]?.value || '')
        : domainEl.value;
    if (first && last && domain) {
        upnPreview.textContent = `${first}.${last}@${domain}`;
    } else {
        upnPreview.textContent = '—';
    }
}

[firstNameEl, lastNameEl, domainEl].forEach(el => {
    if (!el) return;
    el.addEventListener('input', renderUpn);
    el.addEventListener('change', renderUpn);
});
renderUpn();
</script>
@endpush
@endsection
