@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-exclamation me-2 text-warning"></i>Warranty Tracker</h4>
        <small class="text-muted">Monitor device warranty status across all branches</small>
    </div>
</div>

{{-- Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-danger border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Expired</div>
                <div class="fs-2 fw-bold text-danger">{{ $expiredCount }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-warning border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Expiring Soon (90 days)</div>
                <div class="fs-2 fw-bold text-warning">{{ $expiringCount }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm border-start border-success border-4">
            <div class="card-body text-center">
                <div class="text-muted small">Valid</div>
                <div class="fs-2 fw-bold text-success">{{ $validCount }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search name/serial/model..." value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Warranty Status</option>
            <option value="expired" {{ request('status') == 'expired' ? 'selected' : '' }}>Expired</option>
            <option value="expiring" {{ request('status') == 'expiring' ? 'selected' : '' }}>Expiring (90 days)</option>
            <option value="valid" {{ request('status') == 'valid' ? 'selected' : '' }}>Valid</option>
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
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            @foreach(['router','switch','firewall','server','ap','ucm','laptop','desktop','monitor','printer'] as $t)
            <option value="{{ $t }}" {{ request('type') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.devices.warranty') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($devices->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-shield-exclamation display-4 d-block mb-2"></i>No devices with warranty dates found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>Type</th>
                        <th>Serial</th>
                        <th>Branch</th>
                        <th>Purchase Date</th>
                        <th>Warranty Expiry</th>
                        <th>Status</th>
                        <th>Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $dev)
                    @php
                        $daysLeft = $dev->warrantyDaysLeft();
                        $isExpired = $dev->isWarrantyExpired();
                        $isExpiring = !$isExpired && $daysLeft !== null && $daysLeft <= 90;
                    @endphp
                    <tr class="{{ $isExpired ? 'table-danger' : ($isExpiring ? 'table-warning' : '') }}">
                        <td class="fw-semibold">
                            <a href="{{ route('admin.devices.show', $dev) }}" class="text-decoration-none">{{ $dev->name }}</a>
                        </td>
                        <td><span class="badge {{ $dev->typeBadgeClass() }}">{{ $dev->typeLabel() }}</span></td>
                        <td class="text-muted">{{ $dev->serial_number ?: '—' }}</td>
                        <td>{{ $dev->branch?->name ?: '—' }}</td>
                        <td class="text-muted">{{ $dev->purchase_date?->format('M d, Y') ?: '—' }}</td>
                        <td class="fw-semibold">{{ $dev->warranty_expiry->format('M d, Y') }}</td>
                        <td>
                            @if($isExpired)
                            <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i>Expired</span>
                            @elseif($isExpiring)
                            <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Expiring</span>
                            @else
                            <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Valid</span>
                            @endif
                        </td>
                        <td>
                            @if($isExpired)
                            <span class="text-danger fw-bold">{{ abs($daysLeft) }}d ago</span>
                            @elseif($daysLeft !== null)
                            <span class="{{ $daysLeft <= 30 ? 'text-danger' : ($daysLeft <= 90 ? 'text-warning' : 'text-success') }} fw-bold">{{ $daysLeft }}d</span>
                            @else
                            —
                            @endif
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
