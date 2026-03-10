@extends('layouts.admin')
@section('title', 'IPAM Search')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-search me-2"></i>IPAM Global Search</h4>
        <a href="{{ route('admin.network.ipam.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back to Subnets
        </a>
    </div>

    {{-- Search Bar --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <form method="GET" class="d-flex gap-2">
                <input type="text" name="q" class="form-control" value="{{ $query }}" placeholder="Search by IP address, MAC address, or hostname..." autofocus>
                <button class="btn btn-primary px-4"><i class="bi bi-search"></i></button>
            </form>
        </div>
    </div>

    @if($query)
        {{-- IP Reservations --}}
        @if($results['reservations']->count())
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-hdd-rack me-1"></i> IP Reservations
                <span class="badge bg-primary ms-2">{{ $results['reservations']->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>IP</th><th>MAC</th><th>Device</th><th>Branch</th><th>VLAN</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    @foreach($results['reservations'] as $r)
                        <tr>
                            <td><code>{{ $r->ip_address }}</code></td>
                            <td><code class="text-muted">{{ $r->mac_address ?? '-' }}</code></td>
                            <td>{{ $r->device_name ?? '-' }}</td>
                            <td>{{ $r->branch?->name ?? '-' }}</td>
                            <td>{{ $r->vlan ?? '-' }}</td>
                            <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst($r->status ?? 'static') }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- DHCP Leases --}}
        @if($results['leases']->count())
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-hdd-network me-1"></i> DHCP Leases
                <span class="badge bg-info ms-2">{{ $results['leases']->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>IP</th><th>MAC</th><th>Hostname</th><th>Branch</th><th>Source</th><th>Last Seen</th></tr>
                    </thead>
                    <tbody>
                    @foreach($results['leases'] as $l)
                        <tr>
                            <td><code>{{ $l->ip_address }}</code></td>
                            <td><code class="text-muted">{{ $l->mac_address }}</code></td>
                            <td>{{ $l->hostname ?? '-' }}</td>
                            <td>{{ $l->branch?->name ?? '-' }}</td>
                            <td><span class="badge {{ $l->sourceBadgeClass() }}">{{ ucfirst($l->source) }}</span></td>
                            <td>{{ $l->last_seen?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- Network Clients --}}
        @if($results['clients']->count())
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-transparent fw-semibold">
                <i class="bi bi-laptop me-1"></i> Network Clients (Meraki)
                <span class="badge bg-secondary ms-2">{{ $results['clients']->count() }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>IP</th><th>MAC</th><th>Hostname</th><th>VLAN</th><th>Switch</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    @foreach($results['clients'] as $c)
                        <tr>
                            <td><code>{{ $c->ip ?? '-' }}</code></td>
                            <td><code class="text-muted">{{ $c->mac }}</code></td>
                            <td>{{ $c->hostname ?? $c->description ?? '-' }}</td>
                            <td>{{ $c->vlan ?? '-' }}</td>
                            <td>{{ $c->networkSwitch?->name ?? $c->switch_serial ?? '-' }}</td>
                            <td><span class="badge {{ $c->statusBadgeClass() }}">{{ $c->statusLabel() }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        @if(!$results['reservations']->count() && !$results['leases']->count() && !$results['clients']->count())
        <div class="text-center text-muted py-5">
            <i class="bi bi-search fs-1 d-block mb-2"></i>
            No results found for "{{ $query }}"
        </div>
        @endif
    @endif
</div>
@endsection
