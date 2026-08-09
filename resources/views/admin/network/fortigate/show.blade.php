@extends('layouts.admin')
@section('title', 'FortiGate — ' . $firewall->name)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-bricks me-2"></i>{{ $firewall->name }}
            <span class="badge {{ $firewall->syncStatusBadge() }} ms-2">{{ $firewall->syncStatusLabel() }}</span>
        </h4>
        <div class="d-flex gap-2">
            @can('manage-fortigate')
            <form method="POST" action="{{ route('admin.network.fortigate.sync', $firewall) }}">
                @csrf
                <button class="btn btn-sm btn-outline-info"><i class="bi bi-arrow-repeat"></i> Sync Now</button>
            </form>
            <a href="{{ route('admin.network.fortigate.edit', $firewall) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil"></i> Edit
            </a>
            @endcan
            <a href="{{ route('admin.network.fortigate.index') }}" class="btn btn-sm btn-outline-secondary">
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
    @if($firewall->last_sync_error)
    <div class="alert alert-warning">
        <strong>Last sync error:</strong> {{ $firewall->last_sync_error }}
    </div>
    @endif

    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">Firewall</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th class="text-muted" style="width:38%">Management URL</th><td><code>{{ $firewall->apiBaseUrl() }}</code></td></tr>
                        <tr><th class="text-muted">VDOM</th><td>{{ $firewall->vdom }}</td></tr>
                        <tr><th class="text-muted">Branch</th><td>{{ $firewall->branch?->name ?? '-' }}</td></tr>
                        <tr>
                            <th class="text-muted">Network / SSID</th>
                            <td>
                                @if($firewall->network_label)
                                    <span class="badge bg-info text-dark">{{ $firewall->network_label }}</span>
                                @else
                                    <span class="text-muted">Not set</span>
                                @endif
                            </td>
                        </tr>
                        <tr><th class="text-muted">Model</th><td>{{ $firewall->model ?? '-' }}</td></tr>
                        <tr><th class="text-muted">Serial</th><td><code>{{ $firewall->serial_number ?? '-' }}</code></td></tr>
                        <tr><th class="text-muted">Firmware</th><td>{{ $firewall->firmware_version ?? '-' }}</td></tr>
                        <tr><th class="text-muted">SNMP Host</th><td>{{ $firewall->monitoredHost?->name ?? '-' }}</td></tr>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent fw-semibold">API &amp; Sync</div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr><th class="text-muted" style="width:38%">API Token</th><td><code>{{ $firewall->maskedToken() }}</code></td></tr>
                        <tr><th class="text-muted">Auto Sync</th><td>{{ $firewall->sync_enabled ? 'Enabled' : 'Disabled' }}</td></tr>
                        <tr><th class="text-muted">Last Synced</th><td>{{ $firewall->last_synced_at?->format('Y-m-d H:i:s') ?? 'Never' }}</td></tr>
                        <tr><th class="text-muted">Leases Last Sync</th><td>{{ $firewall->last_lease_count !== null ? number_format($firewall->last_lease_count) : '-' }}</td></tr>
                        <tr><th class="text-muted">Leases Stored</th><td>{{ number_format($leases->total()) }}</td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold">DHCP Leases</span>
            @can('view-dhcp-leases')
            <a href="{{ route('admin.network.dhcp.index', ['source' => 'fortigate']) }}" class="btn btn-sm btn-outline-primary">
                All DHCP Leases <i class="bi bi-arrow-right"></i>
            </a>
            @endcan
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>IP Address</th>
                        <th>MAC Address</th>
                        <th>Hostname</th>
                        <th>Client Type (VCI)</th>
                        <th>Interface</th>
                        <th>Reserved</th>
                        <th>Expires</th>
                        <th>Linked Asset</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($leases as $lease)
                    <tr class="{{ $lease->is_conflict ? 'table-danger' : '' }}">
                        <td><code>{{ $lease->ip_address }}</code></td>
                        <td><code class="text-muted">{{ $lease->mac_address }}</code></td>
                        <td>{{ $lease->hostname ?? '-' }}</td>
                        <td class="small text-muted">{{ $lease->vendor ?? '-' }}</td>
                        <td>{{ $lease->interface ?? '-' }}</td>
                        <td>
                            @if($lease->is_reserved)
                                <span class="badge bg-secondary">Reserved</span>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td class="small">{{ $lease->lease_end?->diffForHumans() ?? '-' }}</td>
                        <td>
                            @if($lease->device)
                                <a href="{{ route('admin.devices.show', $lease->device) }}" class="text-decoration-none small fw-semibold">
                                    <i class="bi bi-hdd me-1 text-success"></i>{{ $lease->device->name }}
                                </a>
                            @else
                                <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="small">{{ $lease->last_seen?->diffForHumans() ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="text-center text-muted py-4">No leases pulled yet — run a sync.</td></tr>
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
