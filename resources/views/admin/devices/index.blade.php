@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-cpu me-2 text-primary"></i>Device Inventory</h4>
        <small class="text-muted">All managed assets across branches</small>
    </div>
    @can('manage-assets')
    <a href="{{ route('admin.devices.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add Device
    </a>
    @endcan
</div>


{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Name / IP / MAC / Serial" value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            @foreach($types as $t)
            <option value="{{ $t }}" {{ request('type') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
            @endforeach
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
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="active"      {{ request('status') == 'active'      ? 'selected' : '' }}>Active</option>
            <option value="retired"     {{ request('status') == 'retired'     ? 'selected' : '' }}>Retired</option>
            <option value="maintenance" {{ request('status') == 'maintenance' ? 'selected' : '' }}>Maintenance</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($devices->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cpu display-4 d-block mb-2"></i>No devices found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Status</th><th>Type</th><th>Name</th><th>Model</th>
                        <th>IP</th><th>MAC</th><th>Branch</th><th>Location</th>
                        <th class="text-center">Credentials</th><th>Updated</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $d)
                    <tr>
                        <td><span class="badge {{ $d->statusBadgeClass() }}">{{ ucfirst($d->status) }}</span></td>
                        <td>
                            <span class="badge {{ $d->typeBadgeClass() }}">
                                <i class="bi {{ $d->typeIcon() }} me-1"></i>{{ $d->typeLabel() }}
                            </span>
                        </td>
                        <td class="fw-semibold">{{ $d->name }}</td>
                        <td class="text-muted">{{ $d->model ?: '—' }}</td>
                        <td class="font-monospace">{{ $d->ip_address ?: '—' }}</td>
                        <td class="font-monospace text-muted">{{ $d->mac_address ?: '—' }}</td>
                        <td>{{ $d->branch?->name ?: '—' }}</td>
                        <td class="text-muted">{{ Str::limit($d->location_description, 30) ?: '—' }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $d->credentials->count() > 0 ? 'primary' : 'light text-muted border' }}">
                                {{ $d->credentials->count() }}
                            </span>
                        </td>
                        <td class="text-muted">{{ $d->updated_at->diffForHumans() }}</td>
                        <td>
                            <a href="{{ route('admin.devices.show', $d) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            @can('manage-assets')
                            <a href="{{ route('admin.devices.edit', $d) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            @endcan
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
