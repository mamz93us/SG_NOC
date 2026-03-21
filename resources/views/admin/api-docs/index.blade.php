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
<div class="card border-warning mb-4 shadow-sm" x-data="{ showKey: false }">
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
                                <span x-show="!showKey" class="font-monospace text-secondary">
                                    @if($apiKey)
                                        {{ Str::mask($apiKey, '*', 4) }}
                                    @else
                                        <span class="text-danger fw-semibold">⚠ NOT SET — add HR_API_KEY to .env</span>
                                    @endif
                                </span>
                                <span x-show="showKey" class="font-monospace text-dark">
                                    @if($apiKey)
                                        {{ $apiKey }}
                                    @else
                                        <span class="text-danger fw-semibold">⚠ NOT SET — add HR_API_KEY to .env</span>
                                    @endif
                                </span>
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

                @if($apiKey)
                <button class="btn btn-outline-secondary btn-sm" @click="showKey = !showKey">
                    <i class="bi" :class="showKey ? 'bi-eye-slash' : 'bi-eye'"></i>
                    <span x-text="showKey ? 'Hide Key' : 'Show Key'"></span>
                </button>
                @endif
            </div>
            <div class="col-lg-4">
                <div class="alert alert-warning mb-0 small">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <strong>Keep this key secret.</strong> Rotate it in <code>.env</code> if compromised. Never expose it in client-side code or public repositories.
                </div>
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
                    Creates a new user onboarding workflow. The system will provision an Azure AD account,
                    assign licenses, create a UCM extension, and send a welcome email.
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
                    the system disables the Azure AD account, archives the mailbox, and downgrades the license.
                </p>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-danger"></i>Request Body</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"employee_name"</span>: <span class="text-success">"Ahmed Karimi"</span>,     <span class="text-secondary">// required</span>
  <span class="text-warning">"azure_id"</span>:       <span class="text-success">"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"</span>,
  <span class="text-warning">"upn"</span>:            <span class="text-success">"ahmed.karimi@company.com"</span>,
  <span class="text-warning">"last_day"</span>:       <span class="text-success">"2026-04-30"</span>,
  <span class="text-warning">"reason"</span>:         <span class="text-success">"resignation"</span>,
  <span class="text-warning">"manager_email"</span>: <span class="text-success">"manager@company.com"</span>, <span class="text-secondary">// required</span>
  <span class="text-warning">"manager_name"</span>:  <span class="text-success">"Sarah Smith"</span>,
  <span class="text-warning">"forward_to"</span>:    <span class="text-success">"team@company.com"</span>,
  <span class="text-warning">"branch_id"</span>:     <span class="text-info">1</span>,
  <span class="text-warning">"hr_reference"</span>:  <span class="text-success">"HR-OFF-2026-012"</span>
}</code></pre>

                        <div class="mb-3">
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">employee_name</span>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle me-1">manager_email</span>
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
  <span class="text-warning">"workflow_id"</span>: <span class="text-info">43</span>,
  <span class="text-warning">"status"</span>:      <span class="text-success">"manager_input_pending"</span>,
  <span class="text-warning">"message"</span>:     <span class="text-success">"Offboarding workflow created. Manager approval email sent."</span>
}</code></pre>

                        <h6 class="fw-semibold mb-2 mt-3"><i class="bi bi-terminal me-1 text-secondary"></i>cURL Example</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code><span class="text-info">curl</span> -X POST {{ $baseUrl }}/api/hr/offboarding \
  -H <span class="text-success">"X-HR-Api-Key: YOUR_KEY"</span> \
  -H <span class="text-success">"Content-Type: application/json"</span> \
  -d <span class="text-success">'{"employee_name":"Ahmed Karimi","manager_email":"manager@company.com"}'</span></code></pre>
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
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">Assign Azure AD groups to a user</span>
            </button>
        </h2>
        <div id="endpointGroupAssignment" class="accordion-collapse collapse" data-bs-parent="#endpointsAccordion">
            <div class="accordion-body">
                <p class="text-muted">
                    Assigns one or more Azure AD groups to an existing user. Groups are looked up by name and added
                    via Microsoft Graph API.
                </p>

                <div class="row g-4">
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-primary"></i>Request Body</h6>
                        <pre class="bg-dark text-light p-3 rounded small"><code>{
  <span class="text-warning">"azure_id"</span>:    <span class="text-success">"xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"</span>, <span class="text-secondary">// required*</span>
  <span class="text-warning">"upn"</span>:         <span class="text-success">"ahmed.karimi@company.com"</span>, <span class="text-secondary">// required*</span>
  <span class="text-warning">"group_names"</span>: [
    <span class="text-success">"All-Sales-Staff"</span>,
    <span class="text-success">"Cairo-Office"</span>
  ]
}</code></pre>

                        <div class="alert alert-info small py-2 mb-3">
                            <i class="bi bi-info-circle me-1"></i>
                            <strong>*</strong> At least one of <code>azure_id</code> or <code>upn</code> is required.
                            <code>group_names</code> array is required and must not be empty.
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
                    <td>Unauthorized — <code>X-HR-Api-Key</code> missing or incorrect</td>
                </tr>
                <tr>
                    <td><span class="badge bg-warning text-dark">422</span></td>
                    <td>Validation Error — a required field is missing or invalid</td>
                </tr>
                <tr>
                    <td><span class="badge bg-danger">500</span></td>
                    <td>Server Error — unexpected error on the SG NOC side</td>
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

{{-- ═══════════════════════════════════════════════════════
     TEST CONSOLE
═══════════════════════════════════════════════════════ --}}
<div class="card shadow-sm mb-4"
     x-data="apiConsole(@json($apiKey), @json($baseUrl))">

    <div class="card-header bg-dark text-light d-flex align-items-center gap-2">
        <i class="bi bi-terminal-fill text-success"></i>
        <span class="fw-semibold">API Test Console</span>
        <span class="badge bg-secondary ms-auto">Live</span>
    </div>

    <div class="card-body">
        <div class="row g-3 mb-3">
            {{-- Endpoint selector --}}
            <div class="col-md-4">
                <label class="form-label fw-semibold small">Endpoint</label>
                <select class="form-select" x-model="endpoint" @change="updateExample()">
                    <option value="onboarding">POST /api/hr/onboarding</option>
                    <option value="offboarding">POST /api/hr/offboarding</option>
                    <option value="group-assignment">POST /api/hr/group-assignment</option>
                </select>
            </div>

            {{-- Full URL display --}}
            <div class="col-md-8">
                <label class="form-label fw-semibold small">Full URL</label>
                <div class="input-group">
                    <span class="input-group-text bg-success text-white small fw-bold">POST</span>
                    <input type="text" class="form-control font-monospace small bg-light"
                           :value="baseUrl + '/api/hr/' + endpoint" readonly>
                </div>
            </div>
        </div>

        {{-- Request Body --}}
        <div class="mb-3">
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
                          'bg-warning text-dark': statusCode === 401 || statusCode === 422,
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
    function apiConsole(apiKey, baseUrl) {
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
                azure_id:      "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
                upn:           "ahmed.karimi@company.com",
                last_day:      "2026-04-30",
                reason:        "resignation",
                manager_email: "manager@company.com",
                manager_name:  "Sarah Smith",
                forward_to:    "team@company.com",
                branch_id:     1,
                hr_reference:  "HR-OFF-2026-012"
            }, null, 2),

            'group-assignment': JSON.stringify({
                azure_id:    "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
                upn:         "ahmed.karimi@company.com",
                group_names: ["All-Sales-Staff", "Cairo-Office"]
            }, null, 2)
        };

        return {
            apiKey:       apiKey || '',
            baseUrl:      baseUrl,
            endpoint:     'onboarding',
            body:         examples['onboarding'],
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
                this.loading      = true;
                this.response     = '';
                this.statusCode   = null;
                this.responseTime = null;
                this.errorMsg     = '';

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
                            'Content-Type':  'application/json',
                            'Accept':        'application/json',
                            'X-HR-Api-Key':  this.apiKey,
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
