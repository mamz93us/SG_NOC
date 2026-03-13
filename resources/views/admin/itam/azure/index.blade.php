@extends('layouts.admin')
@section('title', 'Azure Device Sync')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-microsoft me-2"></i>Azure Device Sync</h4>
            @if($lastSync)
            <small class="text-muted">Last sync: {{ \Carbon\Carbon::parse($lastSync)->diffForHumans() }}</small>
            @endif
        </div>
        <form action="{{ route('admin.itam.azure.sync') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Now
            </button>
        </form>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    {{-- Pending Links --}}
    @if($pending->count() > 0)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-link-45deg me-1"></i>Pending Links</span>
            <span class="badge bg-warning text-dark">{{ $pending->count() }}</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Azure Device</th>
                        <th>OS</th>
                        <th>Serial</th>
                        <th>UPN</th>
                        <th>Proposed Link</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pending as $az)
                    <tr>
                        <td class="fw-semibold">{{ $az->display_name }}</td>
                        <td>{{ $az->os }}</td>
                        <td class="font-monospace small">{{ $az->serial_number ?: '—' }}</td>
                        <td class="small">{{ $az->upn ?: '—' }}</td>
                        <td>
                            @if($az->device)
                            <a href="{{ route('admin.devices.show', $az->device) }}" class="text-decoration-none">
                                <i class="bi bi-pc-display me-1"></i>{{ $az->device->name }}
                            </a>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <form action="{{ route('admin.itam.azure.approve', $az) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success" title="Approve Link">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form action="{{ route('admin.itam.azure.reject', $az) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                                <a href="{{ route('admin.itam.azure.create-device', $az) }}" class="btn btn-sm btn-outline-primary" title="Create New Device">
                                    <i class="bi bi-plus-lg"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- All Azure Devices --}}
    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold">All Azure Devices</span>
        </div>
        <div class="card-body border-bottom pb-3">
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="{{ request('search') }}" style="max-width:250px">
                <select name="status" class="form-select form-select-sm" style="max-width:150px">
                    <option value="">All Status</option>
                    @foreach($statuses as $s)
                    <option value="{{ $s }}" {{ request('status')===$s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                @if(request()->anyFilled(['search','status']))
                <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                @endif
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Device Name</th>
                        <th>OS</th>
                        <th>Serial</th>
                        <th>UPN</th>
                        <th>Status</th>
                        <th>Linked Asset</th>
                        <th>Last Sync</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($azureDevices as $az)
                    <tr style="cursor:pointer" onclick="azShowDetail({{ $az->id }})">
                        <td class="fw-semibold">{{ $az->display_name }}</td>
                        <td>{{ $az->os }}{{ $az->os_version ? ' '.$az->os_version : '' }}</td>
                        <td class="font-monospace small">{{ $az->serial_number ?: '—' }}</td>
                        <td class="small">{{ $az->upn ?: '—' }}</td>
                        <td><span class="badge bg-{{ $az->linkStatusBadgeClass() }}">{{ $az->linkStatusLabel() }}</span></td>
                        <td>
                            @if($az->device)
                            <a href="{{ route('admin.devices.show', $az->device) }}" class="text-decoration-none small" onclick="event.stopPropagation()">{{ $az->device->name }}</a>
                            @else
                            <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $az->last_sync_at?->diffForHumans() }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No Azure devices synced yet. Click "Sync Now" to begin.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $azureDevices->links() }}</div>
</div>

{{-- ── Azure Device Detail Modal ──────────────────────────────────── --}}
<div class="modal fade" id="azDetailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold" id="azDetailTitle">
                    <i class="bi bi-microsoft me-1"></i><span id="azDetailName">Loading…</span>
                </h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="azDetailBody">
                <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Loading…</div>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
const azDetailUrl = '{{ rtrim(route("admin.itam.azure.index"), "/") }}/';
const azCsrf      = document.querySelector('meta[name="csrf-token"]')?.content || '';

function azShowDetail(id) {
    document.getElementById('azDetailName').textContent = 'Loading…';
    document.getElementById('azDetailBody').innerHTML   = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div> Loading…</div>';
    new bootstrap.Modal(document.getElementById('azDetailModal')).show();

    fetch(azDetailUrl + id, { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(d => {
            document.getElementById('azDetailName').textContent = d.display_name;

            const badge = statusBadge(d.link_status);
            let linked = '';
            if (d.linked_device) {
                const dev = d.linked_device;
                linked = `<a href="${dev.url}" class="text-decoration-none fw-semibold"><i class="bi bi-cpu me-1"></i>${dev.name}</a>
                          <span class="text-muted small ms-1">${dev.asset_code ? '· ' + dev.asset_code : ''} ${dev.type ? '· ' + dev.type : ''}</span>
                          ${dev.serial ? '<br><span class="font-monospace small text-muted">SN: ' + dev.serial + '</span>' : ''}
                          ${dev.model  ? '<br><span class="small text-muted">' + dev.model + '</span>' : ''}
                          ${dev.branch ? '<br><span class="small text-muted"><i class="bi bi-geo-alt me-1"></i>' + dev.branch + '</span>' : ''}`;
            } else {
                linked = '<span class="text-muted">Not linked to any device</span>';
            }

            // Build raw_data extra info
            let extra = '';
            if (d.raw_data && typeof d.raw_data === 'object') {
                const keys = ['manufacturer', 'model', 'managementType', 'complianceState', 'isManaged', 'trustType', 'approximateLastSignInDateTime'];
                keys.forEach(k => {
                    if (d.raw_data[k] !== undefined && d.raw_data[k] !== null && d.raw_data[k] !== '') {
                        extra += `<tr><td class="text-muted small">${k}</td><td class="small">${d.raw_data[k]}</td></tr>`;
                    }
                });
            }

            // Proposed Import Info
            fetch(azDetailUrl + id + '/preview-import')
                .then(r => r.json())
                .then(p => {
                    let importForm = '';
                    if (d.link_status === 'unlinked') {
                        importForm = `
                        <div class="mt-4 border-top pt-3">
                            <p class="fw-bold text-primary mb-2"><i class="bi bi-box-arrow-in-right me-1"></i>Import & Approve for ITAM</p>
                            <form action="${azDetailUrl}${id}/import" method="POST" class="bg-primary bg-opacity-10 p-3 rounded">
                                <input type="hidden" name="_token" value="${azCsrf}">
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="small fw-semibold">Asset Category</label>
                                        <select name="type" class="form-select form-select-sm">
                                            <option value="laptop" ${p.device_type === 'laptop' ? 'selected' : ''}>Laptop</option>
                                            <option value="desktop" ${p.device_type === 'desktop' ? 'selected' : ''}>Desktop</option>
                                            <option value="tablet" ${p.device_type === 'tablet' ? 'selected' : ''}>Tablet</option>
                                            <option value="server" ${p.device_type === 'server' ? 'selected' : ''}>Server</option>
                                            <option value="other" ${p.device_type === 'other' ? 'selected' : ''}>Other</option>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <label class="small fw-semibold">Generated Asset Code</label>
                                        <input type="text" name="asset_code" value="${p.proposed_code}" class="form-control form-control-sm font-monospace" required>
                                    </div>
                                    <div class="col-md-3">
                                        <button type="submit" class="btn btn-primary btn-sm w-100">Approve & Add</button>
                                    </div>
                                </div>
                                <div class="mt-2 small">
                                    ${p.proposed_user 
                                        ? `<i class="bi bi-person-check text-success me-1"></i>Matches User: <span class="fw-bold">${p.proposed_user.name}</span>` 
                                        : '<i class="bi bi-person-x text-warning me-1"></i>No matching user found by Email.'}
                                </div>
                            </form>
                        </div>`;
                    }

                    document.getElementById('azDetailBody').innerHTML = `
                    <div class="row g-3">
                        <div class="col-md-6">
                            <table class="table table-sm table-borderless mb-0">
                                <tr><td class="text-muted small">Display Name</td><td class="fw-semibold">${d.display_name}</td></tr>
                                <tr><td class="text-muted small">Azure Device ID</td><td class="font-monospace small text-break">${d.azure_id || '—'}</td></tr>
                                <tr><td class="text-muted small">Device Type</td><td>${d.device_type || '—'}</td></tr>
                                <tr><td class="text-muted small">OS</td><td>${d.os || '—'}</td></tr>
                                <tr><td class="text-muted small">OS Version</td><td class="font-monospace small">${d.os_version || '—'}</td></tr>
                                <tr><td class="text-muted small">Serial Number</td><td class="font-monospace">${d.serial || '—'}</td></tr>
                                <tr><td class="text-muted small">Manufacturer</td><td>${d.manufacturer || '—'}</td></tr>
                                <tr><td class="text-muted small">Model</td><td>${d.model || '—'}</td></tr>
                                <tr><td class="text-muted small">User (UPN)</td><td class="small">${d.upn || '—'}</td></tr>
                                <tr><td class="text-muted small">Enrolled</td><td class="small">${d.enrolled_at || '—'}</td></tr>
                                <tr><td class="text-muted small">Last Sync</td><td class="small">${d.last_sync || '—'}</td></tr>
                                <tr><td class="text-muted small">Status</td><td>${badge}</td></tr>
                                ${extra}
                            </table>
                        </div>
                        <div class="col-md-6">
                            <p class="fw-semibold small text-muted mb-1"><i class="bi bi-link-45deg me-1"></i>Linked Asset</p>
                            <div class="border rounded p-3 bg-light">${linked}</div>
                        </div>
                        <div class="col-12">${importForm}</div>
                    </div>`;
                });
        })
        .catch(() => {
            document.getElementById('azDetailBody').innerHTML = '<div class="alert alert-danger">Failed to load device details.</div>';
        });
}

function statusBadge(s) {
    const map = { linked: 'success', pending: 'warning', rejected: 'danger', unlinked: 'secondary' };
    return `<span class="badge bg-${map[s] || 'secondary'}">${s.charAt(0).toUpperCase()+s.slice(1)}</span>`;
}
</script>
@endpush
@endsection
