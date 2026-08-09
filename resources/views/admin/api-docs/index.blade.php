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
@foreach($endpoints as $ep)
    @php
        $methodClass = $ep['method'] === 'GET' ? 'bg-info text-dark' : 'bg-success';
        $bodyId      = 'endpoint-'.$ep['id'];
    @endphp
    <div class="accordion-item border">
        <h2 class="accordion-header">
            <button class="accordion-button {{ $loop->first ? '' : 'collapsed' }} fw-semibold" type="button"
                    data-bs-toggle="collapse" data-bs-target="#{{ $bodyId }}"
                    aria-expanded="{{ $loop->first ? 'true' : 'false' }}" aria-controls="{{ $bodyId }}">
                <span class="badge {{ $methodClass }} me-3 px-2 py-1" style="font-size:.75rem">{{ $ep['method'] }}</span>
                <code>{{ $ep['path'] }}</code>
                <span class="ms-3 text-muted fw-normal small d-none d-md-inline">{{ $ep['summary'] }}</span>
            </button>
        </h2>
        <div id="{{ $bodyId }}" class="accordion-collapse collapse {{ $loop->first ? 'show' : '' }}" data-bs-parent="#endpointsAccordion">
            <div class="accordion-body">

                @if($ep['mirrors'])
                <div class="alert alert-primary py-2 small mb-3">
                    <i class="bi bi-arrow-left-right me-1"></i>
                    <strong>Same behaviour as {{ $ep['mirrors'] }}.</strong>
                    Both go through the same service, so a request raised here is indistinguishable from one a person filled in.
                </div>
                @endif

                <p class="text-muted">{{ $ep['description'] }}</p>

                @if(! empty($ep['notes']))
                <ul class="small text-muted ps-3 mb-3">
                    @foreach($ep['notes'] as $note)
                    <li class="mb-1">{{ $note }}</li>
                    @endforeach
                </ul>
                @endif

                @if(! empty($ep['fields']))
                <h6 class="fw-semibold mb-2"><i class="bi bi-list-columns me-1 text-primary"></i>{{ $ep['method'] === 'GET' ? 'Query parameters' : 'Request body' }}</h6>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-bordered mb-0">
                        <thead class="table-light">
                            <tr>
                                <th style="width:200px">Field</th>
                                <th style="width:150px">Type</th>
                                <th style="width:110px">Required</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($ep['fields'] as [$name, $type, $req, $desc])
                            <tr>
                                <td><code>{{ $name }}</code></td>
                                <td class="small text-muted">{{ $type }}</td>
                                <td>
                                    @if($req === 'required')
                                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">required</span>
                                    @elseif($req === 'one-of')
                                        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">one of</span>
                                    @else
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis">optional</span>
                                    @endif
                                </td>
                                <td class="small">{{ $desc }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(collect($ep['fields'])->contains(fn ($f) => $f[2] === 'one-of'))
                <p class="small text-muted"><i class="bi bi-info-circle me-1"></i><strong>one of</strong> — send any single field from that group; the first one that resolves wins.</p>
                @endif
                @endif

                <div class="row g-4">
                    @if($ep['request'])
                    <div class="col-lg-6">
                        <h6 class="fw-semibold mb-2"><i class="bi bi-arrow-up-circle me-1 text-primary"></i>Example request</h6>
                        <pre class="bg-dark text-light p-3 rounded small mb-0"><code>{{ json_encode($ep['request'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </div>
                    @endif
                    <div class="col-lg-{{ $ep['request'] ? 6 : 12 }}">
                        <h6 class="fw-semibold mb-2">
                            <i class="bi bi-arrow-down-circle me-1 text-primary"></i>Example response
                            <span class="badge {{ $ep['method'] === 'GET' ? 'bg-primary' : 'bg-success' }} ms-1">{{ $ep['method'] === 'GET' ? '200 OK' : '201 Created' }}</span>
                        </h6>
                        <pre class="bg-dark text-light p-3 rounded small mb-0"><code>{{ json_encode($ep['response'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</code></pre>
                    </div>
                </div>

                <h6 class="fw-semibold mb-2 mt-3"><i class="bi bi-terminal me-1 text-secondary"></i>cURL</h6>
                @php
                    $curlPath = str_replace('{workflow_id}', '812', $ep['path']);
                @endphp
                @if($ep['method'] === 'GET')
                <pre class="bg-dark text-light p-3 rounded small mb-0"><code>curl -s "{{ $baseUrl }}{{ $curlPath }}{{ ! empty($ep['fields']) ? '?'.$ep['fields'][0][0].'=VALUE' : '' }}" \
  -H "X-HR-Api-Key: YOUR_KEY" \
  -H "Accept: application/json"</code></pre>
                @else
                <pre class="bg-dark text-light p-3 rounded small mb-0"><code>curl -s -X POST "{{ $baseUrl }}{{ $curlPath }}" \
  -H "X-HR-Api-Key: YOUR_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{{ json_encode($ep['request'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}'</code></pre>
                @endif
            </div>
        </div>
    </div>
@endforeach
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

{{-- ═══════════════════════════════════════════════════════
     TYPICAL INTEGRATION FLOW
═══════════════════════════════════════════════════════ --}}
<div class="card border-secondary mb-4 shadow-sm">
    <div class="card-header bg-secondary bg-opacity-10 d-flex align-items-center gap-2">
        <i class="bi bi-diagram-3-fill text-secondary"></i>
        <span class="fw-semibold">Typical integration flow</span>
        <span class="badge bg-secondary ms-auto">Replace YOUR_KEY with your API key</span>
    </div>
    <div class="card-body p-0">
        <div class="p-3 border-bottom">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-secondary">1</span>
                <span class="fw-semibold small">Cache the reference data</span>
                <span class="text-muted small ms-auto">— branch, department and domain ids</span>
            </div>
            <pre class="bg-dark text-light p-2 rounded small mb-0"><code>curl -s "{{ $baseUrl }}/api/hr/reference-data"   -H "X-HR-Api-Key: YOUR_KEY" -H "Accept: application/json"</code></pre>
        </div>
        <div class="p-3 border-bottom">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-secondary">2</span>
                <span class="fw-semibold small">Resolve the people involved</span>
                <span class="text-muted small ms-auto">— Oracle number in, NOC id out</span>
            </div>
            <pre class="bg-dark text-light p-2 rounded small mb-0"><code>curl -s "{{ $baseUrl }}/api/hr/employees?oracle_emp_no=10432"   -H "X-HR-Api-Key: YOUR_KEY" -H "Accept: application/json"</code></pre>
        </div>
        <div class="p-3 border-bottom">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-success">3</span>
                <span class="fw-semibold small">Raise the request</span>
                <span class="text-muted small ms-auto">— onboarding, offboarding or a data change</span>
            </div>
            <pre class="bg-dark text-light p-2 rounded small mb-0"><code>curl -s -X POST "{{ $baseUrl }}/api/hr/onboarding"   -H "X-HR-Api-Key: YOUR_KEY"   -H "Content-Type: application/json" -H "Accept: application/json"   -d '{"first_name":"Sara","last_name":"Al-Rashid","gender":"female","start_date":"2026-09-01","oracle_emp_no":"10432","manager_email":"manager@samirgroup.com"}'</code></pre>
        </div>
        <div class="p-3">
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-info text-dark">4</span>
                <span class="fw-semibold small">Poll for the outcome</span>
                <span class="text-muted small ms-auto">— using the workflow_id you got back</span>
            </div>
            <pre class="bg-dark text-light p-2 rounded small mb-0"><code>curl -s "{{ $baseUrl }}/api/hr/requests/812"   -H "X-HR-Api-Key: YOUR_KEY" -H "Accept: application/json"</code></pre>
        </div>
    </div>
    <div class="card-footer bg-transparent small text-muted">
        <i class="bi bi-exclamation-circle me-1"></i>
        Always include <code>Accept: application/json</code> — without it, errors return HTML instead of JSON.
        Every write endpoint raises a request for IT to approve; none of them changes anything on its own.
    </div>
</div>

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
                    <option value="employee-update">POST /api/hr/employee-update</option>
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
        // Seeded from App\Support\HrApiDocs so the console and the reference
        // above can never disagree about what a request looks like.
        const examples = @json(collect($endpoints)->filter(fn ($e) => $e['testable'] && $e['request'])->mapWithKeys(fn ($e) => [$e['id'] => $e['request']]));
        Object.keys(examples).forEach(k => { examples[k] = JSON.stringify(examples[k], null, 2); });

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
