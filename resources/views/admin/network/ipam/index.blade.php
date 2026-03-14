@extends('layouts.admin')
@section('title', 'IPAM Subnets')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-diagram-3 me-2"></i>IPAM Subnets</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.network.ipam.search') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-search"></i> Global Search
            </a>
            @can('manage-network-settings')
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSubnetModal">
                <i class="bi bi-plus-lg"></i> Add Subnet
            </button>
            @endcan
        </div>
    </div>

    {{-- Branch Filter --}}
    <div class="mb-3">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <select name="branch" class="form-select form-select-sm" style="max-width:250px" onchange="this.form.submit()">
                <option value="">All Branches</option>
                @foreach($branches as $b)
                <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    {{-- Subnet Tree --}}
    @forelse($subnetTree as $branchName => $subnets)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent fw-semibold">
            <i class="bi bi-building me-1"></i> {{ $branchName }}
            <span class="badge bg-secondary ms-2">{{ $subnets->count() }} subnet(s)</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>CIDR</th>
                        <th>VLAN</th>
                        <th>Gateway</th>
                        <th>Description</th>
                        <th>Source</th>
                        <th>Utilization</th>
                        <th>Used / Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($subnets as $subnet)
                    @php
                        $pct = $subnet->utilizationPercent();
                        $barClass = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                    @endphp
                    <tr>
                        <td><code>{{ $subnet->cidr }}</code></td>
                        <td>{{ $subnet->vlan ?? '-' }}</td>
                        <td>{{ $subnet->gateway ?? '-' }}</td>
                        <td>{{ $subnet->description ?? '-' }}</td>
                        <td><span class="badge bg-secondary">{{ ucfirst($subnet->source) }}</span></td>
                        <td style="min-width:120px">
                            <div class="progress" style="height:18px">
                                <div class="progress-bar {{ $barClass }}" style="width:{{ $pct }}%">{{ $pct }}%</div>
                            </div>
                        </td>
                        <td>{{ $subnet->ip_reservations_count + $subnet->dhcp_leases_count }} / {{ $subnet->total_ips }}</td>
                        <td>
                            <div class="btn-group">
                                <a href="{{ route('admin.network.ipam.show', $subnet) }}" class="btn btn-sm btn-outline-primary" title="View Grid">
                                    <i class="bi bi-grid-3x3"></i>
                                </a>
                                @can('manage-network-settings')
                                <a href="{{ route('admin.network.ipam.edit', $subnet) }}" class="btn btn-sm btn-outline-secondary" title="Edit Subnet">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @empty
    <div class="text-center text-muted py-5">
        <i class="bi bi-diagram-3 fs-1 d-block mb-2"></i>
        No subnets found. Add one to get started.
    </div>
    @endforelse
</div>

{{-- Add Subnet Modal --}}
@can('manage-network-settings')
<div class="modal fade" id="addSubnetModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.network.ipam.store') }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Subnet</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Branch <span class="text-danger">*</span></label>
                        <select name="branch_id" class="form-select" required>
                            <option value="">Select Branch</option>
                            @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">CIDR <span class="text-danger">*</span></label>
                        <input type="text" name="cidr" class="form-control" placeholder="192.168.1.0/24" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">VLAN</label>
                            <input type="number" name="vlan" class="form-control" min="1" max="4094">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Gateway</label>
                            <input type="text" name="gateway" class="form-control" placeholder="192.168.1.1">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Subnet</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan
@endsection
