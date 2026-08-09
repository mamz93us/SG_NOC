@extends('layouts.hr')

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
                            <div class="form-text mb-0">
                                Work email: <strong id="upnPreview" class="text-primary">—</strong>
                                <span id="upnSpinner" class="spinner-border spinner-border-sm text-muted ms-1 d-none"
                                      style="width:.7rem;height:.7rem" role="status"></span>
                            </div>
                            <div id="upnStatus" class="small mt-1"></div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select form-select-sm" required>
                                <option value="">— Select —</option>
                                <option value="female" @selected(old('gender') === 'female')>Female</option>
                                <option value="male" @selected(old('gender') === 'male')>Male</option>
                            </select>
                            <div class="form-text">Determines which Azure groups are auto-assigned.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Mobile Phone</label>
                            <input type="text" name="mobile_phone" class="form-control form-control-sm" value="{{ old('mobile_phone') }}" placeholder="+966XXXXXXXXX">
                            <div class="form-text">Used on Azure profile.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Oracle Employee ID</label>
                            <input type="text" name="oracle_emp_no" class="form-control form-control-sm" value="{{ old('oracle_emp_no') }}" placeholder="e.g. 10432" maxlength="50">
                            <div class="form-text">The employee number from Oracle HR — it becomes this person's reference everywhere in NOC.</div>
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
                    </div>

                    {{-- ── Reporting line ── --}}
                    <p class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">
                        <i class="bi bi-diagram-3 me-1"></i>Reporting Line
                    </p>
                    <div class="alert alert-info py-2 px-3 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        After IT approval, the <strong>manager</strong> receives a form link to choose laptop type,
                        extension, floor, internet level, and group memberships. Both people are recorded on
                        the new employee's record.
                    </div>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            @include('portal.hr._employee_picker', [
                                'pickerId'     => 'mgr',
                                'selected'     => null,
                                'label'        => 'Reporting Manager',
                                'required'     => true,
                                'help'         => 'Receives the setup form. Must be an existing employee with a work email.',
                                'hiddenFields' => ['manager_id' => 'id'],
                            ])
                        </div>
                        <div class="col-md-6">
                            @include('portal.hr._employee_picker', [
                                'pickerId'     => 'sup',
                                'selected'     => null,
                                'label'        => 'Direct Supervisor',
                                'required'     => false,
                                'help'         => 'Optional. Recorded on the employee record; does not receive the form.',
                                'hiddenFields' => ['supervisor_id' => 'id'],
                            ])
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
                HR never sets or sees the new hire's password. IT generates a secure one at
                provisioning time and shares it with the employee only.
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
// Live availability check. This is NOT a cosmetic string render — it asks the
// server, which runs the same buildUPN() that provisioning will run, so the
// address shown here (including any collision suffix) is what actually gets
// created. It also surfaces the duplicate-display-name check that would
// otherwise throw at provisioning time, long after IT had approved.
(function () {
    const checkUrl    = @json(route('portal.hr.onboarding.check-availability'));
    const firstNameEl = document.getElementById('firstName');
    const lastNameEl  = document.getElementById('lastName');
    const domainEl    = document.getElementById('domainSelect');
    const preview     = document.getElementById('upnPreview');
    const spinner     = document.getElementById('upnSpinner');
    const status      = document.getElementById('upnStatus');

    let timer = null;
    let controller = null;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function domainValue() {
        return domainEl.tagName === 'SELECT'
            ? (domainEl.options[domainEl.selectedIndex]?.value || '')
            : domainEl.value.trim();
    }

    function reset() {
        preview.textContent = '—';
        status.innerHTML = '';
        spinner.classList.add('d-none');
    }

    function check() {
        const first  = firstNameEl.value.trim();
        const last   = lastNameEl.value.trim();
        const domain = domainValue();

        if (!first || !last || !domain) { reset(); return; }

        spinner.classList.remove('d-none');

        if (controller) controller.abort();
        controller = new AbortController();

        const params = new URLSearchParams({
            first_name: first, last_name: last, upn_domain: domain
        });

        fetch(checkUrl + '?' + params.toString(), {
            headers: { 'Accept': 'application/json' },
            signal: controller.signal,
        })
        .then(r => r.ok ? r.json() : Promise.reject(r.status))
        .then(d => {
            spinner.classList.add('d-none');
            preview.textContent = d.upn || '—';

            const notes = [];

            if (d.error) {
                notes.push('<span class="text-danger"><i class="bi bi-x-circle me-1"></i>' +
                           escapeHtml(d.error) + '</span>');
            } else if (d.exact_taken) {
                notes.push('<span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>' +
                           'The plain address is already in use — this hire would get <strong>' +
                           escapeHtml(d.upn) + '</strong>.</span>');
            } else {
                notes.push('<span class="text-success"><i class="bi bi-check-circle me-1"></i>Available.</span>');
            }

            if (d.display_name_taken) {
                notes.push('<span class="text-danger d-block mt-1"><i class="bi bi-exclamation-octagon me-1"></i>' +
                           'Someone with this exact full name already exists' +
                           (d.existing_upn ? ' (' + escapeHtml(d.existing_upn) + ')' : '') +
                           '. Provisioning will be <strong>rejected</strong> — add a middle name, or check this is not a duplicate request.</span>');
            }

            status.innerHTML = notes.join('');
        })
        .catch(e => {
            if (e && e.name === 'AbortError') return;
            spinner.classList.add('d-none');
            status.innerHTML = '<span class="text-muted">Could not check availability right now.</span>';
        });
    }

    [firstNameEl, lastNameEl, domainEl].forEach(el => {
        if (!el) return;
        const debounced = () => { clearTimeout(timer); timer = setTimeout(check, 400); };
        el.addEventListener('input', debounced);
        el.addEventListener('change', debounced);
    });

    check();
})();
</script>
@endpush

@endsection
