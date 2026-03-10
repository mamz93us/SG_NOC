@extends('layouts.admin')
@section('title', 'DHCP Lease — ' . $lease->ip_address)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-hdd-network me-2"></i>DHCP Lease: <code>{{ $lease->ip_address }}</code>
            @if($lease->is_conflict)
                <span class="badge bg-danger ms-2">CONFLICT</span>
            @endif
        </h4>
        <a href="{{ route('admin.network.dhcp.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <div class="row g-4">
        {{-- Lease Details --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">Lease Details</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th class="text-muted" style="width:35%">IP Address</th><td><code>{{ $lease->ip_address }}</code></td></tr>
                        <tr><th class="text-muted">MAC Address</th><td><code>{{ $lease->mac_address }}</code></td></tr>
                        <tr><th class="text-muted">Hostname</th><td>{{ $lease->hostname ?? '-' }}</td></tr>
                        <tr><th class="text-muted">Vendor</th><td>{{ $lease->vendor ?? '-' }}</td></tr>
                        <tr><th class="text-muted">VLAN</th><td>{{ $lease->vlan ?? '-' }}</td></tr>
                        <tr><th class="text-muted">Source</th><td><span class="badge {{ $lease->sourceBadgeClass() }}">{{ ucfirst($lease->source) }}</span></td></tr>
                        <tr><th class="text-muted">Source Device</th><td>{{ $lease->source_device ?? '-' }}</td></tr>
                        <tr><th class="text-muted">Last Seen</th><td>{{ $lease->last_seen?->format('Y-m-d H:i:s') ?? '-' }}</td></tr>
                        <tr><th class="text-muted">First Seen</th><td>{{ $lease->created_at?->format('Y-m-d H:i:s') ?? '-' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>

        {{-- Correlation Chain --}}
        <div class="col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-transparent fw-semibold">Device Correlation</div>
                <div class="card-body">
                    <div class="list-group list-group-flush">
                        {{-- Branch --}}
                        <div class="list-group-item d-flex align-items-center">
                            <i class="bi bi-building fs-5 me-3 text-primary"></i>
                            <div>
                                <div class="fw-semibold">Branch</div>
                                <div class="text-muted small">{{ $lease->branch?->name ?? 'Unknown' }}</div>
                            </div>
                        </div>

                        {{-- Switch --}}
                        <div class="list-group-item d-flex align-items-center">
                            <i class="bi bi-hdd-network fs-5 me-3 text-info"></i>
                            <div>
                                <div class="fw-semibold">Switch</div>
                                <div class="text-muted small">
                                    @if($lease->networkSwitch)
                                        {{ $lease->networkSwitch->name }} ({{ $lease->switch_serial }})
                                    @else
                                        {{ $lease->switch_serial ?? 'Unknown' }}
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Port --}}
                        <div class="list-group-item d-flex align-items-center">
                            <i class="bi bi-plug fs-5 me-3 text-warning"></i>
                            <div>
                                <div class="fw-semibold">Port</div>
                                <div class="text-muted small">{{ $lease->port_id ?? 'Unknown' }}</div>
                            </div>
                        </div>

                        {{-- Device --}}
                        <div class="list-group-item d-flex align-items-center">
                            <i class="bi bi-cpu fs-5 me-3 text-success"></i>
                            <div>
                                <div class="fw-semibold">Linked Device</div>
                                <div class="text-muted small">
                                    @if($lease->device)
                                        <a href="{{ route('admin.devices.show', $lease->device) }}">{{ $lease->device->name }}</a>
                                    @else
                                        Not correlated
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Subnet --}}
                        <div class="list-group-item d-flex align-items-center">
                            <i class="bi bi-diagram-3 fs-5 me-3 text-secondary"></i>
                            <div>
                                <div class="fw-semibold">Subnet</div>
                                <div class="text-muted small">
                                    @if($lease->subnet)
                                        <a href="{{ route('admin.network.ipam.show', $lease->subnet) }}">{{ $lease->subnet->cidr }}</a>
                                    @else
                                        Not linked
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
