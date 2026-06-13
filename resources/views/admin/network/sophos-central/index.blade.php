@extends('layouts.admin')
@section('title', 'Sophos Central')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-cloud-fill me-2"></i>Sophos Central</h4>
        <div class="d-flex align-items-center gap-2">
            <span class="text-muted small">
                Last sync: {{ $settings->sophos_central_last_sync_at?->diffForHumans() ?? 'never' }}
            </span>
            @can('manage-sophos')
            <form method="POST" action="{{ route('admin.network.sophos-central.sync') }}">
                @csrf
                <button class="btn btn-primary btn-sm" {{ $settings->sophos_central_client_id ? '' : 'disabled' }}>
                    <i class="bi bi-arrow-repeat me-1"></i>Sync Now
                </button>
            </form>
            @endcan
            @can('manage-settings')
            <a href="{{ route('admin.settings.index') }}#sophos-central" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i>API Settings
            </a>
            @endcan
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" style="white-space: pre-line">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" style="white-space: pre-line">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    @if(! $settings->sophos_central_client_id)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Sophos Central API credentials are not configured yet.
        @can('manage-settings')
            Configure them under <a href="{{ route('admin.settings.index') }}#sophos-central">Settings → Sophos Central</a>.
        @endcan
    </div>
    @endif

    {{-- Summary cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Access Points</div>
                    <div class="fs-4 fw-semibold">{{ $apTotal }}</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">APs Online / Offline</div>
                    <div class="fs-4 fw-semibold">
                        <span class="text-success">{{ $apOnline }}</span>
                        <span class="text-muted fs-6">/</span>
                        <span class="{{ $apOffline ? 'text-danger' : 'text-muted' }}">{{ $apOffline }}</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Firewalls in Central</div>
                    <div class="fs-4 fw-semibold">{{ $fwTotal }} <span class="fs-6 text-muted">({{ $fwConnected }} connected)</span></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3">
                    <div class="text-muted small">Open Central Alerts</div>
                    <div class="fs-4 fw-semibold {{ $openAlerts->count() ? 'text-danger' : '' }}">{{ $openAlerts->count() }}</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Open alerts --}}
    @if($openAlerts->isNotEmpty())
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent">
            <i class="bi bi-exclamation-octagon-fill text-danger me-1"></i><strong>Open Alerts</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Severity</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>First Seen</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($openAlerts as $alert)
                    <tr>
                        <td><span class="badge {{ $alert->severityBadgeClass() }}">{{ ucfirst($alert->severity) }}</span></td>
                        <td class="fw-semibold">{{ $alert->title }}</td>
                        <td class="text-muted small">{{ \Illuminate\Support\Str::limit($alert->message, 120) }}</td>
                        <td class="text-nowrap">{{ $alert->first_seen?->diffForHumans() }}</td>
                        <td class="text-nowrap">{{ $alert->last_seen?->diffForHumans() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Access points --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span><i class="bi bi-wifi me-1"></i><strong>Access Points</strong></span>
            <form method="GET" class="d-flex gap-2">
                <select name="ap_status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                    <option value="">All statuses</option>
                    <option value="online" {{ request('ap_status') === 'online' ? 'selected' : '' }}>Online</option>
                    <option value="offline" {{ request('ap_status') === 'offline' ? 'selected' : '' }}>Offline</option>
                </select>
                <input type="search" name="q" class="form-control form-control-sm" style="width:220px"
                       placeholder="Name / serial / MAC / site / IP" value="{{ request('q') }}">
                <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-search"></i></button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Site</th>
                        <th>Model</th>
                        <th>Serial</th>
                        <th>MAC</th>
                        <th>IP</th>
                        <th>Firmware</th>
                        <th>Last Seen (Central)</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($accessPoints as $ap)
                    <tr>
                        <td class="fw-semibold">{{ $ap->name ?? '-' }}</td>
                        <td><span class="badge {{ $ap->statusBadgeClass() }}">{{ ucfirst($ap->status ?? 'unknown') }}</span></td>
                        <td>{{ $ap->site_name ?? '-' }}</td>
                        <td>{{ $ap->model ?? '-' }}</td>
                        <td><code>{{ $ap->serial_number ?? '-' }}</code></td>
                        <td><code>{{ $ap->mac_address ?? '-' }}</code></td>
                        <td>{{ $ap->ip_address ?? '-' }}</td>
                        <td>{{ $ap->firmware_version ?? '-' }}</td>
                        <td>{{ $ap->central_last_seen_at?->diffForHumans() ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">
                        No access points synced yet{{ $settings->sophos_central_client_id ? ' — run a sync.' : '.' }}
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Firewalls (as seen by Central) --}}
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent">
            <i class="bi bi-shield-fill me-1"></i><strong>Firewalls (Sophos Central view)</strong>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Central Status</th>
                        <th>Serial</th>
                        <th>Model</th>
                        <th>Firmware</th>
                        <th>Upgrade Available</th>
                        <th>Group</th>
                        <th>Local Record</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($firewalls as $fw)
                    <tr>
                        <td class="fw-semibold">{{ $fw->name ?? $fw->hostname ?? '-' }}</td>
                        <td><span class="badge {{ $fw->statusBadgeClass() }}">{{ ucfirst($fw->status ?? 'unknown') }}</span></td>
                        <td><code>{{ $fw->serial_number ?? '-' }}</code></td>
                        <td>{{ $fw->model ?? '-' }}</td>
                        <td>{{ $fw->firmware_version ?? '-' }}</td>
                        <td>
                            @if($fw->hasFirmwareUpgrade())
                                <span class="badge bg-warning text-dark">Yes</span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>{{ $fw->group_name ?? '-' }}</td>
                        <td>
                            @if($fw->localFirewall)
                                <a href="{{ route('admin.network.sophos.show', $fw->localFirewall) }}">{{ $fw->localFirewall->name }}</a>
                            @else
                                <span class="text-muted small">not linked</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">
                        No firewalls synced from Sophos Central yet.
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
