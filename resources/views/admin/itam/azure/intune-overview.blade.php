@extends('layouts.admin')
@section('title', 'Intune Device Overview')

@section('content')
<div class="container-fluid py-4">

    {{-- Header --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0">
                <i class="bi bi-display me-2 text-info"></i>Intune Device Overview
            </h4>
            <small class="text-muted">Intune-synced devices only — TeamViewer IDs, versions, and completeness status</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ request()->fullUrlWithQuery(['export' => 1]) }}" class="btn btn-outline-success btn-sm">
                <i class="bi bi-download me-1"></i>Export CSV
            </a>
            <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif

    {{-- Stats Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-3 fw-bold text-primary">{{ $stats['total'] }}</div>
                <div class="small text-muted">Intune Synced</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-3 fw-bold text-success">{{ $stats['has_tv'] }}</div>
                <div class="small text-muted">Have TeamViewer</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-3 fw-bold text-danger">{{ $stats['missing_tv'] }}</div>
                <div class="small text-muted">Missing TV</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-3 fw-bold text-{{ $stats['linked_asset'] === $stats['total'] ? 'success' : 'warning' }}">{{ $stats['linked_asset'] }}</div>
                <div class="small text-muted">Linked to Asset</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-3 fw-bold text-info">{{ $stats['linked_user'] }}</div>
                <div class="small text-muted">Have UPN</div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card border-0 shadow-sm text-center py-3">
                <div class="fs-3 fw-bold text-secondary">{{ $stats['total'] - $stats['linked_asset'] }}</div>
                <div class="small text-muted">No Asset Link</div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Search device / UPN..." value="{{ request('search') }}" style="max-width:220px">
                <div class="form-check form-check-inline ms-2">
                    <input class="form-check-input" type="checkbox" name="missing_tv" value="1" id="chkMissingTv"
                           {{ request('missing_tv') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="chkMissingTv">
                        <span class="text-danger">Missing TV ID</span>
                    </label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="missing_asset" value="1" id="chkMissingAsset"
                           {{ request('missing_asset') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="chkMissingAsset">
                        <span class="text-warning">Missing Asset</span>
                    </label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="missing_user" value="1" id="chkMissingUser"
                           {{ request('missing_user') ? 'checked' : '' }}>
                    <label class="form-check-label small" for="chkMissingUser">
                        <span class="text-secondary">Missing UPN</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Filter</button>
                @if(request()->anyFilled(['search','missing_tv','missing_asset','missing_user']))
                <a href="{{ route('admin.itam.azure.intune-overview') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                @endif
            </form>
        </div>
    </div>

    {{-- Table --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0" style="font-size:.85rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Device</th>
                            <th>Asset</th>
                            <th>UPN / User</th>
                            <th>Assigned Employee</th>
                            <th class="text-center">
                                <i class="bi bi-display-fill text-warning"></i> TeamViewer ID
                            </th>
                            <th class="text-center">TV Version</th>
                            <th>CPU</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">HW Synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($devices as $az)
                        @php
                            $hasTv     = !empty($az->teamviewer_id);
                            $hasAsset  = !empty($az->device_id);
                            $hasUpn    = !empty($az->upn);
                            $employee  = $az->device?->currentAssignment?->employee;
                            $hasUser   = $employee !== null;
                        @endphp
                        <tr onclick="window.location='{{ route('admin.itam.azure.show', $az) }}'" style="cursor:pointer">
                            <td class="ps-3 fw-semibold">
                                <a href="{{ route('admin.itam.azure.show', $az) }}" class="text-decoration-none text-dark" onclick="event.stopPropagation()">
                                    {{ $az->display_name }}
                                </a>
                                <div class="small text-muted">{{ $az->os }}</div>
                            </td>
                            <td>
                                @if($hasAsset && $az->device)
                                    <a href="{{ route('admin.devices.show', $az->device) }}" class="text-decoration-none small font-monospace" onclick="event.stopPropagation()">
                                        {{ $az->device->asset_code ?: $az->device->name }}
                                    </a>
                                @else
                                    <span class="badge bg-warning text-dark" style="font-size:.7rem">
                                        <i class="bi bi-exclamation-triangle me-1"></i>No Asset
                                    </span>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $az->upn ?: '—' }}</td>
                            <td class="small">
                                @if($hasUser)
                                    <i class="bi bi-person-fill-check text-success me-1"></i>{{ $employee->name }}
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($hasTv)
                                    <span class="font-monospace fw-semibold text-dark">{{ $az->teamviewer_id }}</span>
                                @else
                                    <span class="badge bg-danger bg-opacity-75" style="font-size:.7rem">
                                        <i class="bi bi-x-circle me-1"></i>Missing
                                    </span>
                                @endif
                            </td>
                            <td class="text-center font-monospace small">
                                @if($az->tv_version)
                                    <span class="badge bg-secondary bg-opacity-50 text-dark">v{{ $az->tv_version }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="small text-muted" style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                title="{{ $az->cpu_name }}">
                                {{ $az->cpu_name ? Str::limit($az->cpu_name, 35) : '—' }}
                            </td>
                            <td class="text-center">
                                @php
                                    $missingCount = (!$hasTv ? 1 : 0) + (!$hasAsset ? 1 : 0) + (!$hasUser ? 1 : 0);
                                @endphp
                                @if($missingCount === 0)
                                    <span class="badge bg-success" style="font-size:.7rem"><i class="bi bi-check-all"></i> Complete</span>
                                @else
                                    <span class="badge bg-{{ $missingCount === 3 ? 'danger' : 'warning' }} text-dark" style="font-size:.7rem">
                                        {{ $missingCount }} missing
                                    </span>
                                @endif
                            </td>
                            <td class="text-center small text-muted">
                                {{ $az->net_data_synced_at?->diffForHumans() ?? '—' }}
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                <i class="bi bi-info-circle me-1"></i>No Intune-synced devices found.
                                Run <code>php artisan intune:sync-net-data</code> to populate.
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $devices->links() }}</div>

</div>
@endsection
