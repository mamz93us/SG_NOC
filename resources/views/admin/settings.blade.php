@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3">Settings</h1>
</div>


<div class="card">
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.update') }}" enctype="multipart/form-data">
            @csrf

            <!-- Company Name -->
            <div class="mb-4">
                <label for="company_name" class="form-label">Company Name</label>
                <input 
                    type="text" 
                    name="company_name" 
                    id="company_name"
                    value="{{ old('company_name', $settings->company_name) }}"
                    class="form-control @error('company_name') is-invalid @enderror"
                    required
                >
                @error('company_name')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <!-- Company Logo -->
            <div class="mb-4">
                <label class="form-label">Company Logo</label>

                @if($settings->company_logo)
                    <div class="mb-3">
                        <img
                            src="{{ asset('storage/' . $settings->company_logo) }}"
                            alt="Company Logo"
                            class="img-thumbnail"
                            style="max-width: 300px;"
                        >
                        <div class="mt-2">
                            <button
                                type="button"
                                class="btn btn-sm btn-outline-danger"
                                onclick="if(confirm('Delete the logo?')) document.getElementById('delete-logo-form').submit();"
                            >
                                Delete Logo
                            </button>
                        </div>
                    </div>
                @endif

                <input
                    type="file"
                    name="company_logo"
                    id="company_logo"
                    accept="image/*"
                    class="form-control @error('company_logo') is-invalid @enderror"
                >
                <div class="form-text">Recommended: PNG or JPG, max 2MB</div>
                @error('company_logo')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <!-- Submit Button -->
            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    Save Settings
                </button>
            </div>
        </form>
    </div>
</div>

{{-- Delete Logo: standalone form OUTSIDE the main form (nested forms are invalid HTML) --}}
@if($settings->company_logo)
<form id="delete-logo-form" method="POST" action="{{ route('admin.settings.delete-logo') }}" class="d-none">
    @csrf
    @method('DELETE')
</form>
@endif

{{-- ─────────────────────────────────────────────────────── --}}
{{-- Microsoft SSO Section                                  --}}
{{-- ─────────────────────────────────────────────────────── --}}
<div class="card mt-4">
    <div class="card-header d-flex align-items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23">
            <rect x="1" y="1" width="10" height="10" fill="#f25022"/>
            <rect x="12" y="1" width="10" height="10" fill="#7fba00"/>
            <rect x="1" y="12" width="10" height="10" fill="#00a4ef"/>
            <rect x="12" y="12" width="10" height="10" fill="#ffb900"/>
        </svg>
        <h5 class="mb-0">Microsoft SSO (Azure AD / Entra ID)</h5>
        @if($settings->sso_enabled)
            <span class="badge bg-success ms-auto">Enabled</span>
        @else
            <span class="badge bg-secondary ms-auto">Disabled</span>
        @endif
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.sso') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="sso_enabled"
                            id="sso_enabled" value="1" {{ $settings->sso_enabled ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="sso_enabled">
                            Enable Microsoft SSO Login
                        </label>
                    </div>
                    <div class="form-text">Users will see a "Sign in with Microsoft" button on the login page.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tenant ID</label>
                    <input type="text" name="sso_tenant_id" class="form-control font-monospace"
                        value="{{ old('sso_tenant_id', $settings->sso_tenant_id) }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-text">Azure Portal → App registrations → Directory (tenant) ID</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client ID (Application ID)</label>
                    <input type="text" name="sso_client_id" class="form-control font-monospace"
                        value="{{ old('sso_client_id', $settings->sso_client_id) }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-text">Azure Portal → App registrations → Application (client) ID</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client Secret</label>
                    <input type="password" name="sso_client_secret" class="form-control"
                        placeholder="{{ $settings->sso_client_secret ? '••••••••  (leave blank to keep current)' : 'Paste secret value here' }}">
                    <div class="form-text">Azure Portal → App registrations → Certificates &amp; secrets</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Default Role for New SSO Users</label>
                    <select name="sso_default_role" class="form-select">
                        <option value="viewer"      {{ ($settings->sso_default_role ?? 'viewer') === 'viewer'      ? 'selected' : '' }}>Viewer (read-only)</option>
                        <option value="admin"       {{ ($settings->sso_default_role ?? '') === 'admin'       ? 'selected' : '' }}>Admin</option>
                        <option value="super_admin" {{ ($settings->sso_default_role ?? '') === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                    </select>
                    <div class="form-text">Role assigned when a new Microsoft user logs in for the first time.</div>
                </div>
            </div>

            <div class="alert alert-info py-2 small mb-3">
                <strong>Azure Redirect URI to register:</strong>
                <code class="ms-1">{{ url('/auth/microsoft/callback') }}</code>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save SSO Settings
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ─────────────────────────────────────────────────────── --}}
{{-- UCM Servers Section                                    --}}
{{-- ─────────────────────────────────────────────────────── --}}
<div class="card mt-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-hdd-network me-2"></i>UCM / IPPBX Servers</h5>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addUcmModal">
            <i class="bi bi-plus-circle me-1"></i> Add UCM Server
        </button>
    </div>
    <div class="card-body p-0">
        @if($ucmServers->isEmpty())
            <div class="text-center text-muted py-4">No UCM servers configured yet.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>URL</th>
                        <th>API Username</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ucmServers as $ucm)
                    <tr>
                        <td>{{ $loop->iteration }}</td>
                        <td><strong>{{ $ucm->name }}</strong></td>
                        <td><code>{{ $ucm->url }}</code></td>
                        <td>{{ $ucm->api_username }}</td>
                        <td>
                            <form method="POST" action="{{ route('admin.ucm-servers.toggle', $ucm) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm {{ $ucm->is_active ? 'btn-success' : 'btn-secondary' }}">
                                    {{ $ucm->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </form>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-primary me-1"
                                data-bs-toggle="modal"
                                data-bs-target="#editUcmModal{{ $ucm->id }}">
                                <i class="bi bi-pencil"></i> Edit
                            </button>
                            <form method="POST" action="{{ route('admin.ucm-servers.destroy', $ucm) }}" class="d-inline"
                                onsubmit="return confirm('Delete {{ $ucm->name }}? This cannot be undone.')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </form>
                        </td>
                    </tr>

                    {{-- Edit Modal for each UCM --}}
                    <div class="modal fade" id="editUcmModal{{ $ucm->id }}" tabindex="-1" aria-hidden="true">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form method="POST" action="{{ route('admin.ucm-servers.update', $ucm) }}">
                                    @csrf @method('PUT')
                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit UCM Server</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label">Name / Label <span class="text-danger">*</span></label>
                                            <input type="text" name="name" class="form-control" value="{{ $ucm->name }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">URL <span class="text-danger">*</span></label>
                                            <input type="text" name="url" class="form-control" value="{{ $ucm->url }}" required
                                                placeholder="https://msc1abc.gdms.cloud  or  https://192.168.1.100:8089">
                                            <div class="form-text">
                                                Cloud (GDMS): no port needed &nbsp;|&nbsp; Local UCM: add <code>:8089</code>
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">
                                                Wave / SIP Cloud Domain
                                                <span class="badge bg-info text-dark ms-1" style="font-size:10px">For QR Code</span>
                                            </label>
                                            <input type="text" name="cloud_domain" class="form-control font-monospace"
                                                value="{{ $ucm->cloud_domain }}"
                                                placeholder="e.g. msc1abc.gdms.cloud">
                                            <div class="form-text">
                                                GDMS cloud relay hostname used in Wave QR codes.
                                                Leave blank to use the hostname from the URL above.
                                            </div>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">API Username <span class="text-danger">*</span></label>
                                            <input type="text" name="api_username" class="form-control" value="{{ $ucm->api_username }}" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">API Password</label>
                                            <input type="password" name="api_password" class="form-control"
                                                placeholder="Leave blank to keep current password">
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>

{{-- ─────────────────────────────────────────────────────── --}}
{{-- Meraki Network Section                                 --}}
{{-- ─────────────────────────────────────────────────────── --}}
<div class="card mt-4" id="meraki">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-diagram-3-fill text-primary fs-5"></i>
        <h5 class="mb-0">Meraki Network Integration</h5>
        @if($settings->meraki_enabled)
            <span class="badge bg-success ms-auto">Enabled</span>
        @else
            <span class="badge bg-secondary ms-auto">Disabled</span>
        @endif
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            <strong>Read-only observability.</strong>
            This integration fetches switch status, port states, and client data from the Meraki API.
            No write operations are performed on your network.
        </div>
        <form method="POST" action="{{ route('admin.settings.meraki') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="meraki_enabled"
                            id="meraki_enabled" value="1" {{ $settings->meraki_enabled ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="meraki_enabled">
                            Enable Meraki Network Monitoring
                        </label>
                    </div>
                    <div class="form-text">Enable to show the Network section in the navigation.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">API Key <span class="text-danger">*</span></label>
                    <input type="password" name="meraki_api_key" class="form-control font-monospace"
                        placeholder="{{ $settings->meraki_api_key ? '••••••••  (leave blank to keep current)' : 'Paste Meraki API key here' }}">
                    <div class="form-text">
                        Meraki Dashboard → Account → API access → Generate API key.
                        Stored encrypted.
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Organisation ID <span class="text-danger">*</span></label>
                    <input type="text" name="meraki_org_id" class="form-control font-monospace"
                        value="{{ old('meraki_org_id', $settings->meraki_org_id) }}"
                        placeholder="e.g. 123456">
                    <div class="form-text">
                        Meraki Dashboard → Organisation → Settings → Organisation ID.
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Polling Interval (minutes)</label>
                    <input type="number" name="meraki_polling_interval" class="form-control"
                        value="{{ old('meraki_polling_interval', $settings->meraki_polling_interval ?? 15) }}"
                        min="5" max="1440">
                    <div class="form-text">How often the background sync job runs (min: 5).</div>
                </div>

                <div class="col-md-8 d-flex align-items-end">
                    <div class="w-100">
                        <label class="form-label fw-semibold">Connection Test</label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-secondary" id="testMerakiBtn"
                                onclick="testMerakiConnection()">
                                <i class="bi bi-plug me-1"></i>Test Connection
                            </button>
                            <span class="input-group-text flex-grow-1" id="merakiTestResult" style="min-width:200px">
                                <span class="text-muted small">Click to test current credentials</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save Meraki Settings
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ─────────────────────────────────────────────────────── --}}
{{-- Microsoft Graph / Identity Section                     --}}
{{-- ─────────────────────────────────────────────────────── --}}
{{-- Graph API section visible to all admins --}}
<div class="card mt-4" id="graph">
    <div class="card-header d-flex align-items-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 23 23">
            <rect x="1" y="1" width="10" height="10" fill="#f25022"/>
            <rect x="12" y="1" width="10" height="10" fill="#7fba00"/>
            <rect x="1" y="12" width="10" height="10" fill="#00a4ef"/>
            <rect x="12" y="12" width="10" height="10" fill="#ffb900"/>
        </svg>
        <h5 class="mb-0">Microsoft Graph API (Identity Sync)</h5>
        @if($settings->identity_sync_enabled)
            <span class="badge bg-success ms-auto">Enabled</span>
        @else
            <span class="badge bg-secondary ms-auto">Disabled</span>
        @endif
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Connects to Microsoft Graph API to sync Entra ID users, licenses, and groups into the Identity module.
            Requires an App Registration with <code>User.Read.All</code>, <code>Group.Read.All</code>,
            and <code>Directory.Read.All</code> application permissions.
        </div>
        <form method="POST" action="{{ route('admin.settings.graph') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="identity_sync_enabled"
                            id="identity_sync_enabled" value="1" {{ $settings->identity_sync_enabled ? 'checked' : '' }}>
                        <label class="form-check-label fw-semibold" for="identity_sync_enabled">
                            Enable Automatic Identity Sync
                        </label>
                    </div>
                    <div class="form-text">Runs a background sync job on the configured interval.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Tenant ID <span class="text-danger">*</span></label>
                    <input type="text" name="graph_tenant_id" class="form-control font-monospace"
                        value="{{ old('graph_tenant_id', $settings->graph_tenant_id) }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-text">Azure Portal → App registrations → Directory (tenant) ID</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client ID (Application ID) <span class="text-danger">*</span></label>
                    <input type="text" name="graph_client_id" class="form-control font-monospace"
                        value="{{ old('graph_client_id', $settings->graph_client_id) }}"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    <div class="form-text">Azure Portal → App registrations → Application (client) ID</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client Secret <span class="text-danger">*</span></label>
                    <input type="password" name="graph_client_secret" class="form-control"
                        placeholder="{{ $settings->graph_client_secret ? '••••••••  (leave blank to keep current)' : 'Paste secret value here' }}">
                    <div class="form-text">Azure Portal → App registrations → Certificates &amp; secrets</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Sync Interval (minutes)</label>
                    <input type="number" name="identity_sync_interval" class="form-control"
                        value="{{ old('identity_sync_interval', $settings->identity_sync_interval ?? 60) }}"
                        min="15" max="1440">
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Default Password</label>
                    <input type="text" name="graph_default_password" class="form-control"
                        value="{{ old('graph_default_password', $settings->graph_default_password) }}"
                        placeholder="TempPass@123">
                    <div class="form-text">Used when resetting passwords.</div>
                </div>

                <div class="col-12 d-flex align-items-end">
                    <div>
                        <label class="form-label fw-semibold">Connection Test</label>
                        <div class="input-group">
                            <button type="button" class="btn btn-outline-secondary" id="testGraphBtn"
                                onclick="testGraphConnection()">
                                <i class="bi bi-plug me-1"></i>Test Connection
                            </button>
                            <span class="input-group-text flex-grow-1" id="graphTestResult" style="min-width:220px">
                                <span class="text-muted small">Click to test credentials</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save Graph Settings
                </button>
            </div>
        </form>
    </div>
</div>
{{-- end graph section --}}

{{-- ─────────────────────────────────────────────────────── --}}
{{-- GDMS API Section                                       --}}
{{-- ─────────────────────────────────────────────────────── --}}
<div class="card mt-4" id="gdms">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-router-fill text-primary fs-5"></i>
        <h5 class="mb-0">GDMS API (UCM Cloud)</h5>
        @if($settings->gdms_client_id)
            <span class="badge bg-success ms-auto">Configured</span>
        @else
            <span class="badge bg-secondary ms-auto">Not Configured</span>
        @endif
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Grandstream Device Management System (GDMS) API credentials for UCM Cloud phone provisioning.
        </div>
        <form method="POST" action="{{ route('admin.settings.gdms') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Base URL</label>
                    <input type="text" name="gdms_base_url" class="form-control font-monospace"
                        value="{{ old('gdms_base_url', $settings->gdms_base_url ?? 'https://www.gdms.cloud/oapi') }}"
                        placeholder="https://www.gdms.cloud/oapi">
                </div>

                <div class="col-md-4">
                    <label class="form-label fw-semibold">Organisation ID</label>
                    <input type="text" name="gdms_org_id" class="form-control font-monospace"
                        value="{{ old('gdms_org_id', $settings->gdms_org_id) }}"
                        placeholder="e.g. 139943">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client ID</label>
                    <input type="text" name="gdms_client_id" class="form-control font-monospace"
                        value="{{ old('gdms_client_id', $settings->gdms_client_id) }}"
                        placeholder="e.g. 104508">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Client Secret</label>
                    <input type="password" name="gdms_client_secret" class="form-control"
                        placeholder="{{ $settings->gdms_client_secret ? '•••••• (leave blank to keep current)' : 'Paste client secret here' }}">
                    <div class="form-text">Stored encrypted.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Username</label>
                    <input type="text" name="gdms_username" class="form-control"
                        value="{{ old('gdms_username', $settings->gdms_username) }}"
                        placeholder="e.g. mamz93">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Password Hash (MD5)</label>
                    <input type="text" name="gdms_password_hash" class="form-control font-monospace"
                        value="{{ old('gdms_password_hash', $settings->gdms_password_hash) }}"
                        placeholder="MD5 hash of your password">
                    <div class="form-text">md5 of your GDMS password. Not encrypted (already hashed).</div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save GDMS Settings
                </button>
            </div>
        </form>
    </div>
</div>

{{-- ─────────────────────────────────────────────────────── --}}
{{-- SMTP Email (Outgoing Mail) Section                     --}}
{{-- ─────────────────────────────────────────────────────── --}}
<div class="card mt-4" id="smtp">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-envelope-fill text-primary fs-5"></i>
        <h5 class="mb-0">SMTP Email (Outgoing Mail)</h5>
        @if($settings->smtp_host)
            <span class="badge bg-success ms-auto">Configured</span>
        @else
            <span class="badge bg-secondary ms-auto">Not Configured</span>
        @endif
    </div>
    <div class="card-body">
        <div class="alert alert-info py-2 small mb-3">
            <i class="bi bi-info-circle me-1"></i>
            Used for sending notification emails and workflow alerts. Credentials are stored encrypted.
        </div>
        <form method="POST" action="{{ route('admin.settings.smtp') }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control font-monospace"
                        value="{{ old('smtp_host', $settings->smtp_host) }}"
                        placeholder="smtp.office365.com">
                    <div class="form-text">e.g. smtp.office365.com, smtp.gmail.com, smtp.mailgun.org</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Port</label>
                    <input type="number" name="smtp_port" class="form-control"
                        value="{{ old('smtp_port', $settings->smtp_port ?? 587) }}"
                        min="1" max="65535">
                    <div class="form-text">587 (TLS) · 465 (SSL) · 25</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label fw-semibold">Encryption</label>
                    <select name="smtp_encryption" class="form-select">
                        <option value="tls"  {{ ($settings->smtp_encryption ?? 'tls') === 'tls'  ? 'selected' : '' }}>TLS (STARTTLS)</option>
                        <option value="ssl"  {{ ($settings->smtp_encryption ?? '') === 'ssl'  ? 'selected' : '' }}>SSL</option>
                        <option value="none" {{ ($settings->smtp_encryption ?? '') === 'none' ? 'selected' : '' }}>None</option>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Username</label>
                    <input type="text" name="smtp_username" class="form-control"
                        value="{{ old('smtp_username', $settings->smtp_username) }}"
                        placeholder="noc@yourdomain.com">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">Password</label>
                    <input type="password" name="smtp_password" class="form-control"
                        placeholder="{{ $settings->smtp_password ? '••••••••  (leave blank to keep current)' : 'Enter password or app password' }}">
                    <div class="form-text">Stored encrypted.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">From Address</label>
                    <input type="email" name="smtp_from_address" class="form-control"
                        value="{{ old('smtp_from_address', $settings->smtp_from_address) }}"
                        placeholder="noc@yourdomain.com">
                </div>

                <div class="col-md-6">
                    <label class="form-label fw-semibold">From Name</label>
                    <input type="text" name="smtp_from_name" class="form-control"
                        value="{{ old('smtp_from_name', $settings->smtp_from_name) }}"
                        placeholder="SG NOC">
                </div>

                <div class="col-md-12">
                    <hr class="my-3">
                    <label class="form-label fw-semibold"><i class="bi bi-bell-fill me-1 text-warning"></i>SNMP Global Alert Email</label>
                    <input type="email" name="snmp_alert_email" class="form-control"
                        value="{{ old('snmp_alert_email', $settings->snmp_alert_email) }}"
                        placeholder="noc-alerts@yourdomain.com">
                    <div class="form-text">Universal recipient for SNMP host offline alerts and sensor threshold violations.</div>
                </div>

                {{-- Test Email --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Send Test Email</label>
                    <div class="input-group" style="max-width:480px">
                        <input type="email" id="smtpTestAddr" class="form-control"
                            placeholder="recipient@example.com">
                        <button type="button" class="btn btn-outline-secondary" id="testSmtpBtn"
                            onclick="testSmtpConnection()">
                            <i class="bi bi-send me-1"></i>Send Test
                        </button>
                    </div>
                    <div id="smtpTestResult" class="mt-1 small text-muted">
                        Save settings first, then send a test email to verify delivery.
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-save me-1"></i>Save SMTP Settings
                </button>
            </div>
        </form>
    </div>
</div>

        {{-- ─── Provisioning Settings ──────────────────────────────── --}}
        <div class="card shadow-sm border-0 mb-4" id="provisioning">
            <div class="card-header bg-transparent">
                <h5 class="mb-0 fw-semibold"><i class="bi bi-person-gear me-2 text-primary"></i>Smart User Provisioning <span class="badge bg-secondary fw-normal fs-6 ms-1">Global Defaults</span></h5>
                <small class="text-muted">Configure auto UPN generation, UCM extension assignment, and Azure profile templates.</small>
                <div class="alert alert-info border-0 py-2 mt-2 mb-0 small">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Branch overrides:</strong> UCM server, extension range, and profile templates can be set per-branch in
                    <a href="{{ route('admin.branches.index') }}" class="alert-link">Branches</a>.
                    Branch settings take priority; these values are used as fallback when a branch has no UCM configured.
                </div>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.settings.provisioning') }}">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">UPN Domain <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text text-muted">@</span>
                                <input type="text" name="upn_domain" class="form-control"
                                       value="{{ $settings->upn_domain }}" placeholder="samirgroup.com">
                            </div>
                            <small class="text-muted">New users will get UPN: firstname.lastname@this-domain</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default UCM Server</label>
                            <select name="default_ucm_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach($ucmServers as $ucm)
                                <option value="{{ $ucm->id }}" {{ $settings->default_ucm_id == $ucm->id ? 'selected' : '' }}>{{ $ucm->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Extension Range Start</label>
                            <input type="number" name="ext_range_start" class="form-control" value="{{ $settings->ext_range_start ?? 1000 }}" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Extension Range End</label>
                            <input type="number" name="ext_range_end" class="form-control" value="{{ $settings->ext_range_end ?? 1999 }}" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Default Extension Secret</label>
                            <input type="text" name="ext_default_secret" class="form-control"
                                   value="{{ $settings->ext_default_secret }}" placeholder="changeme123">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Default Permission Level</label>
                            <select name="ext_default_permission" class="form-select">
                                @foreach(['internal' => 'Internal', 'local' => 'Local', 'national' => 'National', 'international' => 'International'] as $val => $lbl)
                                <option value="{{ $val }}" {{ $settings->ext_default_permission === $val ? 'selected' : '' }}>{{ $lbl }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Office Location Template</label>
                            <input type="text" name="profile_office_template" class="form-control"
                                   value="{{ $settings->profile_office_template }}" placeholder="{branch_name}">
                            <small class="text-muted">Variables: <code>{branch_name}</code>, <code>{branch_phone}</code>, <code>{extension}</code>, <code>{first_name}</code>, <code>{last_name}</code>, <code>{upn}</code></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Business Phone Template</label>
                            <input type="text" name="profile_phone_template" class="form-control"
                                   value="{{ $settings->profile_phone_template }}" placeholder="{branch_phone} EXT {extension}">
                            <small class="text-muted">Same variables as above.</small>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Provisioning Settings</button>
                    </div>
                </form>
            </div>
        </div>

        {{-- ─── Allowed Domains ────────────────────────────────────── --}}
        @can('manage-allowed-domains')
        <div class="card shadow-sm border-0 mb-4" id="domains">
            <div class="card-header bg-transparent">
                <h5 class="mb-0 fw-semibold"><i class="bi bi-globe me-2 text-primary"></i>Allowed Domains</h5>
                <small class="text-muted">Organizational email domains — used to filter external/guest Azure AD users</small>
            </div>
            <div class="card-body">
                @php $allowedDomains = \App\Models\AllowedDomain::orderBy('domain')->get(); @endphp
                @if($allowedDomains->isNotEmpty())
                <div class="mb-3">
                    @foreach($allowedDomains as $domain)
                    <span class="badge bg-light text-dark border me-2 mb-2 p-2">
                        @if($domain->is_primary)<i class="bi bi-star-fill text-warning me-1"></i>@endif
                        {{ $domain->domain }}
                        <form method="POST" action="{{ route('admin.settings.domains.destroy', $domain->id) }}" class="d-inline"
                              onsubmit="return confirm('Remove domain {{ $domain->domain }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn-close btn-close-sm ms-1" style="font-size:0.6em"></button>
                        </form>
                    </span>
                    @endforeach
                </div>
                @endif
                <form method="POST" action="{{ route('admin.settings.domains.store') }}" class="d-flex gap-2 align-items-end">
                    @csrf
                    <div>
                        <label class="form-label fw-semibold small">Add Domain</label>
                        <input type="text" name="domain" class="form-control form-control-sm" placeholder="samirgroup.com" required>
                    </div>
                    <div>
                        <label class="form-label fw-semibold small">Description</label>
                        <input type="text" name="description" class="form-control form-control-sm" placeholder="Optional">
                    </div>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_primary" value="1" id="isPrimaryDomain">
                        <label class="form-check-label small" for="isPrimaryDomain">Primary</label>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm mb-0"><i class="bi bi-plus me-1"></i>Add</button>
                </form>
            </div>
        </div>
        @endcan

{{-- Add UCM Modal --}}
<div class="modal fade" id="addUcmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.ucm-servers.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-hdd-network me-2"></i>Add UCM Server</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name / Label <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required
                            placeholder="e.g. Main Office UCM">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">URL <span class="text-danger">*</span></label>
                        <input type="text" name="url" class="form-control" required
                            placeholder="https://msc1abc.gdms.cloud  or  https://192.168.1.100:8089">
                        <div class="form-text">
                            Cloud (GDMS): no port needed &nbsp;|&nbsp; Local UCM: add <code>:8089</code>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">
                            Wave / SIP Cloud Domain
                            <span class="badge bg-info text-dark ms-1" style="font-size:10px">For QR Code</span>
                        </label>
                        <input type="text" name="cloud_domain" class="form-control font-monospace"
                            placeholder="e.g. msc1abc.gdms.cloud">
                        <div class="form-text">
                            GDMS cloud relay hostname used in Wave QR codes.
                            Leave blank to use the hostname from the URL above.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Username <span class="text-danger">*</span></label>
                        <input type="text" name="api_username" class="form-control" required
                            placeholder="admin">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API Password <span class="text-danger">*</span></label>
                        <input type="password" name="api_password" class="form-control" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Server</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── ITAM / Asset Code Settings ── --}}
<div class="card mt-4" id="itam">
    <div class="card-body">
        <h5 class="mb-1">ITAM — Asset Code Settings</h5>
        <p class="text-muted small mb-3">Configure auto-generated asset codes (e.g. <code>SG-LAP-000001</code>).</p>
        <form method="POST" action="{{ route('admin.settings.itam') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Asset Code Prefix <span class="text-danger">*</span></label>
                    <input type="text" name="itam_asset_prefix" id="itam_prefix"
                           class="form-control font-monospace text-uppercase @error('itam_asset_prefix') is-invalid @enderror"
                           value="{{ old('itam_asset_prefix', $settings->itam_asset_prefix ?? 'SG') }}"
                           maxlength="10" placeholder="SG" oninput="updateCodePreview()">
                    <div class="form-text">e.g. <strong>SG</strong> → SG-LAP-000001</div>
                    @error('itam_asset_prefix')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Sequence Padding <span class="text-danger">*</span></label>
                    <input type="number" name="itam_code_padding" id="itam_padding"
                           class="form-control @error('itam_code_padding') is-invalid @enderror"
                           value="{{ old('itam_code_padding', $settings->itam_code_padding ?? 6) }}"
                           min="1" max="10" oninput="updateCodePreview()">
                    <div class="form-text"><code>6</code> → 000001</div>
                    @error('itam_code_padding')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Company URL <span class="text-muted small">(on labels)</span></label>
                    <input type="url" name="itam_company_url"
                           class="form-control @error('itam_company_url') is-invalid @enderror"
                           value="{{ old('itam_company_url', $settings->itam_company_url ?? '') }}"
                           placeholder="https://noc.samirgroup.net">
                    <div class="form-text">Printed on QR asset labels.</div>
                    @error('itam_company_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12">
                    <div class="p-2 bg-light rounded border small">
                        <strong>Preview:</strong> <code id="itam_preview"></code>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Save ITAM Settings</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
function updateCodePreview() {
    const prefix  = (document.getElementById('itam_prefix')?.value || 'SG').toUpperCase();
    const padding = parseInt(document.getElementById('itam_padding')?.value || 6);
    const seq     = String(1).padStart(isNaN(padding) ? 6 : padding, '0');
    const el = document.getElementById('itam_preview');
    if (el) el.textContent = `${prefix}-LAP-${seq}`;
}
document.addEventListener('DOMContentLoaded', updateCodePreview);

function testGraphConnection() {
    const btn       = document.getElementById('testGraphBtn');
    const result    = document.getElementById('graphTestResult');
    const tenantId  = document.querySelector('[name="graph_tenant_id"]').value;
    const clientId  = document.querySelector('[name="graph_client_id"]').value;
    const secret    = document.querySelector('[name="graph_client_secret"]').value;

    if (!tenantId || !clientId) {
        result.innerHTML = '<span class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Enter Tenant ID and Client ID</span>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
    result.innerHTML = '<span class="text-muted small">Connecting…</span>';

    fetch('{{ route('admin.settings.test-graph') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        // Send null secret when field is blank — server falls back to the saved value
        body: JSON.stringify({ tenant_id: tenantId, client_id: clientId, client_secret: secret || null })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.innerHTML = '<span class="text-success small"><i class="bi bi-check-circle-fill me-1"></i>' + data.message + '</span>';
        } else {
            result.innerHTML = '<span class="text-danger small"><i class="bi bi-x-circle-fill me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(() => {
        result.innerHTML = '<span class="text-danger small"><i class="bi bi-x-circle-fill me-1"></i>Request failed</span>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i>Test Connection';
    });
}

function testSmtpConnection() {
    const btn    = document.getElementById('testSmtpBtn');
    const result = document.getElementById('smtpTestResult');
    const addr   = document.getElementById('smtpTestAddr').value.trim();

    if (!addr) {
        result.innerHTML = '<span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Enter a recipient email address first</span>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';
    result.innerHTML = '<span class="text-muted">Connecting to SMTP server…</span>';

    fetch('{{ route('admin.settings.test-smtp') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        body: JSON.stringify({ to: addr })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>' + data.message + '</span>';
        } else {
            result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(() => {
        result.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill me-1"></i>Request failed</span>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send me-1"></i>Send Test';
    });
}

function testMerakiConnection() {
    const btn    = document.getElementById('testMerakiBtn');
    const result = document.getElementById('merakiTestResult');
    const apiKey = document.querySelector('[name="meraki_api_key"]').value;
    const orgId  = document.querySelector('[name="meraki_org_id"]').value;

    if (!orgId) {
        result.innerHTML = '<span class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Please enter an Organisation ID</span>';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Testing…';
    result.innerHTML = '<span class="text-muted small">Connecting…</span>';

    fetch('{{ route('admin.settings.test-meraki') }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': '{{ csrf_token() }}'
        },
        // Send null api_key when field is blank — server falls back to the saved value
        body: JSON.stringify({ api_key: apiKey || null, org_id: orgId })
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            result.innerHTML = '<span class="text-success small"><i class="bi bi-check-circle-fill me-1"></i>' + data.message + '</span>';
        } else {
            result.innerHTML = '<span class="text-danger small"><i class="bi bi-x-circle-fill me-1"></i>' + data.message + '</span>';
        }
    })
    .catch(() => {
        result.innerHTML = '<span class="text-danger small"><i class="bi bi-x-circle-fill me-1"></i>Request failed</span>';
    })
    .finally(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-plug me-1"></i>Test Connection';
    });
}
</script>
@endpush
