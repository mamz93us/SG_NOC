@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-plus-circle-fill me-2 text-primary"></i>New Workflow Request</h4>
        <small class="text-muted">Submit a request for approval and processing</small>
    </div>
    <a href="{{ route('admin.workflows.my-requests') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST" action="{{ route('admin.workflows.store') }}" id="workflowForm">
                    @csrf

                    {{-- Type selector --}}
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Request Type <span class="text-danger">*</span></label>
                        <div class="row g-2">
                            @foreach($types as $val => $label)
                            <div class="col-6 col-md-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="type" id="type_{{ $val }}" value="{{ $val }}" {{ ($type ?? old('type')) === $val ? 'checked' : '' }} required>
                                    <label class="form-check-label" for="type_{{ $val }}">{{ $label }}</label>
                                </div>
                            </div>
                            @endforeach
                        </div>
                        @error('type')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Request Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="titleInput" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" placeholder="Brief description of this request" required>
                            @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Additional context or details...">{{ old('description') }}</textarea>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Branch</label>
                            <select name="branch_id" id="branchSelect" class="form-select">
                                <option value="">— Select Branch —</option>
                                @foreach($branches as $branch)
                                <option value="{{ $branch->id }}" {{ old('branch_id') == $branch->id ? 'selected' : '' }}>{{ $branch->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Dynamic fields for create_user --}}
                    <div id="create_user_fields" class="mt-4 d-none">
                        <hr>
                        <h6 class="fw-semibold"><i class="bi bi-person-plus-fill me-1 text-primary"></i>New User Details</h6>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                                <input type="text" name="first_name" id="firstName" class="form-control form-control-sm" value="{{ old('first_name') }}">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Last Name <span class="text-danger">*</span></label>
                                <input type="text" name="last_name" id="lastName" class="form-control form-control-sm" value="{{ old('last_name') }}">
                            </div>

                            {{-- Email Domain picker --}}
                            <div class="col-12">
                                <label class="form-label small fw-semibold">Email Domain <span class="text-danger">*</span></label>
                                @if($upnDomains->count() > 0)
                                <select name="upn_domain" id="domainSelect" class="form-select form-select-sm">
                                    @foreach($upnDomains as $d)
                                    <option value="{{ $d->domain }}"
                                            {{ old('upn_domain', $upnDomains->firstWhere('is_primary', true)?->domain) === $d->domain ? 'selected' : '' }}>
                                        {{ $d->domain }}{{ $d->is_primary ? ' (primary)' : '' }}
                                    </option>
                                    @endforeach
                                </select>
                                <div class="form-text">The <code>@</code>domain part of the user's email address. Manage domains in
                                    <a href="{{ route('admin.settings.domains') }}" target="_blank">Settings → Domains</a>.
                                </div>
                                @else
                                <input type="text" name="upn_domain" id="domainSelect" class="form-control form-control-sm"
                                       value="{{ old('upn_domain', $settings->upn_domain) }}"
                                       placeholder="e.g. samirgroup.com">
                                <div class="form-text">No domains configured yet.
                                    <a href="{{ route('admin.settings.domains') }}" target="_blank">Add domains in Settings →</a>
                                </div>
                                @endif
                            </div>

                            <div class="col-md-6">
                                <label class="form-label small fw-semibold">Job Title</label>
                                <input type="text" name="job_title" class="form-control form-control-sm" value="{{ old('job_title') }}">
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
                                <label class="form-label small fw-semibold">Initial Password</label>
                                <input type="text" name="initial_password" id="initialPassword" class="form-control form-control-sm" placeholder="Auto-generated if blank" value="{{ old('initial_password') }}">
                                <div class="form-text">Leave blank to auto-generate a secure password.</div>
                            </div>
                        </div>
                    </div>

                    {{-- Generic other details field --}}
                    <div id="other_fields" class="mt-4 d-none">
                        <hr><h6 class="text-muted fw-semibold">Additional Information</h6>
                        <textarea name="details" class="form-control form-control-sm" rows="4" placeholder="Provide any relevant details...">{{ old('details') }}</textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Submit Request
                        </button>
                        <a href="{{ route('admin.workflows.my-requests') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- Sidebar --}}
    <div class="col-12 col-lg-4">

        {{-- Provisioning Preview (create_user only) --}}
        <div id="provisioningPreview" class="card shadow-sm border-0 border-primary mb-3 d-none">
            <div class="card-header bg-primary text-white">
                <strong><i class="bi bi-eye-fill me-1"></i>Provisioning Preview</strong>
                <small class="ms-1 opacity-75">live</small>
            </div>
            <div class="card-body small">
                <dl class="row mb-2">
                    <dt class="col-5 text-muted">Email (UPN)</dt>
                    <dd class="col-7 fw-semibold text-break" id="previewUpn">
                        <span class="text-muted fst-italic">type name above…</span>
                    </dd>

                    <dt class="col-5 text-muted">Extension Range</dt>
                    <dd class="col-7" id="previewRange">
                        <span class="text-muted fst-italic">loading…</span>
                    </dd>

                    <dt class="col-5 text-muted">UCM Server</dt>
                    <dd class="col-7" id="previewUcm">
                        <span class="text-muted fst-italic">—</span>
                    </dd>

                    <dt class="col-5 text-muted">Default Licenses</dt>
                    <dd class="col-7" id="previewLicense">
                        @php
                            $previewSkus = $settings->graph_default_license_skus
                                ?? ($settings->graph_default_license_sku ? [$settings->graph_default_license_sku] : []);
                        @endphp
                        @if(!empty($previewSkus))
                        <span class="text-muted fst-italic small">loading names…</span>
                        @else
                        <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Not set</span>
                        @endif
                    </dd>
                </dl>
                @if(empty($previewSkus))
                <div class="alert alert-warning py-1 px-2 small mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i>No default license configured.
                    <a href="{{ route('admin.settings.provisioning-licenses') }}" class="alert-link">Set one →</a>
                </div>
                @endif
            </div>
        </div>

        {{-- Approval Process info --}}
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-info-circle me-1"></i>Approval Process</strong></div>
            <div class="card-body small text-muted">
                <p class="mb-2">Approval chains are configured in <a href="{{ route('admin.workflow-templates.index') }}">Workflow Templates</a>. Each request type follows its defined chain before executing.</p>
                <p class="mb-0"><i class="bi bi-lightning-fill text-warning me-1"></i>For <strong>Create User</strong>: after final approval the account is created immediately — Azure AD user, license(s), UCM extension, and employee profile are all provisioned in one step.</p>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const PREVIEW_URL  = '{{ route('admin.workflows.preview-user') }}';
const CSRF_TOKEN   = '{{ csrf_token() }}';

const firstNameEl  = document.getElementById('firstName');
const lastNameEl   = document.getElementById('lastName');
const branchEl     = document.getElementById('branchSelect');
const domainEl     = document.getElementById('domainSelect');
const titleEl      = document.getElementById('titleInput');
const previewCard  = document.getElementById('provisioningPreview');
const previewUpn   = document.getElementById('previewUpn');
const previewRange = document.getElementById('previewRange');
const previewUcm   = document.getElementById('previewUcm');
const previewLic   = document.getElementById('previewLicense');

let previewTimeout = null;

// ── Toggle field sections based on selected type ──
document.querySelectorAll('input[name="type"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('create_user_fields').classList.add('d-none');
        document.getElementById('other_fields').classList.add('d-none');
        previewCard.classList.add('d-none');
        if (this.value === 'create_user') {
            document.getElementById('create_user_fields').classList.remove('d-none');
            previewCard.classList.remove('d-none');
            updatePreview();
            if (!titleEl.value.trim()) titleEl.value = 'Create New User';
        } else if (this.value === 'other') {
            document.getElementById('other_fields').classList.remove('d-none');
        }
    });
});
// Trigger on page load if type pre-selected
const checked = document.querySelector('input[name="type"]:checked');
if (checked) checked.dispatchEvent(new Event('change'));

// ── Live UPN preview (JS-only, no AJAX) ──
function sanitizePart(s) {
    return s.toLowerCase().replace(/[^a-z0-9]/g, '');
}

function currentDomain() {
    if (!domainEl) return 'example.com';
    return (domainEl.value || domainEl.tagName === 'SELECT'
        ? domainEl.options?.[domainEl.selectedIndex]?.value
        : domainEl.value) || 'example.com';
}

function updateUpnPreview() {
    const first  = sanitizePart(firstNameEl?.value?.trim() || '');
    const last   = sanitizePart(lastNameEl?.value?.trim()  || '');
    const domain = currentDomain();
    if (first && last) {
        previewUpn.innerHTML = `<span class="text-primary">${first}.${last}@${domain}</span>`;
    } else if (first || last) {
        previewUpn.innerHTML = `<span class="text-muted fst-italic">${first || '…'}.${last || '…'}@${domain}</span>`;
    } else {
        previewUpn.innerHTML = '<span class="text-muted fst-italic">type name above…</span>';
    }
}

// ── AJAX preview for extension range + UCM (debounced) ──
function updatePreview() {
    updateUpnPreview();
    clearTimeout(previewTimeout);
    previewTimeout = setTimeout(fetchRangePreview, 300);
}

function fetchRangePreview() {
    const params = new URLSearchParams({
        first_name: firstNameEl?.value?.trim() || '',
        last_name:  lastNameEl?.value?.trim()  || '',
        branch_id:  branchEl?.value || '',
        domain:     currentDomain(),
    });
    fetch(`${PREVIEW_URL}?${params}`, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        // UPN (more accurate from server, uses same sanitise logic)
        if (data.upn) {
            previewUpn.innerHTML = `<span class="text-primary">${data.upn}</span>`;
        }
        // Range
        if (data.range) {
            previewRange.innerHTML = `<span class="fw-semibold">${data.range.start} – ${data.range.end}</span>`;
        }
        // UCM
        previewUcm.innerHTML = data.ucmName
            ? `<span class="fw-semibold">${data.ucmName}</span>`
            : '<span class="text-muted">— global default —</span>';
        // Licenses — show friendly names (licenseData = [{sku, name}, ...])
        if (data.licenseData && data.licenseData.length > 0) {
            previewLic.innerHTML = '<ul class="list-unstyled mb-0">'
                + data.licenseData.map(l =>
                    `<li class="fw-semibold">${l.name}<br><code class="text-muted small" style="font-size:.7rem">${l.sku}</code></li>`
                  ).join('')
                + '</ul>';
        } else {
            previewLic.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Not set</span>';
        }
    })
    .catch(() => {
        previewRange.innerHTML = '<span class="text-muted">—</span>';
        previewUcm.innerHTML   = '<span class="text-muted">—</span>';
    });
}

// Bind events
if (firstNameEl) firstNameEl.addEventListener('input', updatePreview);
if (lastNameEl)  lastNameEl.addEventListener('input', updatePreview);
if (branchEl)    branchEl.addEventListener('change', updatePreview);
if (domainEl)    domainEl.addEventListener('change', updatePreview);
</script>
@endpush
@endsection
