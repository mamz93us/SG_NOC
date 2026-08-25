@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-building me-2 text-primary"></i>{{ $branch->name }}</h4>
        <small class="text-muted">Single pane of glass &mdash; branch infrastructure overview</small>
    </div>
    <a href="{{ route('admin.noc.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>NOC Dashboard</a>
</div>

{{-- 1. Health Score Cards — the three fixed-weight categories --}}
<div class="row g-3 mb-4">
    @foreach($score['categories'] as $cat)
        @php
            // Coloured on the category's own percentage. Raw points run on 0-40,
            // 0-45 and 0-15 scales, so colouring by points would paint a perfect
            // 15/15 Devices score red.
            $color = \App\Services\HealthScoringService::healthColorStatic($cat['percent']);
            $unmeasured = $cat['max_points'] - $cat['evaluable_points'];
        @endphp
        <div class="col-12 col-lg-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body py-4 text-center">
                    <div class="text-uppercase small text-muted fw-semibold">{{ $cat['label'] }}</div>
                    <div class="display-5 fw-bold text-{{ $color }}">
                        {{ $cat['points'] }}<span class="fs-5 text-muted">/{{ $cat['max_points'] }}</span>
                    </div>
                    <div class="progress mt-2" style="height:8px">
                        <div class="progress-bar bg-{{ $color }}" style="width:{{ $cat['percent'] }}%"></div>
                        {{-- Points we could not assess at all, shown in grey so an
                             unmonitored category never reads as a healthy one. --}}
                        @if($unmeasured > 0)
                            <div class="progress-bar bg-secondary opacity-25"
                                 style="width:{{ round($unmeasured / max(1, $cat['max_points']) * 100) }}%"
                                 title="{{ $unmeasured }} points could not be assessed"></div>
                        @endif
                    </div>
                    @if($unmeasured > 0)
                        <div class="small text-muted mt-2">
                            <i class="bi bi-question-circle"></i> {{ $unmeasured }} pts unmonitored
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- Overall Score --}}
@php
    $statusColor = \App\Services\HealthScoringService::statusColor($score['status']);
    $statusLabel = \App\Services\HealthScoringService::statusLabel($score['status']);
@endphp
<div class="alert alert-{{ $statusColor }} d-flex align-items-center gap-3 mb-3">
    <div class="display-6 fw-bold">{{ $score['total'] }}</div>
    <div class="flex-grow-1">
        <strong>Overall Branch Health &mdash; {{ $statusLabel }}</strong>
        <div class="small">
            Weighted total out of 100 &middot;
            {{ $score['coverage_percent'] }} of 100 points measurable
            @if($score['coverage_percent'] < 100)
                <span class="text-muted">({{ 100 - $score['coverage_percent'] }} unmonitored)</span>
            @endif
            @if($score['raw_total'] !== $score['total'])
                &middot; <span class="fw-semibold">capped from {{ $score['raw_total'] }}</span>
            @endif
        </div>
    </div>
</div>

{{-- Cap reasons — a capped score always travels with its explanation. --}}
@if(!empty($score['cap_reasons']))
    <div class="alert alert-danger d-flex align-items-start gap-3 mb-4">
        <i class="bi bi-exclamation-octagon-fill fs-4"></i>
        <div>
            <strong>Score capped at {{ $score['total'] }}</strong>
            <ul class="mb-0 mt-1 small ps-3">
                @foreach($score['cap_reasons'] as $reason)
                    <li>{{ $reason['message'] }} <span class="text-muted">(ceiling {{ $reason['cap'] }})</span></li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

{{-- Check breakdown — all 12, with what each one actually looked at. --}}
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent">
        <strong><i class="bi bi-list-check me-1"></i>Health Check Breakdown</strong>
        <span class="text-muted small ms-2">Updated {{ \Illuminate\Support\Carbon::parse($score['updated_at'])->diffForHumans() }}</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Check</th>
                    <th class="text-center">Points</th>
                    <th class="text-center">Passing</th>
                    <th class="text-center">Unknown</th>
                    <th>Detail</th>
                    <th class="text-end pe-3">Updated</th>
                </tr>
            </thead>
            <tbody>
            @foreach($score['categories'] as $cat)
                <tr class="table-light">
                    <td colspan="6" class="ps-3 small fw-bold text-uppercase text-muted">
                        {{ $cat['label'] }} &mdash; {{ $cat['points'] }}/{{ $cat['max_points'] }}
                    </td>
                </tr>
                @foreach($cat['checks'] as $check)
                    @php
                        $tone = match($check['status']) {
                            'pass' => 'success', 'degraded' => 'warning',
                            'fail' => 'danger', default => 'secondary',
                        };
                    @endphp
                    <tr>
                        <td class="ps-3">
                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }} me-1">
                                {{ ucfirst($check['status']) }}
                            </span>
                            <a href="{{ $check['portal_url'] }}" class="text-decoration-none fw-semibold">
                                {{ $check['label'] }}
                            </a>
                        </td>
                        <td class="text-center fw-semibold text-{{ $tone }}">
                            {{ $check['points'] }}<span class="text-muted small">/{{ $check['max_points'] }}</span>
                        </td>
                        <td class="text-center small">
                            @if($check['total'] > 0)
                                {{ $check['passing'] }}/{{ $check['total'] }}
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="text-center small">
                            @if($check['unknown'] > 0)
                                <span class="badge bg-secondary-subtle text-secondary">{{ $check['unknown'] }}</span>
                            @else
                                <span class="text-muted">&mdash;</span>
                            @endif
                        </td>
                        <td class="small">
                            {{ $check['message'] }}
                            {{-- Name what failed. A score that says "8 of 10" without
                                 saying which two is not actionable. --}}
                            @if(!empty($check['failures']))
                                <ul class="mb-0 mt-1 ps-3 text-muted" style="font-size:11px">
                                    @foreach(array_slice($check['failures'], 0, 6) as $f)
                                        <li>
                                            <span class="fw-semibold">{{ $f['label'] }}</span>
                                            @if(!empty($f['detail'])) &mdash; {{ $f['detail'] }} @endif
                                        </li>
                                    @endforeach
                                    @if(count($check['failures']) > 6)
                                        <li class="fst-italic">and {{ count($check['failures']) - 6 }} more</li>
                                    @endif
                                </ul>
                            @endif
                        </td>
                        <td class="text-end pe-3 small text-muted text-nowrap">
                            {{ $check['last_updated_at']
                                ? \Illuminate\Support\Carbon::parse($check['last_updated_at'])->diffForHumans()
                                : '—' }}
                        </td>
                    </tr>
                @endforeach
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="row g-4">
    {{-- Left Column --}}
    <div class="col-lg-8">

        {{-- 2. Branch Tunnels (watchdog) --}}
        {{-- Was VpnTunnel, whose own docblock calls the model vestigial: nothing
             writes those rows and the table is empty in production, so this card
             was permanently blank. BranchTunnel is what the watchdog maintains,
             and it carries a `degraded` state the old one had no concept of --
             gateway reachable, but a carried subnet dark. --}}
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-shield-lock me-1"></i>Branch Tunnels ({{ $branchTunnels->count() }})</strong>
                @php
                    $vpnUp = $branchTunnels->where('state', 'up')->count();
                    $vpnDegraded = $branchTunnels->where('state', 'degraded')->count();
                    $vpnDown = $branchTunnels->where('state', 'down')->count();
                @endphp
                <span>
                    <span class="badge bg-success">{{ $vpnUp }} Up</span>
                    @if($vpnDegraded > 0)<span class="badge bg-warning text-dark">{{ $vpnDegraded }} Degraded</span>@endif
                    @if($vpnDown > 0)<span class="badge bg-danger">{{ $vpnDown }} Down</span>@endif
                </span>
            </div>
            <div class="card-body p-0">
                @forelse($branchTunnels as $t)
                @php
                    $tone = match($t->state) {
                        'up' => 'bg-success', 'degraded' => 'bg-warning text-dark',
                        'down' => 'bg-danger', default => 'bg-secondary',
                    };
                    $downProbes = $t->activeProbes->where('status', 'down');
                @endphp
                <div class="px-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }} small">
                    <div class="d-flex align-items-center">
                        <span class="badge {{ $tone }} me-2">
                            <i class="bi bi-circle-fill me-1" style="font-size:7px"></i>{{ ucfirst($t->state) }}
                        </span>
                        <span class="fw-semibold me-2">{{ $t->name }}</span>
                        <span class="text-muted font-monospace">{{ $t->firewall_ip }}</span>
                        <span class="text-muted ms-auto">{{ $t->last_ping_at?->diffForHumans() ?? 'never checked' }}</span>
                    </div>
                    @if($downProbes->isNotEmpty())
                        <div class="text-warning mt-1" style="font-size:11px">
                            <i class="bi bi-exclamation-triangle"></i>
                            Unreachable subnets: {{ $downProbes->pluck('label')->implode(', ') }}
                        </div>
                    @endif
                </div>
                @empty
                <div class="text-center py-3 text-muted small">No branch tunnels configured.</div>
                @endforelse
            </div>
        </div>

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
