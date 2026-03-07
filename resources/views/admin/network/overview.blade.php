@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-diagram-3-fill me-2 text-primary"></i>Network Overview
        </h4>
        <small class="text-muted">
            Meraki switch observability &mdash; read-only
            @if($lastSync)
                &bull; Last sync: <span class="font-monospace">{{ \Carbon\Carbon::parse($lastSync)->diffForHumans() }}</span>
            @endif
            @if(isset($lastSyncLog) && $lastSyncLog)
                &bull; <span class="badge {{ $lastSyncLog->statusBadgeClass() }}">{{ ucfirst($lastSyncLog->status) }}</span>
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.network.sync-logs') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-clock-history me-1"></i>Sync Logs
        </a>
        @can('manage-network-settings')
        <form method="POST" action="{{ route('admin.network.sync') }}">
            @csrf
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Now
            </button>
        </form>
        @endcan
    </div>
</div>

@if(!$settings->meraki_enabled)
<div class="alert alert-warning">
    <i class="bi bi-exclamation-circle me-2"></i>
    Meraki integration is <strong>disabled</strong>.
    @can('manage-settings')
    Enable it in <a href="{{ route('admin.settings.index') }}#meraki">Settings → Meraki</a>.
    @endcan
</div>
@endif

{{-- ── Summary stat cards ── --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-primary">{{ $totalSwitches }}</div>
                <div class="small text-muted mt-1"><i class="bi bi-hdd-network me-1"></i>Total Switches</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-success">{{ $onlineSwitches }}</div>
                <div class="small text-muted mt-1"><i class="bi bi-circle-fill text-success me-1" style="font-size:9px"></i>Online</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="display-6 fw-bold text-info">{{ $totalClients }}</div>
                <div class="small text-muted mt-1"><i class="bi bi-laptop me-1"></i>Clients (24 h)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center h-100 border-0 shadow-sm">
            <div class="card-body py-3">
                <div class="display-6 fw-bold {{ $connectedPorts > 0 ? 'text-success' : 'text-secondary' }}">
                    {{ $connectedPorts }}<span class="fs-4 text-muted fw-normal">/{{ $totalPorts }}</span>
                </div>
                <div class="small text-muted mt-1"><i class="bi bi-ethernet me-1"></i>Ports Connected</div>
            </div>
        </div>
    </div>
</div>

{{-- ── Status strip ── --}}
<div class="d-flex flex-wrap gap-2 mb-4">
    <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-circle-fill me-1" style="font-size:9px"></i>{{ $onlineSwitches }} Online</span>
    @if($offlineSwitches)<span class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-circle-fill me-1" style="font-size:9px"></i>{{ $offlineSwitches }} Offline</span>@endif
    @if($alertingSwitches)<span class="badge bg-warning text-dark fs-6 px-3 py-2"><i class="bi bi-exclamation-circle-fill me-1" style="font-size:9px"></i>{{ $alertingSwitches }} Alerting</span>@endif
    <span class="badge bg-info text-dark fs-6 px-3 py-2">{{ $onlineClients }} Online Clients</span>
</div>

{{-- ── Switch cards grid ── --}}
@if($switches->isEmpty())
<div class="card shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-hdd-network display-4 mb-3 d-block"></i>
        <p class="mb-1">No switches found.</p>
        @can('manage-network-settings')
        <p class="small">Configure your Meraki API key in <a href="{{ route('admin.settings.index') }}">Settings</a> and run a sync.</p>
        @endcan
    </div>
</div>
@else
<div class="row g-3">
    @foreach($switches as $sw)
    @php
        $borderClass  = $sw->isOnline() ? 'border-success' : ($sw->status === 'alerting' ? 'border-warning' : 'border-danger');
        $headerBg     = $sw->isOnline() ? 'bg-success bg-opacity-10' : ($sw->status === 'alerting' ? 'bg-warning bg-opacity-10' : 'bg-danger bg-opacity-10');
    @endphp
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card h-100 shadow-sm border-2 {{ $borderClass }}">
            <div class="card-header {{ $headerBg }} d-flex align-items-center gap-2 py-2">
                <span class="badge {{ $sw->statusBadgeClass() }} px-2 py-1 small">
                    <i class="bi bi-circle-fill me-1" style="font-size:8px"></i>{{ ucfirst($sw->status) }}
                </span>
                <strong class="fs-6 text-truncate">{{ $sw->name }}</strong>
                <span class="ms-auto badge bg-secondary small">{{ $sw->model }}</span>
            </div>
            <div class="card-body py-2 small">
                <div class="row g-0">
                    <div class="col-6">
                        @if($sw->lan_ip)
                        <div><span class="text-muted">IP:</span> <code>{{ $sw->lan_ip }}</code></div>
                        @endif
                        @if($sw->mac)
                        <div><span class="text-muted">MAC:</span> <code class="small">{{ $sw->mac }}</code></div>
                        @endif
                        @if($sw->network_name)
                        <div><span class="text-muted">Network:</span> {{ $sw->network_name }}</div>
                        @endif
                    </div>
                    <div class="col-6 text-end">
                        <div><span class="badge bg-secondary">{{ $sw->port_count }} ports</span></div>
                        <div class="mt-1"><span class="badge bg-info text-dark">{{ $sw->clients_count }} clients</span></div>
                    </div>
                </div>
                @if($sw->port_count > 0)
                @php
                    $pct = $sw->connectedPortPercent();
                @endphp
                <div class="mt-2">
                    <div class="d-flex justify-content-between small text-muted mb-1">
                        <span>Ports connected</span><span>{{ $pct }}%</span>
                    </div>
                    <div class="progress" style="height:6px">
                        <div class="progress-bar bg-success" style="width:{{ $pct }}%"></div>
                    </div>
                </div>
                @endif
            </div>
            <div class="card-footer bg-transparent py-2">
                <a href="{{ route('admin.network.switch-detail', $sw->serial) }}"
                   class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-ethernet me-1"></i>View Ports &amp; Clients
                </a>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif

@endsection
