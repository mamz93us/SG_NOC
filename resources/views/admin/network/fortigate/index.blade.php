@extends('layouts.admin')
@section('title', 'FortiGate Firewalls')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-bricks me-2"></i>FortiGate Firewalls</h4>
        @can('manage-fortigate')
        <a href="{{ route('admin.network.fortigate.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Add FortiGate
        </a>
        @endcan
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif
    @if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>IP : Port</th>
                        <th>VDOM</th>
                        <th>Branch</th>
                        <th>Network / SSID</th>
                        <th>Model</th>
                        <th>Firmware</th>
                        <th>Leases</th>
                        <th>Sync Status</th>
                        <th>Last Synced</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($firewalls as $fw)
                    <tr>
                        <td class="fw-semibold">{{ $fw->name }}</td>
                        <td><code>{{ $fw->ip }}:{{ $fw->port }}</code></td>
                        <td><span class="text-muted small">{{ $fw->vdom }}</span></td>
                        <td>{{ $fw->branch?->name ?? '-' }}</td>
                        <td>
                            @if($fw->network_label)
                                <span class="badge bg-info text-dark">{{ $fw->network_label }}</span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>{{ $fw->model ?? '-' }}</td>
                        <td>{{ $fw->firmware_version ?? '-' }}</td>
                        <td>{{ number_format($leaseCounts[$fw->ip] ?? 0) }}</td>
                        <td>
                            <span class="badge {{ $fw->syncStatusBadge() }}"
                                  @if($fw->last_sync_error) title="{{ $fw->last_sync_error }}" @endif>
                                {{ $fw->syncStatusLabel() }}
                            </span>
                        </td>
                        <td>{{ $fw->last_synced_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.network.fortigate.show', $fw) }}" class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @can('manage-fortigate')
                                <a href="{{ route('admin.network.fortigate.edit', $fw) }}" class="btn btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.network.fortigate.sync', $fw) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-info" title="Sync DHCP Leases Now"><i class="bi bi-arrow-repeat"></i></button>
                                </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">No FortiGate firewalls configured.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="alert alert-light border mt-3 small mb-0">
        <i class="bi bi-info-circle me-1"></i>
        Each FortiGate needs its own <strong>REST API admin token</strong>
        (System &rarr; Administrators &rarr; Create New &rarr; REST API Admin). Give it a read-only
        profile and add the NOC IP to its <em>Trusted Hosts</em>.
    </div>
</div>
@endsection
