@extends('layouts.admin')
@section('title', 'Sophos Firewalls')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-shield-fill me-2"></i>Sophos Firewalls</h4>
        @can('manage-sophos')
        <a href="{{ route('admin.network.sophos.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg"></i> Add Firewall
        </a>
        @endcan
    </div>

    @if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>IP : Port</th>
                        <th>Branch</th>
                        <th>Model</th>
                        <th>Firmware</th>
                        <th>Interfaces</th>
                        <th>VPN Tunnels</th>
                        <th>Rules</th>
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
                        <td>{{ $fw->branch?->name ?? '-' }}</td>
                        <td>{{ $fw->model ?? '-' }}</td>
                        <td>{{ $fw->firmware_version ?? '-' }}</td>
                        <td>{{ $fw->interfaces_count }}</td>
                        <td>{{ $fw->vpn_tunnels_count }}</td>
                        <td>{{ $fw->firewall_rules_count }}</td>
                        <td><span class="badge {{ $fw->syncStatusBadge() }}">{{ $fw->syncStatusLabel() }}</span></td>
                        <td>{{ $fw->last_synced_at?->diffForHumans() ?? 'Never' }}</td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <a href="{{ route('admin.network.sophos.show', $fw) }}" class="btn btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @can('manage-sophos')
                                <a href="{{ route('admin.network.sophos.edit', $fw) }}" class="btn btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" action="{{ route('admin.network.sophos.sync', $fw) }}" class="d-inline">
                                    @csrf
                                    <button class="btn btn-outline-info" title="Sync Now"><i class="bi bi-arrow-repeat"></i></button>
                                </form>
                                @endcan
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">No Sophos firewalls configured.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
