@extends('layouts.admin')
@section('title', 'DHCP Leases')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-hdd-network me-2"></i>DHCP Leases</h4>
    </div>

    {{-- Summary Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-primary">{{ number_format($totalLeases) }}</div>
                    <div class="text-muted small">Total Leases</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-info">{{ number_format($merakiLeases) }}</div>
                    <div class="text-muted small">Meraki</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-warning">{{ number_format($snmpLeases) }}</div>
                    <div class="text-muted small">Sophos / SNMP</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card text-center border-0 shadow-sm {{ $conflictCount > 0 ? 'border-danger' : '' }}">
                <div class="card-body">
                    <div class="fs-2 fw-bold text-danger">{{ $conflictCount }}</div>
                    <div class="text-muted small">Conflicts</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Filters --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-0">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">Source</label>
                    <select name="source" class="form-select form-select-sm">
                        <option value="">All Sources</option>
                        <option value="meraki" {{ request('source') == 'meraki' ? 'selected' : '' }}>Meraki</option>
                        <option value="sophos" {{ request('source') == 'sophos' ? 'selected' : '' }}>Sophos</option>
                        <option value="snmp" {{ request('source') == 'snmp' ? 'selected' : '' }}>SNMP</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-0">VLAN</label>
                    <input type="number" name="vlan" class="form-control form-control-sm" value="{{ request('vlan') }}" placeholder="VLAN">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-0">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="{{ request('search') }}" placeholder="IP, MAC, or hostname">
                </div>
                <div class="col-md-1">
                    <div class="form-check">
                        <input type="checkbox" name="conflicts" value="1" class="form-check-input" {{ request('conflicts') ? 'checked' : '' }}>
                        <label class="form-check-label small">Conflicts</label>
                    </div>
                </div>
                <div class="col-md-2 d-flex gap-1">
                    <button class="btn btn-sm btn-primary flex-fill"><i class="bi bi-search"></i> Filter</button>
                    <a href="{{ route('admin.network.dhcp.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    {{-- Leases Table --}}
    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>IP Address</th>
                        <th>MAC Address</th>
                        <th>Hostname</th>
                        <th>VLAN</th>
                        <th>Source</th>
                        <th>Branch</th>
                        <th>Switch / Port</th>
                        <th>Last Seen</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @forelse($leases as $lease)
                    <tr class="{{ $lease->is_conflict ? 'table-danger' : '' }}">
                        <td>
                            <code>{{ $lease->ip_address }}</code>
                            @if($lease->is_conflict)
                                <span class="badge bg-danger ms-1">CONFLICT</span>
                            @endif
                        </td>
                        <td><code class="text-muted">{{ $lease->mac_address }}</code></td>
                        <td>{{ $lease->hostname ?? '-' }}</td>
                        <td>{{ $lease->vlan ?? '-' }}</td>
                        <td><span class="badge {{ $lease->sourceBadgeClass() }}">{{ ucfirst($lease->source) }}</span></td>
                        <td>{{ $lease->branch?->name ?? '-' }}</td>
                        <td>
                            @if($lease->switch_serial)
                                {{ $lease->switch_serial }}
                                @if($lease->port_id) / {{ $lease->port_id }} @endif
                            @else
                                -
                            @endif
                        </td>
                        <td>{{ $lease->last_seen?->diffForHumans() ?? '-' }}</td>
                        <td>
                            <a href="{{ route('admin.network.dhcp.show', $lease) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No DHCP leases found.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if($leases->hasPages())
        <div class="card-footer border-0">{{ $leases->links() }}</div>
        @endif
    </div>
</div>
@endsection
