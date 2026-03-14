@extends('layouts.admin')
@section('content')
<style>
    .noc-card {
        background: linear-gradient(145deg, #ffffff, #f8f9fa);
        border: 1px solid rgba(0,0,0,0.05);
        border-radius: 12px;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .noc-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 15px rgba(0,0,0,0.05) !important;
    }
    .status-pulse {
        width: 10px; height: 10px; border-radius: 50%;
        display: inline-block;
        box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7);
        animation: pulse-green 2s infinite;
    }
    @keyframes pulse-green {
        0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.7); }
        70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(25, 135, 84, 0); }
        100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(25, 135, 84, 0); }
    }
    .icon-box {
        width: 48px; height: 48px;
        display: flex; align-items: center; justify-content: center;
        border-radius: 12px; font-size: 1.5rem;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0 fw-bold"><i class="bi bi-speedometer2 me-2 text-primary"></i>Command Center</h3>
        <p class="text-muted small mb-0">Unified Identity, Network, VoIP, and Infrastructure overview &bull; Live as of {{ now()->format('H:i') }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.noc.events') }}" class="btn btn-outline-secondary btn-sm shadow-sm">
            <i class="bi bi-exclamation-triangle me-1"></i>All Events
        </a>
        <button class="btn btn-primary btn-sm shadow-sm" onclick="location.reload()">
            <i class="bi bi-arrow-repeat me-1"></i>Refresh
        </button>
    </div>
</div>

{{-- Top Row: Core Metrics (Identity, Assets, VoIP, Provisioning) --}}
<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card shadow-sm noc-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h6 class="text-muted text-uppercase small mb-1">Total Identities</h6>
                    <h3 class="mb-0 fw-bold text-primary">{{ number_format($totalUsers) }}</h3>
                </div>
                <div class="icon-box bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-people-fill"></i>
                </div>
            </div>
            <div class="mt-auto">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Licensed Users</span><span>{{ $licensedPercent }}%</span>
                </div>
                <div class="progress" style="height:6px">
                    <div class="progress-bar bg-primary" style="width:{{ $licensedPercent }}%"></div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 col-xl-3">
        <div class="card shadow-sm noc-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h6 class="text-muted text-uppercase small mb-1">Managed Assets</h6>
                    <h3 class="mb-0 fw-bold text-success">{{ number_format($totalDevices) }}</h3>
                </div>
                <div class="icon-box bg-success bg-opacity-10 text-success">
                    <i class="bi bi-cpu-fill"></i>
                </div>
            </div>
            <div class="mt-auto">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Missing Credentials</span>
                    <span class="{{ $missingCreds > 0 ? 'text-danger fw-bold' : 'text-success' }}">{{ $missingCreds }}</span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Printers Overdue</span>
                    <span class="{{ $printersOverdue > 0 ? 'text-warning fw-bold' : 'text-success' }}">{{ $printersOverdue }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-6 col-xl-3">
        <div class="card shadow-sm noc-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h6 class="text-muted text-uppercase small mb-1">VoIP Extensions</h6>
                    <h3 class="mb-0 fw-bold text-info">{{ number_format($totalExt) }}</h3>
                </div>
                <div class="icon-box bg-info bg-opacity-10 text-info">
                    <i class="bi bi-telephone-fill"></i>
                </div>
            </div>
            <div class="mt-auto">
                <div class="d-flex justify-content-between small text-muted">
                    <span><i class="bi bi-circle-fill text-success small me-1"></i>Idle</span>
                    <span class="fw-bold">{{ $totalIdle }}</span>
                </div>
                <div class="d-flex justify-content-between small text-muted mt-1">
                    <span><i class="bi bi-circle-fill text-warning small me-1"></i>In-Use</span>
                    <span class="fw-bold">{{ $totalInUse }}</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 col-xl-3">
        <div class="card shadow-sm noc-card h-100 p-3">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h6 class="text-muted text-uppercase small mb-1">Phone Requests</h6>
                    <h3 class="mb-0 fw-bold text-secondary">{{ number_format($phoneRequestCount) }}</h3>
                </div>
                <div class="icon-box bg-secondary bg-opacity-10 text-secondary">
                    <i class="bi bi-file-earmark-code-fill"></i>
                </div>
            </div>
            <div class="mt-auto">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Total XML Fetches</span>
                    <span class="fw-bold">{{ number_format($totalXmlRequests) }}</span>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span>Directory Contacts</span>
                    <span class="fw-bold">{{ number_format($contactCount) }}</span>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Middle Row: Network Infrastructure (Switches, VPNs, SNMP Hosts) --}}
<h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-hdd-network me-2"></i>Infrastructure Health</h6>
<div class="row g-3 mb-4">
    {{-- Switches --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 noc-card position-relative">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold text-dark">Network Switches</h6>
                    <span class="badge {{ $onlineSwitches == $totalSwitches ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">{{ $onlineSwitches }}/{{ $totalSwitches }} Online</span>
                </div>
                <div class="progress" style="height:8px">
                    <div class="progress-bar {{ $onlinePercent >= 90 ? 'bg-success' : 'bg-warning' }}" style="width:{{ $onlinePercent }}%"></div>
                </div>
                @if($onlineSwitches < $totalSwitches)
                <div class="small text-danger mt-2"><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $totalSwitches - $onlineSwitches }} offline switches detected</div>
                @else
                <div class="small text-success mt-2"><i class="bi bi-check-circle-fill me-1"></i>All switches operating normally</div>
                @endif
                <a href="{{ route('admin.network.overview') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>

    {{-- VPN Tunnels --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 noc-card position-relative">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold text-dark">IPsec VPN Tunnels</h6>
                    @php $vpnTotal = $vpnTunnels->count(); $vpnPct = $vpnTotal > 0 ? round(($vpnOnline/$vpnTotal)*100) : 0; @endphp
                    <span class="badge {{ $vpnOnline == $vpnTotal && $vpnTotal > 0 ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning' }}">{{ $vpnOnline }}/{{ $vpnTotal }} Established</span>
                </div>
                <div class="progress" style="height:8px">
                    <div class="progress-bar {{ $vpnPct >= 90 ? 'bg-success' : 'bg-warning' }}" style="width:{{ $vpnPct }}%"></div>
                </div>
                @if($vpnTotal > 0 && $vpnOnline < $vpnTotal)
                <div class="small text-danger mt-2"><i class="bi bi-dash-circle-fill me-1"></i>{{ $vpnTotal - $vpnOnline }} tunnels down</div>
                @else
                <div class="small text-success mt-2"><i class="bi bi-check-circle-fill me-1"></i>All tunnels connected</div>
                @endif
                <a href="{{ route('admin.network.vpn.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>

    {{-- Monitored Hosts --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 noc-card position-relative">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold text-dark">Monitored Hosts</h6>
                    @php $hostTotal = $hostsUp + $hostsDown; $hostPct = $hostTotal > 0 ? round(($hostsUp/$hostTotal)*100) : 0; @endphp
                    <span class="badge {{ $hostsDown == 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' }}">{{ $hostsUp }}/{{ $hostTotal }} Up</span>
                </div>
                <div class="progress" style="height:8px">
                    <div class="progress-bar {{ $hostPct == 100 ? 'bg-success' : 'bg-danger' }}" style="width:{{ $hostPct }}%"></div>
                </div>
                @if($hostsDown > 0)
                <div class="small text-danger mt-2"><i class="bi bi-x-circle-fill me-1"></i>{{ $hostsDown }} hosts are offline</div>
                @else
                <div class="small text-success mt-2"><i class="bi bi-check-circle-fill me-1"></i>All hosts responsive</div>
                @endif
                <a href="{{ route('admin.network.monitoring.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>
</div>

{{-- VPN Tunnel Detail Widget --}}
@if($vpnTunnels->count() > 0)
<h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-shield-lock-fill me-2"></i>VPN Tunnel Status</h6>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex gap-4 mb-3">
                    <div class="text-center">
                        <div class="fs-3 fw-bold text-success">{{ $vpnTunnels->where('status', 'up')->count() }}</div>
                        <small class="text-muted">Up</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-3 fw-bold text-warning">{{ $vpnTunnels->where('status', 'connecting')->count() }}</div>
                        <small class="text-muted">Connecting</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-3 fw-bold text-danger">{{ $vpnTunnels->where('status', 'down')->count() }}</div>
                        <small class="text-muted">Down</small>
                    </div>
                </div>
                <div class="row g-2">
                    @foreach($vpnTunnels->sortBy('status') as $t)
                    <div class="col-md-4 col-lg-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded border {{ $t->status === 'down' ? 'border-danger bg-danger bg-opacity-10' : ($t->status === 'connecting' ? 'border-warning bg-warning bg-opacity-10' : 'border-success bg-success bg-opacity-10') }}">
                            <i class="bi {{ $t->status === 'up' ? 'bi-check-circle-fill text-success' : ($t->status === 'connecting' ? 'bi-arrow-repeat text-warning' : 'bi-x-circle-fill text-danger') }}"></i>
                            <div>
                                <div class="small fw-semibold">{{ $t->name }}</div>
                                <div style="font-size:10px" class="text-muted">{{ $t->branch?->name ?: 'No branch' }}</div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Sophos SNMP VPN Tunnel Detail Widget --}}
@if($sophosVpnTunnels->count() > 0)
<h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-shield-shaded me-2"></i>Sophos S2S VPN Tunnels (SNMP)</h6>
<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex gap-4 mb-3">
                    <div class="text-center">
                        <div class="fs-3 fw-bold text-success">{{ $sophosVpnTunnels->where('status', 'up')->count() }}</div>
                        <small class="text-muted">Up</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-3 fw-bold text-danger">{{ $sophosVpnTunnels->where('status', 'down')->count() }}</div>
                        <small class="text-muted">Down</small>
                    </div>
                </div>
                <div class="row g-2">
                    @foreach($sophosVpnTunnels->sortBy('status') as $t)
                    <div class="col-md-4 col-lg-3">
                        <div class="d-flex align-items-center gap-2 p-2 rounded border {{ $t->status === 'down' ? 'border-danger bg-danger bg-opacity-10' : 'border-success bg-success bg-opacity-10' }}">
                            <i class="bi {{ $t->status === 'up' ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' }}"></i>
                            <div class="text-truncate">
                                <div class="small fw-semibold text-truncate" title="{{ $t->name }}">{{ $t->name }}</div>
                                <div style="font-size:10px" class="text-muted text-truncate">
                                    {{ $t->firewall?->name }} ({{ $t->firewall?->branch?->name ?: 'No branch' }})
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- DHCP / IPAM / Sophos Row --}}
<h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-hdd-rack me-2"></i>DHCP & Firewall Overview</h6>
<div class="row g-3 mb-4">
    {{-- DHCP Leases --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 noc-card position-relative">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold text-dark">DHCP Leases</h6>
                    <span class="badge bg-primary-subtle text-primary">{{ $dhcpTotal ?? 0 }} Total</span>
                </div>
                <div class="d-flex gap-3 mb-2">
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-primary">{{ $dhcpBySource['meraki'] ?? 0 }}</div>
                        <small class="text-muted">Meraki</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-danger">{{ $dhcpBySource['sophos'] ?? 0 }}</div>
                        <small class="text-muted">Sophos</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-warning">{{ $dhcpBySource['snmp'] ?? 0 }}</div>
                        <small class="text-muted">SNMP</small>
                    </div>
                </div>
                @if(($dhcpConflicts ?? 0) > 0)
                <div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $dhcpConflicts }} IP conflicts detected</div>
                @else
                <div class="small text-success"><i class="bi bi-check-circle-fill me-1"></i>No IP conflicts</div>
                @endif
                <a href="{{ route('admin.network.dhcp.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>

    {{-- Subnet Utilization --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 noc-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold text-dark">Top Subnets</h6>
                    <a href="{{ route('admin.network.ipam.index') }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View All</a>
                </div>
                @forelse($topSubnets ?? [] as $sub)
                @php $pct = $sub->utilizationPercent(); @endphp
                <div class="mb-2">
                    <div class="d-flex justify-content-between small mb-1">
                        <span class="fw-semibold">{{ $sub->cidr }}</span>
                        <span class="{{ $pct >= 90 ? 'text-danger' : ($pct >= 70 ? 'text-warning' : 'text-success') }}">{{ $pct }}%</span>
                    </div>
                    <div class="progress" style="height:5px">
                        <div class="progress-bar {{ $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $pct }}%"></div>
                    </div>
                </div>
                @empty
                <div class="text-muted small text-center py-2">No subnets tracked yet</div>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Sophos Firewalls --}}
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100 noc-card position-relative">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0 fw-bold text-dark">Sophos Firewalls</h6>
                    <span class="badge {{ ($sophosTotal ?? 0) > 0 ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">{{ $sophosSynced ?? 0 }}/{{ $sophosTotal ?? 0 }} Synced</span>
                </div>
                @if(($sophosTotal ?? 0) > 0)
                <div class="d-flex gap-3 mb-2">
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-success">{{ $sophosSynced ?? 0 }}</div>
                        <small class="text-muted">Synced</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-warning">{{ ($sophosTotal ?? 0) - ($sophosSynced ?? 0) }}</div>
                        <small class="text-muted">Pending</small>
                    </div>
                    <div class="text-center">
                        <div class="fs-5 fw-bold text-info">{{ $sophosVpnUp ?? 0 }}</div>
                        <small class="text-muted">VPN Up</small>
                    </div>
                </div>
                @else
                <div class="text-muted small text-center py-2">No Sophos firewalls configured</div>
                @endif
                <a href="{{ route('admin.network.sophos.index') }}" class="stretched-link"></a>
            </div>
        </div>
    </div>
</div>

{{-- UCM Unified PBX Status --}}
<h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-server me-2"></i>Unified PBX Health</h6>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">Server</th>
                        <th>Status</th>
                        <th>Model & Firmware</th>
                        <th>Extensions</th>
                        <th>SIP Trunks</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($ucmStats as $s)
                        @php $server = $s['server']; $stats = $s['stats']; @endphp
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark">{{ $server->name }}</div>
                                <div class="small text-muted font-monospace">{{ parse_url($server->url, PHP_URL_HOST) ?? $server->url }}</div>
                            </td>
                            <td>
                                @if($stats['online'])
                                    <span class="badge bg-success-subtle text-success border border-success"><div class="status-pulse bg-success me-1"></div>Online</span>
                                    <div class="small text-muted mt-1">{{ $stats['uptime'] ?? 'Up' }}</div>
                                @else
                                    <span class="badge bg-danger-subtle text-danger border border-danger"><i class="bi bi-x-circle-fill me-1"></i>Offline</span>
                                    <div class="small text-danger mt-1 text-truncate" style="max-width: 150px;" title="{{ $stats['error'] ?? 'Unreachable' }}">{{ $stats['error'] ?? 'Unreachable' }}</div>
                                @endif
                            </td>
                            <td>
                                @if($stats['online'])
                                    <div class="text-dark small"><i class="bi bi-cpu me-1"></i>{{ $stats['model'] ?? '-' }}</div>
                                    <div class="text-muted small">v{{ $stats['firmware'] ?? '-' }}</div>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if($stats['online'])
                                    @php $ext = $stats['extensions'] ?? []; @endphp
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="fw-bold">{{ $ext['total'] ?? 0 }}</div>
                                        <div class="d-flex gap-1" style="font-size: 0.75rem;">
                                            <span class="badge bg-success" title="Idle">{{ $ext['idle'] ?? 0 }}</span>
                                            <span class="badge bg-warning text-dark" title="In Use">{{ $ext['inuse'] ?? 0 }}</span>
                                            <span class="badge bg-danger" title="Unavailable">{{ $ext['unavailable'] ?? 0 }}</span>
                                        </div>
                                    </div>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td>
                                @if($stats['online'])
                                    @php $trunk = $stats['trunk_counts'] ?? []; @endphp
                                    <span class="badge {{ ($trunk['unreachable'] ?? 0) > 0 ? 'bg-danger' : 'bg-success' }}">
                                        {{ $trunk['reachable'] ?? 0 }}/{{ $trunk['total'] ?? 0 }} Up
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Bottom Row: NOC Events & Branch Health --}}
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-exclamation-square me-2"></i>Active Alerts</h6>
        <div class="card shadow-sm border-0 h-100">
            @if($openEvents->isEmpty())
                <div class="card-body d-flex flex-column align-items-center justify-content-center text-muted" style="min-height: 250px;">
                    <i class="bi bi-emoji-smile fs-1 mb-2 text-success opacity-50"></i>
                    <p class="mb-0">All systems operational. No active NOC alerts.</p>
                </div>
            @else
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <tbody>
                                @foreach($openEvents as $event)
                                <tr>
                                    <td class="ps-3" style="width:8px;padding-right:0">
                                        <div style="width:4px;height:100%;background:{{ $event->severity === 'critical' ? '#dc3545' : ($event->severity === 'warning' ? '#ffc107' : '#0dcaf0') }};border-radius:2px">&nbsp;</div>
                                    </td>
                                    <td style="width:90px"><span class="badge {{ $event->severityBadgeClass() }}">{{ ucfirst($event->severity) }}</span></td>
                                    <td><div class="fw-bold mb-1"><i class="{{ $event->moduleIcon() }} text-muted me-1"></i>{{ $event->title }}</div><div class="text-muted opacity-75">{{ Str::limit($event->message, 80) }}</div></td>
                                    <td class="text-muted text-nowrap text-end">{{ $event->last_seen->diffForHumans() }}</td>
                                    <td class="pe-3 text-end">
                                        @can('manage-noc')
                                        <form method="POST" action="{{ route('admin.noc.events.acknowledge', $event->id) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-light border shadow-sm">Ack</button>
                                        </form>
                                        @endcan
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>
    </div>
    
    <div class="col-lg-5">
        <h6 class="text-muted fw-bold text-uppercase small mb-3"><i class="bi bi-building me-2"></i>Branch Health Map</h6>
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body p-0">
                @if($branches->isEmpty())
                    <div class="text-center py-4 text-muted small">No branches found.</div>
                @else
                    <div class="list-group list-group-flush">
                        @foreach($branches as $branch)
                        @php $h = $branch->health ?? ['total'=>0,'identity'=>0,'voice'=>0,'network'=>0,'asset'=>0]; @endphp
                        <a href="{{ route('admin.noc.branch', $branch->id) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3 border-bottom-0 border-top">
                            <div>
                                <h6 class="mb-1 fw-bold text-dark">{{ $branch->name }}</h6>
                                <div class="d-flex gap-2 small">
                                    <span class="badge bg-{{ \App\Services\HealthScoringService::healthColorStatic($h['identity']) }}-subtle text-{{ \App\Services\HealthScoringService::healthColorStatic($h['identity']) }}">ID: {{ $h['identity'] }}</span>
                                    <span class="badge bg-{{ \App\Services\HealthScoringService::healthColorStatic($h['network']) }}-subtle text-{{ \App\Services\HealthScoringService::healthColorStatic($h['network']) }}">NW: {{ $h['network'] }}</span>
                                    <span class="badge bg-{{ \App\Services\HealthScoringService::healthColorStatic($h['asset']) }}-subtle text-{{ \App\Services\HealthScoringService::healthColorStatic($h['asset']) }}">IT: {{ $h['asset'] }}</span>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="display-6 fw-bold text-{{ \App\Services\HealthScoringService::healthColorStatic($h['total']) }} mb-0" style="font-size: 1.5rem;">{{ $h['total'] }}%</div>
                            </div>
                        </a>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

@endsection
