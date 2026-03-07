@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-laptop me-2 text-primary"></i>Network Clients
        </h4>
        <small class="text-muted">Clients seen in the last 24 hours across all switches</small>
    </div>
</div>

{{-- ── Filters ── --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-md-4">
        <input type="text" name="search" class="form-control form-control-sm"
            placeholder="Search hostname, IP, MAC, manufacturer…"
            value="{{ request('search') }}">
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
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            <option value="Online"  {{ request('status') == 'Online'  ? 'selected' : '' }}>Online</option>
            <option value="Offline" {{ request('status') == 'Offline' ? 'selected' : '' }}>Offline</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.clients') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($clients->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-laptop display-4 mb-3 d-block"></i>
            No clients found. Run a sync to populate client data.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Status</th>
                        <th>Hostname / Description</th>
                        <th>IP</th>
                        <th>MAC</th>
                        <th>Manufacturer</th>
                        <th>OS</th>
                        <th class="text-center">VLAN</th>
                        <th>Port</th>
                        <th>Connected Switch</th>
                        <th>Usage (24h)</th>
                        <th>Last Seen</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($clients as $client)
                    <tr>
                        {{-- Status: uses statusLabel() so null shows "Unknown" not "-" --}}
                        <td>
                            <span class="badge {{ $client->statusBadgeClass() }} small">
                                {{ $client->statusLabel() }}
                            </span>
                        </td>

                        {{-- Hostname — show description as fallback --}}
                        <td>
                            <span class="fw-semibold">{{ $client->hostname ?: '-' }}</span>
                            @if($client->description && $client->description !== $client->hostname)
                                <br><span class="text-muted small">{{ $client->description }}</span>
                            @endif
                        </td>

                        <td class="font-monospace">{{ $client->ip ?: '-' }}</td>
                        <td class="font-monospace text-muted small">{{ $client->mac }}</td>
                        <td class="text-muted">{{ $client->manufacturer ?: '-' }}</td>
                        <td class="text-muted">{{ $client->os ?: '-' }}</td>

                        <td class="text-center">
                            @if($client->vlan)
                            <span class="badge bg-info text-dark">{{ $client->vlan }}</span>
                            @else
                            <span class="text-muted">-</span>
                            @endif
                        </td>

                        <td class="font-monospace small">{{ $client->port_id ?: '-' }}</td>

                        {{-- Connected switch — link using switch_serial directly;
                             show relationship name when loaded, serial as fallback --}}
                        <td>
                            @if($client->switch_serial)
                                <a href="{{ route('admin.network.switch-detail', $client->switch_serial) }}"
                                   class="text-decoration-none small">
                                    <i class="bi bi-hdd-network me-1 text-primary"></i>{{ $client->networkSwitch?->name ?? $client->switch_serial }}
                                </a>
                            @else
                                <span class="text-muted small">-</span>
                            @endif
                        </td>

                        <td class="text-end font-monospace small">{{ $client->usageLabel() }}</td>
                        <td class="text-muted small">{{ $client->last_seen ? $client->last_seen->diffForHumans() : '-' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">
            {{ $clients->links() }}
        </div>
        @endif
    </div>
</div>

@endsection
