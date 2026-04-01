@extends('layouts.admin')
@section('title', 'HR API Documentation')

@section('content')

{{-- ═══════════════════════════════════════════════════════
     PAGE HEADER
═══════════════════════════════════════════════════════ --}}
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-file-earmark-code-fill me-2 text-primary"></i>HR API Documentation
        </h4>
        <small class="text-muted">Integrate your HR system with SG NOC for automated user provisioning</small>
    </div>
    <div>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle fs-6 px-3 py-2">
            <i class="bi bi-hdd-stack me-1"></i>
            Base URL: <code class="text-primary ms-1">{{ $baseUrl }}/api/hr</code>
        </span>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════
     AUTHENTICATION CARD
═══════════════════════════════════════════════════════ --}}
<div class="card border-warning mb-4 shadow-sm">
    <div class="card-header bg-warning bg-opacity-10 border-warning d-flex align-items-center gap-2">
        <i class="bi bi-shield-lock-fill text-warning fs-5"></i>
        <span class="fw-semibold text-warning-emphasis">Authentication</span>
        <span class="badge bg-warning text-dark ms-auto">Required on every request</span>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-start">
            <div class="col-lg-8">
                <p class="mb-2 text-muted small">All API requests must include the following HTTP header:</p>
                <table class="table table-sm table-bordered mb-3">
                    <thead class="table-light">
                        <tr>
                            <th style="width:220px">Header</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>X-HR-Api-Key</code></td>
                            <td>
                                @if($hrApiKeys->isNotEmpty())
                                    <span class="font-monospace text-success fw-semibold">
                                        {{ $hrApiKeys->first()->key_prefix }}••••••••••••••••••••••••••••••••••
                                    </span>
                                    <span class="badge bg-success ms-2">{{ $hrApiKeys->count() }} active key(s)</span>
                                @elseif($legacyKey)
                                    <span class="font-monospace text-warning">{{ Str::mask($legacyKey, '*', 4) }}</span>
                                    <span class="badge bg-warning text-dark ms-2">Legacy .env key</span>
                                @else
                                    <span class="text-danger fw-semibold">⚠ No active keys — generate one below</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td><code>Content-Type</code></td>
                            <td><code>application/json</code></td>
                        </tr>
                        <tr>
                            <td><code>Accept</code></td>
                            <td><code>application/json</code></td>
                        </tr>
                    </tbody>
                </table>

                @if($hrApiKeys->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Key Name</th>
                                <th>Prefix</th>
                                <th>Last Used</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($hrApiKeys as $k)
                            <tr>
                                <td>{{ $k->name }}</td>
                                <td><code class="font-monospace">{{ $k->key_prefix }}…</code></td>
                                <td class="text-muted small">{{ $k->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
            <div class="col-lg-4">
                <div class="alert alert-warning mb-2 small">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Keys are database-managed.</strong> Generate, revoke, and rotate keys at any time from the
                    <a href="/admin/hr-api-keys" class="alert-link">HR API Keys</a> page.
                    Raw keys are shown <strong>only once</strong> at creation.
                </div>
                @if($legacyKey)
                <div class="alert alert-secondary mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Legacy key detected</strong> in <code>.env</code> (HR_API_KEY).
                    This still works but will be removed in a future release.
                    Migrate to a database key when possible.
                </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════
     ENDPOINTS
═══════════════════════════════════════════════════════ --}}
<h5 class="fw-bold mb-3"><i class="bi bi-send-fill me-2 text-primary"></i>Endpoints</h5>

<div class="accordion mb-4 shadow-sm" id="endpointsAccordion">

    {{-- ─────────────────────────────────────────────────────
         ENDPOINT A: POST /api/hr/onboarding
    ───────────────────────────────────────────────────── --}}
    <div class="accordion-item border">
        <h2 class="accordion-header">
            <button class="accordion-button fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#endpointOnboarding"
                    aria-expanded="true" aria-controls="endpointOnboarding">
                <span class="badge bg-success me-3 px-2 py-1" style="font-size:.75rem">POST</span>
                <code>/api/hr/onboarding</code>
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">Create employee onboarding workflow</span>
            </button>
        </h2>
        <div id="endpointOnboarding" class="accordion-collapse collapse show" data-bs-parent="#endpointsAccordion">
            <div class="accordion-body">
                <p class="text-muted">
                    Creates a new user onboarding workflow. The system will provision an Active Directory account,
                    assign a UCM extension, and send a welcome email.
                </p>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-success"></i>Request Body</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"first_name"</span>:      <span class="text-success">"Ahmed"</span>,          <span class="text-secondary">// required</span>
  <span class="text-warning">"last_name"</span>:       <span class="text-success">"Karimi"</span>,         <span class="text-secondary">// required</span>
  <span class="text-warning">"job_title"</span>:       <span class="text-success">"Software Engineer"</span>,
  <span class="text-warning">"branch_id"</span>:       <span class="text-info">1</span>,              <span class="text-secondary">// required</span>
  <span class="text-warning">"department_id"</span>:   <span class="text-info">3</span>,
  <span class="text-warning">"department_name"</span>: <span class="text-success">"Engineering"</span>,
  <span class="text-warning">"start_date"</span>:      <span class="text-success">"2026-04-01"</span>,
  <span class="text-warning">"manager_email"</span>:   <span class="text-success">"manager@company.com"</span>,
  <span class="text-warning">"upn_domain"</span>:      <span class="text-success">"company.com"</span>,
  <span class="text-warning">"hr_reference"</span>:    <span class="text-success">"HR-2026-0045"</span>,
  <span class="text-warning">"mobile_phone"</span>:    <span class="text-success">"+20XXXXXXXXX"</span>,
  <span class="text-warning">"notes"</span>:           <span class="text-success">"VIP employee"</span>
}</code></pre>

                        <div class="mb-3">
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">first_name</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">last_name</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">branch_id</span>
                            <small class="text-muted ms-1">— required fields</small>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-circle me-1 text-primary"></i>
                            Response <span class="badge bg-success ms-1">201 Created</span>
                        </h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"ok"</span>:          <span class="text-info">true</span>,
  <span class="text-warning">"workflow_id"</span>: <span class="text-info">42</span>,
  <span class="text-warning">"status"</span>:      <span class="text-success">"pending"</span>,
  <span class="text-warning">"message"</span>:     <span class="text-success">"Onboarding workflow created for Ahmed Karimi."</span>
}</code></pre>

                        <h6 class="fw-semibold mb-2 mt-3"><i class="bi bi-terminal me-1 text-secondary"></i>cURL Example</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code><span class="text-info">curl</span> -X POST {{ $baseUrl }}/api/hr/onboarding \
  -H <span class="text-success">"X-HR-Api-Key: YOUR_KEY"</span> \
  -H <span class="text-success">"Content-Type: application/json"</span> \
  -d <span class="text-success">'{"first_name":"Ahmed","last_name":"Karimi","branch_id":1}'</span></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────
         ENDPOINT B: POST /api/hr/offboarding
    ───────────────────────────────────────────────────── --}}
    <div class="accordion-item border">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#endpointOffboarding"
                    aria-expanded="false" aria-controls="endpointOffboarding">
                <span class="badge bg-danger me-3 px-2 py-1" style="font-size:.75rem">POST</span>
                <code>/api/hr/offboarding</code>
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">Create employee offboarding workflow</span>
            </button>
        </h2>
        <div id="endpointOffboarding" class="accordion-collapse collapse" data-bs-parent="#endpointsAccordion">
            <div class="accordion-body">
                <p class="text-muted">
                    Creates an employee offboarding workflow. Sends a manager approval email. After the manager confirms,
                    the system terminates the employee record, flags their assets, and logs a mailbox-forwarding note
                    for the Exchange administrator to process manually.
                </p>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Identity:</strong> Use <code>upn</code> (the employee's work email) as the primary identifier.
                    Optionally pass <code>employee_id</code> (SG NOC internal ID) for faster lookup.
                    <code>azure_id</code> is <strong>not used</strong> — this system uses on-premise identity only.
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-danger"></i>Request Body</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"employee_name"</span>: <span class="text-success">"Ahmed Karimi"</span>,     <span class="text-secondary">// required</span>
  <span class="text-warning">"upn"</span>:            <span class="text-success">"ahmed.karimi@company.com"</span>, <span class="text-secondary">// required*</span>
  <span class="text-warning">"employee_id"</span>:   <span class="text-info">17</span>,              <span class="text-secondary">// optional — faster lookup</span>
  <span class="text-warning">"last_day"</span>:       <span class="text-success">"2026-04-30"</span>,
  <span class="text-warning">"reason"</span>:         <span class="text-success">"resignation"</span>,
  <span class="text-warning">"manager_email"</span>:  <span class="text-success">"manager@company.com"</span>, <span class="text-secondary">// required</span>
  <span class="text-warning">"manager_name"</span>:   <span class="text-success">"Sarah Smith"</span>,
  <span class="text-warning">"forward_to"</span>:     <span class="text-success">"team@company.com"</span>,   <span class="text-secondary">// Exchange admin note</span>
  <span class="text-warning">"branch_id"</span>:      <span class="text-info">1</span>,
  <span class="text-warning">"hr_reference"</span>:   <span class="text-success">"HR-OFF-2026-012"</span>
}</code></pre>

                        <div class="mb-3">
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">employee_name</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">upn</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">manager_email</span>
                            <small class="text-muted ms-1">— required fields</small>
                        </div>
                        <div class="alert alert-secondary small py-2">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>forward_to</strong> is noted in the workflow log. The Exchange administrator
                            must apply the mailbox forwarding rule manually.
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-circle me-1 text-primary"></i>
                            Response <span class="badge bg-success ms-1">201 Created</span>
                        </h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"ok"</span>:          <span class="text-info">true</span>,
  <span class="text-warning">"workflow_id"</span>: <span class="text-info">43</span>,
  <span class="text-warning">"status"</span>:      <span class="text-success">"manager_input_pending"</span>,
  <span class="text-warning">"message"</span>:     <span class="text-success">"Offboarding workflow created. Manager approval email sent."</span>
}</code></pre>

                        <h6 class="fw-semibold mb-2 mt-3"><i class="bi bi-terminal me-1 text-secondary"></i>cURL Example</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code><span class="text-info">curl</span> -X POST {{ $baseUrl }}/api/hr/offboarding \
  -H <span class="text-success">"X-HR-Api-Key: YOUR_KEY"</span> \
  -H <span class="text-success">"Content-Type: application/json"</span> \
  -d <span class="text-success">'{"employee_name":"Ahmed Karimi","upn":"ahmed.karimi@company.com","manager_email":"manager@company.com"}'</span></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ─────────────────────────────────────────────────────
         ENDPOINT C: POST /api/hr/group-assignment
    ───────────────────────────────────────────────────── --}}
    <div class="accordion-item border">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#endpointGroupAssignment"
                    aria-expanded="false" aria-controls="endpointGroupAssignment">
                <span class="badge bg-primary me-3 px-2 py-1" style="font-size:.75rem">POST</span>
                <code>/api/hr/group-assignment</code>
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">Log group assignments for a user</span>
            </button>
        </h2>
        <div id="endpointGroupAssignment" class="accordion-collapse collapse" data-bs-parent="#endpointsAccordion">
            <div class="accordion-body">
                <p class="text-muted">
                    Logs the requested group assignments for an employee. The workflow is recorded in SG NOC
                    for the IT administrator to apply via Exchange / Active Directory as needed.
                </p>
                <div class="alert alert-info small py-2 mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Identity:</strong> <code>upn</code> (work email) is the <strong>only</strong> required identifier.
                    This system does not push to Azure AD — group assignments are logged for manual processing.
                </div>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-primary"></i>Request Body</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"upn"</span>:         <span class="text-success">"ahmed.karimi@company.com"</span>, <span class="text-secondary">// required</span>
  <span class="text-warning">"group_names"</span>: [
    <span class="text-success">"All-Sales-Staff"</span>,
    <span class="text-success">"Cairo-Office"</span>
  ]                                  <span class="text-secondary">// required, non-empty</span>
}</code></pre>

                        <div class="mb-3">
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">upn</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">group_names</span>
                            <small class="text-muted ms-1">— required fields</small>
                        </div>
                        <div class="alert alert-secondary small py-2">
                            <i class="bi bi-info-circle me-1"></i>
                            The IT admin will be notified of the requested groups.
                            Apply them via <strong>Active Directory Users & Computers</strong> or Exchange admin.
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-circle me-1 text-primary"></i>
                            Response <span class="badge bg-primary ms-1">200 OK</span>
                        </h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"ok"</span>:          <span class="text-info">true</span>,
  <span class="text-warning">"workflow_id"</span>: <span class="text-info">44</span>,
  <span class="text-warning">"status"</span>:      <span class="text-success">"completed"</span>,
  <span class="text-warning">"message"</span>:     <span class="text-success">"Group assignment workflow created."</span>
}</code></pre>

                        <h6 class="fw-semibold mb-2 mt-3"><i class="bi bi-terminal me-1 text-secondary"></i>cURL Example</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code><span class="text-info">curl</span> -X POST {{ $baseUrl }}/api/hr/group-assignment \
  -H <span class="text-success">"X-HR-Api-Key: YOUR_KEY"</span> \
  -H <span class="text-success">"Content-Type: application/json"</span> \
  -d <span class="text-success">'{"upn":"ahmed.karimi@company.com","group_names":["All-Sales-Staff"]}'</span></code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>{{-- /accordion --}}

{{-- ═══════════════════════════════════════════════════════
     RESPONSE CODES
═══════════════════════════════════════════════════════ --}}
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-light d-flex align-items-center gap-2">
        <i class="bi bi-reception-4 text-primary"></i>
        <span class="fw-semibold">HTTP Response Codes</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:110px">Code</th>
                    <th>Meaning</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><span class="badge bg-success">201</span></td>
                    <td>Created — workflow created successfully</td>
                </tr>
                <tr>
                    <td><span class="badge bg-primary">200</span></td>
                    <td>OK — request processed successfully</td>
                </tr>
                <tr>
                    <td><span class="badge bg-warning text-dark">401</span></td>
                    <td>Unauthorized — <code>X-HR-Api-Key</code> missing, invalid, or revoked</td>
                </tr>
                <tr>
                    <td><span class="badge bg-warning text-dark">422</span></td>
                    <td>Validation Error — a required field is missing or invalid</td>
                </tr>
                <tr>
                    <td><span class="badge bg-warning text-dark">429</span></td>
                    <td>Too Many Requests — rate limit exceeded (5 req/min per IP for offboarding)</td>
                </tr>
                <tr>
                    <td><span class="badge bg-danger">500</span></td>
                    <td>Server Error — unexpected error; check server logs</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════
     BRANCH & DEPARTMENT REFERENCE
═══════════════════════════════════════════════════════ --}}
<div class="card mb-4 shadow-sm">
    <div class="card-header bg-light d-flex align-items-center gap-2">
        <i class="bi bi-table text-primary"></i>
        <span class="fw-semibold">Branch &amp; Department Reference</span>
        <span class="text-muted fw-normal small ms-1">— Use these IDs in your API requests</span>
    </div>
    <div class="card-body">
        <div class="row g-4">
            {{-- Branches --}}
            <div class="col-lg-6">
                <h6 class="fw-semibold mb-2">
                    <i class="bi bi-building me-1 text-secondary"></i>Branches
                </h6>
                @if($branches->isEmpty())
                    <p class="text-muted small fst-italic">No branches found.</p>
                @else
                    <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
                        <table class="table table-sm table-bordered table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:80px">ID</th>
                                    <th>Branch Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($branches as $branch)
                                <tr>
                                    <td><code>{{ $branch->id }}</code></td>
                                    <td>{{ $branch->name }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- Departments --}}
            <div class="col-lg-6">
                <h6 class="fw-semibold mb-2">
                    <i class="bi bi-diagram-3 me-1 text-secondary"></i>Departments
                </h6>
                @if($departments->isEmpty())
                    <p class="text-muted small fst-italic">No departments found.</p>
                @else
                    <div class="table-responsive" style="max-height:320px; overflow-y:auto;">
                        <table class="table table-sm table-bordered table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:80px">ID</th>
                                    <th>Department Name</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($departments as $dept)
                                <tr>
                                    <td><code>{{ $dept->id }}</code></td>
                                    <td>{{ $dept->name }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

    {{-- ─────────────────────────────────────────────────────
         ENDPOINT D: GET /api/hr/device-lookup
    ───────────────────────────────────────────────────── --}}
    <div class="accordion-item border">
        <h2 class="accordion-header">
            <button class="accordion-button collapsed fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#endpointDeviceLookup"
                    aria-expanded="false" aria-controls="endpointDeviceLookup">
                <span class="badge bg-info text-dark me-3 px-2 py-1" style="font-size:.75rem">GET</span>
                <code>/api/hr/device-lookup</code>
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">Get TeamViewer ID &amp; hardware info for a user's device</span>
            </button>
        </h2>
        <div id="endpointDeviceLookup" class="accordion-collapse collapse" data-bs-parent="#endpointsAccordion">
            <div class="accordion-body">
                <p class="text-muted">
                    Returns the TeamViewer ID, CPU, MAC addresses, and other hardware info for the device(s)
                    currently assigned to the given user (looked up by UPN or email).
                    Useful for helpdesk integrations that need to remote into a user's machine.
                </p>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-info"></i>Query Parameter</h6>
                        <table class="table table-sm table-bordered mb-3">
                            <thead class="table-light">
                                <tr><th>Parameter</th><th>Required</th><th>Description</th></tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>upn</code></td>
                                    <td><span class="badge bg-danger-subtle text-danger border border-danger-subtle">required</span></td>
                                    <td>User Principal Name or work email (e.g. <code>ahmed@company.com</code>)</td>
                                </tr>
                            </tbody>
                        </table>

                        <h6 class="fw-semibold mb-2"><i class="bi bi-terminal me-1 text-secondary"></i>cURL Example</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>curl -X GET \
  "{{ $baseUrl }}/api/hr/device-lookup?upn=ahmed.karimi@company.com" \
  -H "X-HR-Api-Key: YOUR_API_KEY" \
  -H "Accept: application/json"</code></pre>
                    </div>

                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-circle me-1 text-primary"></i>
                            Response <span class="badge bg-success ms-1">200 OK</span>
                        </h6>
                        <pre class="bg-dark text-light p-3 rounded small" style="font-size:.75rem"><code>{
  <span class="text-warning">"ok"</span>:             <span class="text-info">true</span>,
  <span class="text-warning">"upn"</span>:            <span class="text-success">"ahmed.karimi@company.com"</span>,
  <span class="text-warning">"employee"</span>:       <span class="text-success">"Ahmed Karimi"</span>,
  <span class="text-warning">"teamviewer_id"</span>:  <span class="text-success">"1234567890"</span>,   <span class="text-secondary">// primary device TV ID</span>
  <span class="text-warning">"tv_version"</span>:     <span class="text-success">"15.72.6 H"</span>,
  <span class="text-warning">"devices"</span>: [{
    <span class="text-warning">"asset_code"</span>:    <span class="text-success">"SG-LAP-000171"</span>,
    <span class="text-warning">"device_name"</span>:   <span class="text-success">"J-MZAHRAN"</span>,
    <span class="text-warning">"type"</span>:          <span class="text-success">"laptop"</span>,
    <span class="text-warning">"model"</span>:         <span class="text-success">"LENOVO 21SX006UAD"</span>,
    <span class="text-warning">"serial"</span>:        <span class="text-success">"PF5XEHlL"</span>,
    <span class="text-warning">"branch"</span>:        <span class="text-success">"JED"</span>,
    <span class="text-warning">"ip_address"</span>:    <span class="text-success">"192.168.1.50"</span>,
    <span class="text-warning">"mac_address"</span>:   <span class="text-success">"A8:2B:DD:68:3D:9E"</span>,
    <span class="text-warning">"cpu"</span>:           <span class="text-success">"Intel Core Ultra 7 255H"</span>,
    <span class="text-warning">"teamviewer_id"</span>: <span class="text-success">"1234567890"</span>,
    <span class="text-warning">"tv_version"</span>:    <span class="text-success">"15.72.6 H"</span>,
    <span class="text-warning">"ethernet_mac"</span>:  <span class="text-success">"A8:2B:DD:68:3D:9E"</span>,
    <span class="text-warning">"wifi_mac_intune"</span>:<span class="text-success">"BC:F1:05:5C:F7:5B"</span>,
    <span class="text-warning">"usb_adapters"</span>:  [],
    <span class="text-warning">"hw_synced_at"</span>:  <span class="text-success">"2026-04-01T13:00:00Z"</span>,
    <span class="text-warning">"azure_device"</span>: {
      <span class="text-warning">"display_name"</span>: <span class="text-success">"J-MZAHRAN"</span>,
      <span class="text-warning">"upn"</span>:          <span class="text-success">"ahmed.karimi@company.com"</span>,
      <span class="text-warning">"os"</span>:           <span class="text-success">"Windows 10.0.26200.8037"</span>,
      <span class="text-warning">"last_sync"</span>:    <span class="text-success">"2026-04-01T13:00:00Z"</span>
    }
  }]
}</code></pre>

                        <div class="alert alert-info small mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>Note:</strong> <code>teamviewer_id</code> at the root level is the ID of the first device
                            that has TeamViewer synced — convenient shortcut for single-device users.
                            If the device hasn't run the Intune script yet, <code>teamviewer_id</code> will be <code>null</code>.
                        </div>
                    </div>
                </div>

                <h6 class="fw-semibold mt-3 mb-2">Error Responses</h6>
                <div class="row g-2">
                    <div class="col-md-6">
                        <pre class="bg-dark text-light p-2 rounded small mb-0"><code><span class="text-warning">// 404 — User not found</span>
{
  <span class="text-warning">"ok"</span>:      <span class="text-info">false</span>,
  <span class="text-warning">"error"</span>:   <span class="text-success">"No employee found for the given UPN."</span>,
  <span class="text-warning">"devices"</span>: []
}</code></pre>
                    </div>
                    <div class="col-md-6">
                        <pre class="bg-dark text-light p-2 rounded small mb-0"><code><span class="text-warning">// 200 — Found but no devices assigned</span>
{
  <span class="text-warning">"ok"</span>:          <span class="text-info">true</span>,
  <span class="text-warning">"employee"</span>:    <span class="text-success">"Ahmed Karimi"</span>,
  <span class="text-warning">"devices"</span>:    [],
  <span class="text-warning">"message"</span>:    <span class="text-success">"Employee found but has no currently assigned devices."</span>
}</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>{{-- /endpointsAccordion --}}

{{-- ═══════════════════════════════════════════════════════
     TEST CONSOLE
═══════════════════════════════════════════════════════ --}}
<div class="card shadow-sm mb-4"
     x-data="apiConsole(@json($baseUrl))">

    <div class="card-header bg-dark text-light d-flex align-items-center gap-2">
        <i class="bi bi-terminal-fill text-success"></i>
        <span class="fw-semibold">API Test Console</span>
        <span class="badge bg-secondary ms-auto">Live</span>
    </div>

    <div class="card-body">
        <div class="row g-3 mb-3">
            {{-- API Key input --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold small">API Key</label>
                <input type="password" class="form-control font-monospace small"
                       x-model="apiKey"
                       placeholder="Paste your key here…">
                <div class="form-text">Key is sent only to this server.</div>
            </div>

            {{-- Endpoint selector --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Endpoint</label>
                <select class="form-select" x-model="endpoint" @change="updateExample()">
                    <option value="onboarding">POST /api/hr/onboarding</option>
                    <option value="offboarding">POST /api/hr/offboarding</option>
                    <option value="group-assignment">POST /api/hr/group-assignment</option>
                    <option value="device-lookup">GET /api/hr/device-lookup</option>
                </select>
            </div>

            {{-- Full URL display --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Full URL</label>
                <div class="input-group">
                    <span class="input-group-text small fw-bold"
                          :class="endpoint === 'device-lookup' ? 'bg-info text-dark' : 'bg-success text-white'"
                          x-text="endpoint === 'device-lookup' ? 'GET' : 'POST'"></span>
                    <input type="text" class="form-control font-monospace small bg-light"
                           :value="endpoint === 'device-lookup'
                               ? baseUrl + '/api/hr/device-lookup?upn=' + (upnParam || 'user@domain.com')
                               : baseUrl + '/api/hr/' + endpoint"
                           readonly>
                </div>
            </div>
        </div>

        {{-- UPN field for GET device-lookup --}}
        <template x-if="endpoint === 'device-lookup'">
            <div class="mb-3">
                <label class="form-label fw-semibold small">UPN / Email <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm font-monospace"
                       x-model="upnParam"
                       placeholder="user@company.com">
                <div class="form-text">The user's work email or Azure UPN to look up their device.</div>
            </div>
        </template>

        {{-- Request Body (hidden for GET) --}}
        <div class="mb-3" x-show="endpoint !== 'device-lookup'">
            <label class="form-label fw-semibold small">Request Body (JSON)</label>
            <textarea class="form-control font-monospace small"
                      rows="12"
                      x-model="body"
                      spellcheck="false"
                      placeholder="{}"></textarea>
        </div>

        {{-- Send button + status --}}
        <div class="d-flex align-items-center gap-3 mb-3">
            <button class="btn btn-primary"
                    @click="sendRequest()"
                    :disabled="loading">
                <span x-show="!loading">
                    <i class="bi bi-send-fill me-1"></i> Send Request
                </span>
                <span x-show="loading">
                    <span class="spinner-border spinner-border-sm me-1" role="status"></span>
                    Sending…
                </span>
            </button>

            <template x-if="statusCode">
                <span class="badge fs-6 px-3 py-2"
                      :class="{
                          'bg-success': statusCode >= 200 && statusCode < 300,
                          'bg-warning text-dark': statusCode === 401 || statusCode === 422 || statusCode === 429,
                          'bg-danger': statusCode >= 500
                      }"
                      x-text="'HTTP ' + statusCode">
                </span>
            </template>

            <template x-if="statusCode">
                <span class="text-muted small" x-text="responseTime + ' ms'"></span>
            </template>

            <button class="btn btn-outline-secondary btn-sm ms-auto"
                    x-show="response"
                    @click="response = ''; statusCode = null; responseTime = null">
                <i class="bi bi-x-lg me-1"></i> Clear
            </button>
        </div>

        {{-- Response --}}
        <template x-if="response">
            <div>
                <label class="form-label fw-semibold small">Response</label>
                <pre class="bg-dark text-light p-3 rounded small"
                     style="max-height:300px; overflow-y:auto;"
                     x-text="response"></pre>
            </div>
        </template>

        {{-- Error --}}
        <template x-if="errorMsg">
            <div class="alert alert-danger small" x-text="errorMsg"></div>
        </template>
    </div>
</div>

@endsection

@push('scripts')
<script>
    function apiConsole(baseUrl) {
        const examples = {
            'onboarding': JSON.stringify({
                first_name:      "Ahmed",
                last_name:       "Karimi",
                job_title:       "Software Engineer",
                branch_id:       1,
                department_id:   3,
                department_name: "Engineering",
                start_date:      "2026-04-01",
                manager_email:   "manager@company.com",
                upn_domain:      "company.com",
                hr_reference:    "HR-2026-0045",
                mobile_phone:    "+20XXXXXXXXX",
                notes:           "VIP employee"
            }, null, 2),

            'offboarding': JSON.stringify({
                employee_name: "Ahmed Karimi",
                upn:           "ahmed.karimi@company.com",
                employee_id:   17,
                last_day:      "2026-04-30",
                reason:        "resignation",
                manager_email: "manager@company.com",
                manager_name:  "Sarah Smith",
                forward_to:    "team@company.com",
                branch_id:     1,
                hr_reference:  "HR-OFF-2026-012"
            }, null, 2),

            'group-assignment': JSON.stringify({
                upn:         "ahmed.karimi@company.com",
                group_names: ["All-Sales-Staff", "Cairo-Office"]
            }, null, 2)
        };

        return {
            apiKey:       '',
            baseUrl:      baseUrl,
            endpoint:     'onboarding',
            body:         examples['onboarding'],
            upnParam:     '',
            response:     '',
            statusCode:   null,
            responseTime: null,
            loading:      false,
            errorMsg:     '',

            updateExample() {
                this.body         = examples[this.endpoint] || '{}';
                this.response     = '';
                this.statusCode   = null;
                this.responseTime = null;
                this.errorMsg     = '';
            },

            async sendRequest() {
                if (! this.apiKey.trim()) {
                    this.errorMsg = 'Please enter your API key above before sending.';
                    return;
                }

                this.loading      = true;
                this.response     = '';
                this.statusCode   = null;
                this.responseTime = null;
                this.errorMsg     = '';

                // ── GET device-lookup ─────────────────────────────
                if (this.endpoint === 'device-lookup') {
                    if (! this.upnParam.trim()) {
                        this.errorMsg = 'Please enter a UPN / email address.';
                        this.loading  = false;
                        return;
                    }
                    const url   = this.baseUrl + '/api/hr/device-lookup?upn=' + encodeURIComponent(this.upnParam.trim());
                    const start = Date.now();
                    try {
                        const res = await fetch(url, {
                            method:  'GET',
                            headers: {
                                'Accept':           'application/json',
                                'X-HR-Api-Key':     this.apiKey.trim(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        this.statusCode   = res.status;
                        this.responseTime = Date.now() - start;
                        const json = await res.json();
                        this.response = JSON.stringify(json, null, 2);
                    } catch (e) {
                        this.errorMsg = 'Request failed: ' + e.message;
                    } finally {
                        this.loading = false;
                    }
                    return;
                }

                // ── POST endpoints ────────────────────────────────
                let parsed;
                try {
                    parsed = JSON.parse(this.body);
                } catch (e) {
                    this.errorMsg = 'Invalid JSON: ' + e.message;
                    this.loading  = false;
                    return;
                }

                const url   = this.baseUrl + '/api/hr/' + this.endpoint;
                const start = Date.now();

                try {
                    const res = await fetch(url, {
                        method:  'POST',
                        headers: {
                            'Content-Type':     'application/json',
                            'Accept':           'application/json',
                            'X-HR-Api-Key':     this.apiKey.trim(),
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify(parsed)
                    });

                    this.statusCode   = res.status;
                    this.responseTime = Date.now() - start;

                    const text = await res.text();
                    try {
                        this.response = JSON.stringify(JSON.parse(text), null, 2);
                    } catch {
                        this.response = text;
                    }
                } catch (err) {
                    this.errorMsg = 'Network error: ' + err.message;
                }

                this.loading = false;
            }
        };
    }
</script>
@endpush
