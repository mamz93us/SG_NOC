@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Access Gateway</h4>
        <small class="text-muted">Front the legacy app on <code>arcmate.samirgroup.net</code> — upstream URL, IP allowlist &amp; audit</small>
    </div>
    @can('view-agw-audit')
    <a href="{{ route('admin.access-gateway.audit') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-eye me-1"></i>Audit Log
    </a>
    @endcan
</div>

@if($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
</div>
@endif

{{-- ── Gateway settings ─────────────────────────────────────────────── --}}
@can('manage-agw-settings')
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent"><strong><i class="bi bi-sliders me-1"></i>Gateway Settings</strong></div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.access-gateway.settings') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-7">
                    <label class="form-label fw-semibold">App URL (upstream)</label>
                    <input type="url" name="agw_backend_url"
                           class="form-control @error('agw_backend_url') is-invalid @enderror"
                           value="{{ old('agw_backend_url', $settings->agw_backend_url) }}"
                           placeholder="http://10.0.0.20:8891">
                    <div class="form-text">The legacy IIS app's local HTTP address. The gateway proxies here; changes apply within its refresh window (no restart).</div>
                    @error('agw_backend_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch">
                        <input type="hidden" name="agw_enforce_ip_acl" value="0">
                        <input type="checkbox" class="form-check-input" role="switch" id="enforceAcl"
                               name="agw_enforce_ip_acl" value="1" {{ old('agw_enforce_ip_acl', $settings->agw_enforce_ip_acl) ? 'checked' : '' }}>
                        <label class="form-check-label" for="enforceAcl">Enforce IP allowlist</label>
                    </div>
                    <div class="form-text">Off = allow all source IPs (still audited).</div>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-save me-1"></i>Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ── Dynamic (branch) entries ─────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <strong><i class="bi bi-hdd-network me-1"></i>Branch WAN IPs <span class="badge bg-secondary ms-1">{{ $dynamic->count() }}</span></strong>
        <form method="POST" action="{{ route('admin.access-gateway.sync') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Sync now</button>
        </form>
    </div>
    <div class="card-body p-0">
        @if($dynamic->isEmpty())
            <div class="text-center text-muted py-4">No branch WAN IPs synced yet. Click <strong>Sync now</strong>, or wait for the 5-minute scheduler.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Branch</th>
                        <th>CIDR</th>
                        <th class="text-center">Status</th>
                        <th>Note</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($dynamic as $row)
                    <tr>
                        <td class="ps-3"><strong>{{ strtoupper($row->branch) }}</strong></td>
                        <td><code>{{ $row->cidr }}</code></td>
                        <td class="text-center">
                            @if($row->active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-secondary">Inactive</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $row->note ?: '—' }}</td>
                        <td class="text-end pe-3">
                            <form method="POST" action="{{ route('admin.access-gateway.allowlist.toggle', $row->id) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-secondary">
                                    {{ $row->active ? 'Disable' : 'Enable' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="card-footer bg-transparent text-muted small">
            Branch rows are synced from live agent WAN IPs and can't be deleted here — disable a row to block a branch, or disable its branch agent.
        </div>
        @endif
    </div>
</div>

{{-- ── Manual entries ───────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <strong><i class="bi bi-list-check me-1"></i>Manual Allowlist <span class="badge bg-secondary ms-1">{{ $manual->count() }}</span></strong>
    </div>
    <div class="card-body p-0">
        @if($manual->isEmpty())
            <div class="text-center text-muted py-4">No manual entries. Add fixed office/admin ranges below.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">CIDR</th>
                        <th class="text-center">Status</th>
                        <th>Note</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($manual as $row)
                    <tr>
                        <td class="ps-3"><code>{{ $row->cidr }}</code></td>
                        <td class="text-center">
                            @if($row->active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-secondary">Inactive</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $row->note ?: '—' }}</td>
                        <td class="text-end pe-3">
                            <form method="POST" action="{{ route('admin.access-gateway.allowlist.toggle', $row->id) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $row->active ? 'Disable' : 'Enable' }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.access-gateway.allowlist.destroy', $row->id) }}" class="d-inline"
                                  onsubmit="return confirm('Remove {{ $row->cidr }} from the allowlist?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="card-footer bg-transparent">
        <form method="POST" action="{{ route('admin.access-gateway.allowlist.store') }}">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">IP / CIDR <span class="text-danger">*</span></label>
                    <input type="text" name="cidr" class="form-control @error('cidr') is-invalid @enderror"
                           value="{{ old('cidr') }}" placeholder="197.1.2.0/24 or 197.1.2.3" required>
                    @error('cidr') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Note</label>
                    <input type="text" name="note" class="form-control" value="{{ old('note') }}" placeholder="e.g. HQ office range">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus me-1"></i>Add</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── Blocklist ────────────────────────────────────────────────────── --}}
<div class="card shadow-sm border-0 mb-4 border-danger-subtle">
    <div class="card-header bg-transparent d-flex align-items-center justify-content-between">
        <strong class="text-danger"><i class="bi bi-shield-fill-x me-1"></i>Blocklist <span class="badge bg-danger ms-1">{{ $blocked->count() }}</span></strong>
    </div>
    <div class="card-body p-0">
        @if($blocked->isEmpty())
            <div class="text-center text-muted py-4">Nothing blocked. Blocked IPs are denied even when the allowlist is off.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">CIDR</th>
                        <th class="text-center">Status</th>
                        <th>Note</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($blocked as $row)
                    <tr>
                        <td class="ps-3"><code>{{ $row->cidr }}</code></td>
                        <td class="text-center">
                            @if($row->active)
                                <span class="badge bg-danger">Blocked</span>
                            @else
                                <span class="badge bg-secondary">Inactive</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $row->note ?: '—' }}</td>
                        <td class="text-end pe-3">
                            <form method="POST" action="{{ route('admin.access-gateway.blocklist.toggle', $row->id) }}" class="d-inline">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ $row->active ? 'Unblock' : 'Re-block' }}</button>
                            </form>
                            <form method="POST" action="{{ route('admin.access-gateway.blocklist.destroy', $row->id) }}" class="d-inline"
                                  onsubmit="return confirm('Remove {{ $row->cidr }} from the blocklist?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
    <div class="card-footer bg-transparent">
        <form method="POST" action="{{ route('admin.access-gateway.blocklist.store') }}">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">IP / CIDR to block <span class="text-danger">*</span></label>
                    <input type="text" name="cidr" class="form-control @error('blocklist_cidr') is-invalid @enderror"
                           value="{{ old('cidr') }}" placeholder="203.0.113.7 or 203.0.113.0/24" required>
                    @error('blocklist_cidr') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Note</label>
                    <input type="text" name="note" class="form-control" placeholder="e.g. abusive scanner">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-danger w-100"><i class="bi bi-shield-x me-1"></i>Block</button>
                </div>
            </div>
        </form>
    </div>
</div>

@endsection
