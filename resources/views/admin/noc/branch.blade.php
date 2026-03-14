@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-building me-2 text-primary"></i>{{ $branch->name }}</h4>
        <small class="text-muted">Single pane of glass &mdash; branch infrastructure overview</small>
    </div>
    <a href="{{ route('admin.noc.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>NOC Dashboard</a>
</div>

{{-- 1. Health Score Cards --}}
<div class="row g-3 mb-4">
    @foreach([
        ['identity', 'Identity', 'bi-people-fill', 'primary'],
        ['voice',    'Voice',    'bi-telephone-fill', 'info'],
        ['network',  'Network',  'bi-diagram-3-fill', 'success'],
        ['asset',    'Assets',   'bi-cpu-fill', 'warning'],
    ] as [$key, $label, $icon, $color])
    @php $s = $score[$key] ?? 0; @endphp
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 text-center">
            <div class="card-body py-4">
                <i class="bi {{ $icon }} fs-2 text-{{ $color }} mb-2 d-block"></i>
                <div class="display-5 fw-bold text-{{ $color }}">{{ $s }}%</div>
                <div class="small text-muted mt-1">{{ $label }} Health</div>
                <div class="progress mt-2" style="height:6px">
                    <div class="progress-bar bg-{{ $color }}" style="width:{{ $s }}%"></div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- Overall Score --}}
<div class="alert alert-{{ $score['total'] >= 90 ? 'success' : ($score['total'] >= 70 ? 'info' : ($score['total'] >= 50 ? 'warning' : 'danger')) }} d-flex align-items-center gap-3 mb-4">
    <div class="display-6 fw-bold">{{ $score['total'] }}%</div>
    <div>
        <strong>Overall Branch Health</strong>
        <div class="small">Average of all 4 module scores</div>
    </div>
</div>

<div class="row g-4">
    {{-- Left Column --}}
    <div class="col-lg-8">

        {{-- 2. VPN Tunnels --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-shield-lock me-1"></i>VPN Tunnels ({{ $vpnTunnels->count() }})</strong>
                @php
                    $vpnUp = $vpnTunnels->where('status', 'up')->count();
                    $vpnDown = $vpnTunnels->where('status', 'down')->count();
                @endphp
                <span>
                    <span class="badge bg-success">{{ $vpnUp }} Up</span>
                    @if($vpnDown > 0)<span class="badge bg-danger">{{ $vpnDown }} Down</span>@endif
                </span>
            </div>
            <div class="card-body p-0">
                @forelse($vpnTunnels as $t)
                <div class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <span class="badge {{ $t->status === 'up' ? 'bg-success' : ($t->status === 'connecting' ? 'bg-warning text-dark' : 'bg-danger') }} me-2">
                        <i class="bi bi-circle-fill me-1" style="font-size:7px"></i>{{ ucfirst($t->status) }}
                    </span>
                    <span class="fw-semibold me-2">{{ $t->name }}</span>
                    <span class="text-muted">{{ $t->remote_ip }}</span>
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No VPN tunnels for this branch.</div>
                @endforelse
            </div>
        </div>
        @endif

        {{-- 2b. Sophos VPN Tunnels (SNMP) --}}
        @if(($sophosVpnTunnels ?? collect())->count() > 0)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-shield-shaded me-1"></i>Sophos S2S VPN ({{ $sophosVpnTunnels->count() }})</strong>
                @php
                    $sVpnUp = $sophosVpnTunnels->where('status', 'up')->count();
                    $sVpnDown = $sophosVpnTunnels->where('status', 'down')->count();
                @endphp
                <span>
                    <span class="badge bg-success">{{ $sVpnUp }} Up</span>
                    @if($sVpnDown > 0)<span class="badge bg-danger">{{ $sVpnDown }} Down</span>@endif
                </span>
            </div>
            <div class="card-body p-0">
                @foreach($sophosVpnTunnels as $st)
                <div class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <span class="badge {{ $st->status === 'up' ? 'bg-success' : 'bg-danger' }} me-2" style="font-size:10px">
                        <i class="bi bi-circle-fill me-1" style="font-size:6px"></i>{{ ucfirst($st->status) }}
                    </span>
                    <span class="fw-semibold me-2">{{ $st->name }}</span>
                    <span class="text-muted ms-auto">{{ $st->firewall?->name }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- 3. ISP Connections --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-globe2 me-1"></i>ISP Connections ({{ $ispConns->count() }})</strong></div>
            <div class="card-body p-0">
                @forelse($ispConns as $isp)
                <div class="d-flex justify-content-between align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <div>
                        <span class="fw-semibold">{{ $isp->provider }}</span>
                        <span class="text-muted ms-2">{{ $isp->circuit_id ?: '' }}</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <span class="text-muted">{{ $isp->speedLabel() }}</span>
                        {!! $isp->contractStatusBadge() !!}
                        <a href="{{ route('admin.network.sla.detail', $isp->id) }}" class="btn btn-sm btn-outline-info py-0 px-1"><i class="bi bi-graph-up"></i></a>
                    </div>
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No ISP connections configured.</div>
                @endforelse
            </div>
        </div>

        {{-- 4. Network Switches --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-diagram-3-fill me-1"></i>Switches ({{ $switches->count() }})</strong></div>
            <div class="card-body p-0">
                @forelse($switches as $sw)
                <div class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <span class="badge {{ $sw->statusBadgeClass() }} me-2"><i class="bi bi-circle-fill me-1" style="font-size:7px"></i>{{ ucfirst($sw->status) }}</span>
                    <span class="fw-semibold me-2">{{ $sw->name }}</span>
                    <span class="text-muted me-2">{{ $sw->model }}</span>
                    <span class="ms-auto text-muted">{{ $sw->port_count }} ports</span>
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No switches in this branch.</div>
                @endforelse
            </div>
        </div>

        {{-- 5. Monitored Hosts --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-broadcast me-1"></i>Monitored Hosts ({{ $monitorHosts->count() }})</strong>
                @php
                    $hUp = $monitorHosts->where('status', 'up')->count();
                    $hDown = $monitorHosts->where('status', 'down')->count();
                @endphp
                <span>
                    <span class="badge bg-success">{{ $hUp }} Up</span>
                    @if($hDown > 0)<span class="badge bg-danger">{{ $hDown }} Down</span>@endif
                </span>
            </div>
            <div class="card-body p-0">
                @forelse($monitorHosts as $h)
                <div class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <span class="badge {{ $h->status === 'up' ? 'bg-success' : 'bg-danger' }} me-2" style="font-size:10px">
                        <i class="bi bi-circle-fill me-1" style="font-size:6px"></i>{{ ucfirst($h->status) }}
                    </span>
                    <span class="fw-semibold me-2">{{ $h->name }}</span>
                    <code class="text-muted">{{ $h->ip }}</code>
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No monitored hosts.</div>
                @endforelse
            </div>
        </div>

        {{-- 5b. Sophos Firewalls --}}
        @if(($sophosFirewalls ?? collect())->count() > 0)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-shield-fill me-1"></i>Sophos Firewalls ({{ $sophosFirewalls->count() }})</strong>
                <a href="{{ route('admin.network.sophos.index') }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View All</a>
            </div>
            <div class="card-body p-0">
                @foreach($sophosFirewalls as $fw)
                <a href="{{ route('admin.network.sophos.show', $fw) }}" class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small text-decoration-none text-dark">
                    <span class="badge {{ $fw->syncStatusBadge() }} me-2">{{ $fw->syncStatusLabel() }}</span>
                    <span class="fw-semibold me-2">{{ $fw->name }}</span>
                    <code class="text-muted me-auto">{{ $fw->ip }}:{{ $fw->port }}</code>
                    <span class="text-muted">{{ $fw->model ?? '' }}</span>
                </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- 5c. DHCP Leases (Recent) --}}
        @if(($dhcpLeases ?? collect())->count() > 0)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-hdd-network-fill me-1"></i>Recent DHCP Leases</strong>
                <a href="{{ route('admin.network.dhcp.index', ['branch' => $branch->id]) }}" class="btn btn-sm btn-outline-secondary py-0 px-2">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <thead class="table-light">
                            <tr><th class="ps-3">IP</th><th>MAC</th><th>Hostname</th><th>Source</th><th>Last Seen</th></tr>
                        </thead>
                        <tbody>
                            @foreach($dhcpLeases as $lease)
                            <tr class="{{ $lease->is_conflict ? 'table-danger' : '' }}">
                                <td class="ps-3"><code>{{ $lease->ip_address }}</code></td>
                                <td class="font-monospace text-muted">{{ $lease->mac_address }}</td>
                                <td>{{ $lease->hostname ?? '-' }}</td>
                                <td><span class="badge {{ $lease->sourceBadgeClass() }}">{{ ucfirst($lease->source) }}</span></td>
                                <td class="text-muted">{{ $lease->last_seen?->diffForHumans() ?? '-' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @endif

        {{-- 6. Devices --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-cpu me-1"></i>Devices ({{ $devices->count() }})</strong></div>
            <div class="card-body p-0">
                @if($devices->isEmpty())
                <div class="text-center py-3 text-muted small">No devices in this branch.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 small">
                        <tbody>
                            @foreach($devices->take(10) as $dev)
                            <tr>
                                <td class="ps-3"><span class="badge {{ $dev->typeBadgeClass() }}">{{ $dev->typeLabel() }}</span></td>
                                <td class="fw-semibold">{{ $dev->name }}</td>
                                <td class="text-muted">
                                    @if($dev->credentials->isEmpty())
                                    <span class="badge bg-warning text-dark"><i class="bi bi-key me-1"></i>No Creds</span>
                                    @else
                                    <span class="text-success small"><i class="bi bi-check-circle me-1"></i>{{ $dev->credentials->count() }} creds</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($devices->count() > 10)
                <div class="px-3 py-2 border-top text-muted small">+ {{ $devices->count() - 10 }} more devices</div>
                @endif
                @endif
            </div>
        </div>
    </div>

    {{-- Right Column (Sidebar) --}}
    <div class="col-lg-4">

        {{-- Printers --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-printer-fill me-1"></i>Printers ({{ $printers->count() }})</strong></div>
            <div class="card-body p-0">
                @forelse($printers as $p)
                <div class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <span class="fw-semibold me-auto">{{ $p->name }}</span>
                    @if($p->isMaintenanceDue())
                    <span class="badge bg-warning text-dark"><i class="bi bi-tools me-1"></i>Due</span>
                    @else
                    <span class="badge bg-success-subtle text-success border">OK</span>
                    @endif
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No printers.</div>
                @endforelse
            </div>
        </div>

        {{-- Landlines --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent"><strong><i class="bi bi-telephone me-1"></i>Landlines ({{ $landlines->count() }})</strong></div>
            <div class="card-body p-0">
                @forelse($landlines as $ll)
                <div class="d-flex align-items-center px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <code class="me-2">{{ $ll->phone_number }}</code>
                    <span class="text-muted me-auto">{{ $ll->provider }}</span>
                    <span class="badge {{ $ll->statusBadgeClass() }}">{{ ucfirst($ll->status) }}</span>
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No landlines.</div>
                @endforelse
            </div>
        </div>

        {{-- Static IPs --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-hdd-rack me-1"></i>Static IPs</strong>
                <span class="badge bg-secondary">{{ $ipCount }}</span>
            </div>
            <div class="card-body small text-center">
                @if($ipCount > 0)
                <a href="{{ route('admin.network.ip-reservations.index', ['branch' => $branch->id]) }}" class="btn btn-sm btn-outline-primary">
                    View {{ $ipCount }} IP Reservations
                </a>
                @else
                <span class="text-muted">No IP reservations.</span>
                @endif
            </div>
        </div>

        {{-- IPAM Subnets --}}
        @if(($subnets ?? collect())->count() > 0)
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-grid-3x3 me-1"></i>Subnets</strong>
                <span class="badge bg-secondary">{{ $subnets->count() }}</span>
            </div>
            <div class="card-body p-0">
                @foreach($subnets as $sub)
                @php $pct = $sub->utilizationPercent(); @endphp
                <a href="{{ route('admin.network.ipam.show', $sub) }}" class="d-block px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small text-decoration-none text-dark">
                    <div class="d-flex justify-content-between mb-1">
                        <span class="fw-semibold font-monospace">{{ $sub->cidr }}</span>
                        <span class="{{ $pct >= 90 ? 'text-danger' : ($pct >= 70 ? 'text-warning' : 'text-success') }} fw-bold">{{ $pct }}%</span>
                    </div>
                    <div class="progress" style="height:4px">
                        <div class="progress-bar {{ $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success') }}" style="width:{{ $pct }}%"></div>
                    </div>
                    @if($sub->description)<div class="text-muted mt-1" style="font-size:11px">{{ $sub->description }}</div>@endif
                </a>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Employees --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-person-vcard-fill me-1"></i>Employees</strong>
                <span class="badge bg-secondary">{{ $employees->count() }}</span>
            </div>
            <div class="card-body small text-center">
                @if($employees->count() > 0)
                <a href="{{ route('admin.employees.index', ['branch' => $branch->id]) }}" class="btn btn-sm btn-outline-primary">
                    View {{ $employees->count() }} Employees
                </a>
                @else
                <span class="text-muted">No employees assigned.</span>
                @endif
            </div>
        </div>

        {{-- Open Alerts --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-bell-fill me-1 text-danger"></i>Open Alerts</strong>
                <span class="badge bg-danger">{{ $openAlerts->count() }}</span>
            </div>
            <div class="card-body p-0">
                @forelse($openAlerts as $alert)
                <div class="px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <span class="badge {{ $alert->severityBadgeClass() }} me-1">{{ ucfirst($alert->severity) }}</span>
                    <span class="fw-semibold">{{ $alert->title }}</span>
                    <div class="text-muted" style="font-size:11px">{{ $alert->last_seen->diffForHumans() }}</div>
                </div>
                @empty
                <div class="text-center py-3 text-muted small"><i class="bi bi-check-circle text-success me-1"></i>No open alerts!</div>
                @endforelse
            </div>
        </div>

        {{-- Open Incidents --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-journal-text me-1 text-warning"></i>Open Incidents</strong>
                <span class="badge bg-warning text-dark">{{ $openIncidents->count() }}</span>
            </div>
            <div class="card-body p-0">
                @forelse($openIncidents as $inc)
                <a href="{{ route('admin.noc.incidents.show', $inc) }}" class="d-block px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small text-decoration-none text-dark">
                    <span class="badge {{ $inc->severityBadgeClass() }} me-1">{{ ucfirst($inc->severity) }}</span>
                    <span class="fw-semibold">{{ $inc->title }}</span>
                    <div class="text-muted" style="font-size:11px">#{{ $inc->id }} &middot; {{ $inc->created_at->diffForHumans() }}</div>
                </a>
                @empty
                <div class="text-center py-3 text-muted small"><i class="bi bi-check-circle text-success me-1"></i>No open incidents!</div>
                @endforelse
            </div>
        </div>

    </div>
</div>

@endsection
