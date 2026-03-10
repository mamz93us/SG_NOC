@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-up-circle me-2 text-info"></i>Firmware Tracker</h4>
        <small class="text-muted">Track firmware versions and identify devices needing updates</small>
    </div>
</div>

{{-- Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-danger border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Outdated Firmware</div>
                <div class="fs-2 fw-bold text-danger">{{ $outdatedCount }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-success border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Up to Date</div>
                <div class="fs-2 fw-bold text-success">{{ $uptodateCount }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-secondary border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Unknown / Not Set</div>
                <div class="fs-2 fw-bold text-secondary">{{ $unknownCount }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name/model..." value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Firmware Status</option>
            <option value="outdated" {{ request('status') == 'outdated' ? 'selected' : '' }}>Outdated</option>
            <option value="current" {{ request('status') == 'current' ? 'selected' : '' }}>Up to Date</option>
            <option value="unknown" {{ request('status') == 'unknown' ? 'selected' : '' }}>Unknown</option>
        </select>
    </div>
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.devices.firmware') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($devices->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-arrow-up-circle display-4 d-block mb-2"></i>No devices found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Model</th>
                        <th>Branch</th>
                        <th>Current Firmware</th>
                        <th>Latest Available</th>
                        <th>Status</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $dev)
                    @php $outdated = $dev->isFirmwareOutdated(); @endphp
                    <tr class="{{ $outdated ? 'table-warning' : '' }}">
                        <td class="fw-semibold">
                            <a href="{{ route('admin.devices.show', $dev) }}" class="text-decoration-none">{{ $dev->name }}</a>
                        </td>
                        <td><span class="badge {{ $dev->typeBadgeClass() }}">{{ $dev->typeLabel() }}</span></td>
                        <td class="text-muted">{{ $dev->model ?: '—' }}</td>
                        <td>{{ $dev->branch?->name ?: '—' }}</td>
                        <td><code>{{ $dev->firmware_version ?: '—' }}</code></td>
                        <td><code>{{ $dev->latest_firmware ?: '—' }}</code></td>
                        <td>
                            @if(!$dev->firmware_version && !$dev->latest_firmware)
                            <span class="badge bg-secondary"><i class="bi bi-question-circle me-1"></i>Unknown</span>
                            @elseif($outdated)
                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Outdated</span>
                            @else
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Current</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.devices.edit', $dev) }}" class="btn btn-sm btn-outline-primary" title="Edit device">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $devices->links() }}</div>
        @endif
    </div>
</div>

@endsection
