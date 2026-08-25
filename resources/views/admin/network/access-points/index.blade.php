@extends('layouts.admin')
@section('title', 'Access Points')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <h4 class="mb-0"><i class="bi bi-router me-2"></i>Access Points</h4>
        @can('manage-access-points')
        <div class="d-flex gap-2">
            <form method="POST" action="{{ route('admin.network.access-points.ping-all') }}">
                @csrf
                <button class="btn btn-primary btn-sm" id="checkAllBtn">
                    <i class="bi bi-reception-4 me-1"></i>Check All Now
                </button>
            </form>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addApModal">
                <i class="bi bi-plus-lg me-1"></i>Add Access Point
            </button>
            <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="bi bi-upload me-1"></i>Import CSV
            </button>
        </div>
        @endcan
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- Summary --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Total APs</div><div class="fs-4 fw-semibold">{{ $total }}</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Up</div><div class="fs-4 fw-semibold text-success">{{ $up }}</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Down</div><div class="fs-4 fw-semibold {{ $down ? 'text-danger' : '' }}">{{ $down }}</div>
            </div></div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
                <div class="text-muted small">Unknown (not yet pinged)</div><div class="fs-4 fw-semibold text-secondary">{{ $unknown }}</div>
            </div></div>
        </div>
    </div>

    {{-- Filters --}}
    <form method="GET" class="row g-2 mb-3">
        <div class="col-auto">
            <select name="vendor" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All vendors</option>
                @foreach($vendors as $v)
                    <option value="{{ $v }}" {{ request('vendor') === $v ? 'selected' : '' }}>{{ ucfirst(str_replace('_',' ',$v)) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <option value="up"   {{ request('status') === 'up' ? 'selected' : '' }}>Up</option>
                <option value="down" {{ request('status') === 'down' ? 'selected' : '' }}>Down</option>
                <option value="unknown" {{ request('status') === 'unknown' ? 'selected' : '' }}>Unknown</option>
            </select>
        </div>
        <div class="col-auto">
            <select name="branch_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All branches</option>
                @foreach($branches as $b)
                    <option value="{{ $b->id }}" {{ (string) request('branch_id') === (string) $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <input type="search" name="q" class="form-control form-control-sm" style="width:240px"
                   placeholder="Name / serial / MAC / IP / site" value="{{ request('q') }}">
        </div>
        <div class="col-auto"><button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button></div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Status</th>
                        <th>Name</th>
                        <th>Vendor</th>
                        <th>Model</th>
                        <th>IP</th>
                        <th>Site / Branch</th>
                        <th>Firmware</th>
                        <th>Latency</th>
                        <th>Uptime</th>
                        <th>Last Seen</th>
                        <th>Mon.</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($accessPoints as $ap)
                    <tr>
                        <td><span class="badge {{ $ap->statusBadgeClass() }}">{{ strtoupper($ap->status) }}</span></td>
                        <td class="fw-semibold">
                            {{ $ap->name }}
                            @if($ap->device)
                                <a href="{{ route('admin.devices.show', $ap->device) }}" class="text-muted small ms-1"
                                   title="Asset {{ $ap->device->asset_code }}">
                                    <i class="bi bi-box-seam"></i> {{ $ap->device->asset_code }}
                                </a>
                            @else
                                <span class="badge bg-warning text-dark ms-1" title="Not in the asset register">No asset</span>
                            @endif
                        </td>
                        <td>{{ $ap->vendorLabel() }}</td>
                        <td>{{ $ap->model ?? '-' }}</td>
                        <td><code>{{ $ap->ip_address ?? '-' }}</code></td>
                        <td>{{ $ap->branch?->name ?? $ap->site ?? '-' }}</td>
                        <td class="small">{{ $ap->firmware ?? '-' }}</td>
                        <td>{{ $ap->ping_latency_ms !== null ? $ap->ping_latency_ms.' ms' : '-' }}</td>
                        <td class="small">{{ $ap->uptimeHuman() ?? '-' }}</td>
                        <td class="small text-nowrap">{{ $ap->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                        <td>
                            @can('manage-access-points')
                            <form method="POST" action="{{ route('admin.network.access-points.toggle', $ap) }}">
                                @csrf
                                <button class="btn btn-sm btn-link p-0" title="Toggle monitoring">
                                    <i class="bi {{ $ap->monitor_enabled ? 'bi-bell-fill text-success' : 'bi-bell-slash text-muted' }}"></i>
                                </button>
                            </form>
                            @else
                                <i class="bi {{ $ap->monitor_enabled ? 'bi-bell-fill text-success' : 'bi-bell-slash text-muted' }}"></i>
                            @endcan
                        </td>
                        <td class="text-end">
                            @can('manage-access-points')
                            <div class="btn-group btn-group-sm">
                                <form method="POST" action="{{ route('admin.network.access-points.ping', $ap) }}">
                                    @csrf
                                    <button class="btn btn-outline-info" title="Ping now"><i class="bi bi-reception-4"></i></button>
                                </form>
                                @unless($ap->device)
                                <form method="POST" action="{{ route('admin.network.access-points.asset', $ap) }}">
                                    @csrf
                                    <button class="btn btn-outline-success" title="Register as an asset"><i class="bi bi-box-seam"></i></button>
                                </form>
                                @endunless
                                <form method="POST" action="{{ route('admin.network.access-points.destroy', $ap) }}"
                                      onsubmit="return confirm('Remove {{ $ap->name }}?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-outline-danger" title="Remove"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="12" class="text-center text-muted py-4">
                        No access points yet.
                        @can('manage-access-points') Use <strong>Add Access Point</strong>, or <strong>Import CSV</strong> to load your Sophos Central export. @endcan
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-2">
        <i class="bi bi-info-circle me-1"></i>
        APX-series APs have no SNMP, so status is by ICMP ping over the branch VPN tunnels.
        <strong>Monitored APs are auto-checked every 5 minutes</strong> by the scheduler; use
        <em>Check All Now</em> for an immediate sweep. TP-Link/Omada APs can be added here too
        once the Omada controller is online.
    </p>
</div>

@push('scripts')
<script>
    document.getElementById('checkAllBtn')?.closest('form')?.addEventListener('submit', e => {
        const btn = document.getElementById('checkAllBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking…';
    });
</script>
@endpush

@can('manage-access-points')
{{-- Add access point --}}
<div class="modal fade" id="addApModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" action="{{ route('admin.network.access-points.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-lg me-2"></i>Add Access Point</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        For an AP the Sophos Central export does not cover — a TP-Link/Omada unit, a spare, or one
                        fitted before it was enrolled. It is registered in the asset register automatically and given
                        an asset code from the same sequence the CSV import uses.
                    </p>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required value="{{ old('name') }}"
                                   placeholder="e.g. RYD-Floor2-AP03">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Vendor <span class="text-danger">*</span></label>
                            <select name="vendor" class="form-select" required>
                                <option value="sophos"  @selected(old('vendor') === 'sophos')>Sophos</option>
                                <option value="tp_link" @selected(old('vendor') === 'tp_link')>TP-Link</option>
                                <option value="other"   @selected(old('vendor') === 'other')>Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Model</label>
                            <input type="text" name="model" class="form-control" value="{{ old('model') }}"
                                   placeholder="e.g. APX 320">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IP address</label>
                            <input type="text" name="ip_address" class="form-control" value="{{ old('ip_address') }}"
                                   placeholder="10.1.0.51">
                            <div class="form-text">Needed for ping monitoring.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Serial number</label>
                            <input type="text" name="serial_number" class="form-control" value="{{ old('serial_number') }}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">MAC address</label>
                            <input type="text" name="mac_address" class="form-control" value="{{ old('mac_address') }}"
                                   placeholder="c4:71:54:a1:b2:c3">
                        </div>
                        <div class="col-12">
                            <div class="alert alert-light border small mb-0">
                                <i class="bi bi-info-circle me-1"></i>
                                Give it a serial or a MAC if you have one — that is how the asset register recognises
                                it later, and how a re-import matches this row instead of adding a duplicate.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Branch</label>
                            <select name="branch_id" class="form-select">
                                <option value="">—</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected((string) old('branch_id') === (string) $b->id)>{{ $b->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Site label</label>
                            <input type="text" name="site" class="form-control" value="{{ old('site') }}"
                                   placeholder="Free text, as it appears in the controller">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Firmware</label>
                            <input type="text" name="firmware" class="form-control" value="{{ old('firmware') }}">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input type="hidden" name="monitor_enabled" value="0">
                                <input type="checkbox" name="monitor_enabled" value="1" class="form-check-input"
                                       id="apMonitorEnabled" checked>
                                <label class="form-check-label" for="apMonitorEnabled">Ping-monitor this AP</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add &amp; register asset</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Import modal --}}
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.network.access-points.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Import Access Points (Sophos Central CSV)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        In Sophos Central go to <strong>Wireless → Access Points</strong>, export to CSV, and upload it here.
                        Existing APs are matched by serial number and updated; new ones are added and linked to an asset record.
                    </p>
                    <input type="file" name="csv" class="form-control" accept=".csv,text/csv" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-upload me-1"></i>Import</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
