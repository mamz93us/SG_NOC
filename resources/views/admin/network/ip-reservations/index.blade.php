@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-hdd-rack me-2 text-primary"></i>IP Reservations</h4>
        <small class="text-muted">Static IP address management (IPAM)</small>
    </div>
    @can('manage-network-settings')
    <a href="{{ route('admin.network.ip-reservations.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Reserve IP
    </a>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="IP / Name / MAC / Owner" value="{{ request('search') }}">
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
        <select name="vlan" class="form-select form-select-sm">
            <option value="">All VLANs</option>
            @foreach($vlans as $v)
            <option value="{{ $v }}" {{ request('vlan') == $v ? 'selected' : '' }}>VLAN {{ $v }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="device_type" class="form-select form-select-sm">
            <option value="">All Types</option>
            @foreach($deviceTypes as $key => $label)
            <option value="{{ $key }}" {{ request('device_type') == $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.ip-reservations.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

{{-- Summary --}}
<div class="row g-3 mb-3">
    <div class="col-auto">
        <span class="badge bg-primary-subtle text-primary border px-3 py-2">
            <i class="bi bi-hdd-rack me-1"></i>{{ $reservations->total() }} reservations
        </span>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($reservations->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-hdd-rack display-4 d-block mb-2"></i>No IP reservations found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th>IP Address</th>
                        <th>Subnet</th>
                        <th>VLAN</th>
                        <th>Type</th>
                        <th>Device Name</th>
                        <th>MAC Address</th>
                        <th>Assigned To</th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($reservations as $r)
                    <tr>
                        <td class="fw-semibold">{{ $r->branch?->name ?: '—' }}</td>
                        <td class="font-monospace fw-bold">{{ $r->ip_address }}</td>
                        <td class="font-monospace text-muted">{{ $r->subnet ?: '—' }}</td>
                        <td>
                            @if($r->vlan)
                            <span class="badge bg-info-subtle text-info border">{{ $r->vlan }}</span>
                            @else — @endif
                        </td>
                        <td>
                            @if($r->device_type)
                            <span class="badge {{ $r->deviceTypeBadgeClass() }}">{{ $deviceTypes[$r->device_type] ?? ucfirst($r->device_type) }}</span>
                            @else — @endif
                        </td>
                        <td>{{ $r->device_name ?: '—' }}</td>
                        <td class="font-monospace text-muted">{{ $r->mac_address ?: '—' }}</td>
                        <td>{{ $r->assigned_to ?: '—' }}</td>
                        <td class="text-muted">{{ Str::limit($r->notes, 30) ?: '—' }}</td>
                        <td class="text-nowrap">
                            @can('manage-network-settings')
                            <a href="{{ route('admin.network.ip-reservations.edit', $r) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('admin.network.ip-reservations.destroy', $r) }}" class="d-inline" onsubmit="return confirm('Delete this IP reservation?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $reservations->links() }}</div>
        @endif
    </div>
</div>

@endsection
