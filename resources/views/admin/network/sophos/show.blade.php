@extends('layouts.admin')
@section('title', 'Sophos: ' . $firewall->name)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-shield-fill me-2"></i>{{ $firewall->name }}
            <span class="badge {{ $firewall->syncStatusBadge() }} ms-2">{{ $firewall->syncStatusLabel() }}</span>
        </h4>
        <div class="d-flex gap-2">
            @can('manage-sophos')
            <form method="POST" action="{{ route('admin.network.sophos.sync', $firewall) }}" class="d-inline">
                @csrf
                <button class="btn btn-info btn-sm"><i class="bi bi-arrow-repeat"></i> Sync Now</button>
            </form>
            <a href="{{ route('admin.network.sophos.edit', $firewall) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-pencil"></i> Edit
            </a>
            @endcan
            <a href="{{ route('admin.network.sophos.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    {{-- Overview Row --}}
    <div class="row g-3 mb-4">
        <div class="col-md-2"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2"><div class="fw-bold">{{ $firewall->ip }}:{{ $firewall->port }}</div><div class="text-muted small">Address</div></div></div></div>
        <div class="col-md-2"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2"><div class="fw-bold">{{ $firewall->model ?? '-' }}</div><div class="text-muted small">Model</div></div></div></div>
        <div class="col-md-2"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2"><div class="fw-bold">{{ $firewall->firmware_version ?? '-' }}</div><div class="text-muted small">Firmware</div></div></div></div>
        <div class="col-md-2"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2"><div class="fw-bold">{{ $firewall->serial_number ?? '-' }}</div><div class="text-muted small">Serial</div></div></div></div>
        <div class="col-md-2"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2"><div class="fw-bold">{{ $firewall->branch?->name ?? '-' }}</div><div class="text-muted small">Branch</div></div></div></div>
        <div class="col-md-2"><div class="card border-0 shadow-sm text-center"><div class="card-body py-2"><div class="fw-bold">{{ $firewall->last_synced_at?->diffForHumans() ?? 'Never' }}</div><div class="text-muted small">Last Synced</div></div></div></div>
    </div>

    {{-- Tabs --}}
    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-interfaces">Interfaces <span class="badge bg-secondary">{{ $firewall->interfaces->count() }}</span></a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-objects">Network Objects <span class="badge bg-secondary">{{ $firewall->networkObjects->count() }}</span></a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-vpn">VPN Tunnels <span class="badge bg-secondary">{{ $firewall->vpnTunnels->count() }}</span></a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-rules">Firewall Rules <span class="badge bg-secondary">{{ $firewall->firewallRules->count() }}</span></a></li>
    </ul>

    <div class="tab-content">
        {{-- Interfaces Tab --}}
        <div class="tab-pane fade show active" id="tab-interfaces">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Name</th><th>Hardware</th><th>IP / Mask</th><th>Zone</th><th>Status</th><th>MTU</th><th>Speed</th></tr>
                        </thead>
                        <tbody>
                        @forelse($firewall->interfaces as $iface)
                            <tr>
                                <td class="fw-semibold">{{ $iface->name }}</td>
                                <td>{{ $iface->hardware ?? '-' }}</td>
                                <td><code>{{ $iface->ip_address ?? '-' }}</code> @if($iface->netmask) / {{ $iface->netmask }} @endif</td>
                                <td>{{ $iface->zone ?? '-' }}</td>
                                <td><span class="badge {{ $iface->statusBadgeClass() }}">{{ ucfirst($iface->status) }}</span></td>
                                <td>{{ $iface->mtu ?? '-' }}</td>
                                <td>{{ $iface->speed ?? '-' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No interfaces synced yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Network Objects Tab --}}
        <div class="tab-pane fade" id="tab-objects">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Name</th><th>Type</th><th>IP Address</th><th>Subnet</th><th>IPAM Synced</th></tr>
                        </thead>
                        <tbody>
                        @forelse($firewall->networkObjects as $obj)
                            <tr>
                                <td class="fw-semibold">{{ $obj->name }}</td>
                                <td><span class="badge {{ $obj->typeBadgeClass() }}">{{ $obj->object_type ?? '-' }}</span></td>
                                <td><code>{{ $obj->ip_address ?? '-' }}</code></td>
                                <td>{{ $obj->subnet ?? '-' }}</td>
                                <td>{!! $obj->ipam_synced ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>' !!}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No network objects synced yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- VPN Tunnels Tab --}}
        <div class="tab-pane fade" id="tab-vpn">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>Name</th><th>Type</th><th>Policy</th><th>Remote GW</th><th>Local Subnet</th><th>Remote Subnet</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        @forelse($firewall->vpnTunnels as $vpn)
                            <tr>
                                <td class="fw-semibold">{{ $vpn->name }}</td>
                                <td>{{ $vpn->connection_type ?? '-' }}</td>
                                <td>{{ $vpn->policy ?? '-' }}</td>
                                <td><code>{{ $vpn->remote_gateway ?? '-' }}</code></td>
                                <td>{{ $vpn->local_subnet ?? '-' }}</td>
                                <td>{{ $vpn->remote_subnet ?? '-' }}</td>
                                <td><span class="badge {{ $vpn->statusBadgeClass() }}">{{ ucfirst($vpn->status) }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No VPN tunnels synced yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Firewall Rules Tab --}}
        <div class="tab-pane fade" id="tab-rules">
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover table-sm mb-0">
                        <thead class="table-light">
                            <tr><th>#</th><th>Name</th><th>Src Zone</th><th>Dst Zone</th><th>Source</th><th>Destination</th><th>Action</th><th>Enabled</th><th>Log</th></tr>
                        </thead>
                        <tbody>
                        @forelse($firewall->firewallRules as $rule)
                            <tr class="{{ !$rule->enabled ? 'text-muted' : '' }}">
                                <td>{{ $rule->position }}</td>
                                <td class="fw-semibold">{{ $rule->rule_name }}</td>
                                <td>{{ $rule->source_zone ?? 'Any' }}</td>
                                <td>{{ $rule->dest_zone ?? 'Any' }}</td>
                                <td>{{ is_array($rule->source_networks) ? implode(', ', $rule->source_networks) : '-' }}</td>
                                <td>{{ is_array($rule->dest_networks) ? implode(', ', $rule->dest_networks) : '-' }}</td>
                                <td><span class="badge {{ $rule->actionBadgeClass() }}">{{ ucfirst($rule->action) }}</span></td>
                                <td>{!! $rule->enabled ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-danger"></i>' !!}</td>
                                <td>{!! $rule->log_traffic ? '<i class="bi bi-check text-success"></i>' : '-' !!}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-3">No firewall rules synced yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- SNMP Link --}}
    @if($firewall->monitoredHost)
    <div class="mt-4">
        <a href="{{ route('admin.network.monitoring.show', $firewall->monitoredHost) }}" class="btn btn-outline-info btn-sm">
            <i class="bi bi-broadcast me-1"></i> View SNMP Metrics for {{ $firewall->monitoredHost->name }}
        </a>
    </div>
    @endif
</div>
@endsection
