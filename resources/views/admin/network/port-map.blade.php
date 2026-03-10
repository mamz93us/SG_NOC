@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-grid-3x3-gap me-2 text-primary"></i>Port Map</h4>
        <small class="text-muted">Visual port layout per switch with VLAN colour coding</small>
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-4 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search switch name/serial..." value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.port-map.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

{{-- VLAN Legend --}}
@if($vlans->isNotEmpty())
<div class="card shadow-sm mb-4">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center small">
        <strong class="me-2">VLAN Legend:</strong>
        @foreach($vlans as $vlan)
        @php $hue = ($vlan * 37) % 360; @endphp
        <span class="badge" style="background:hsl({{ $hue }},65%,50%);color:#fff">VLAN {{ $vlan }}</span>
        @endforeach
        <span class="badge bg-success ms-2">Connected</span>
        <span class="badge bg-secondary bg-opacity-50">Disconnected</span>
        <span class="badge bg-secondary bg-opacity-25">Disabled</span>
    </div>
</div>
@endif

@if($switches->isEmpty())
<div class="card shadow-sm">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-grid-3x3-gap display-4 d-block mb-2"></i>No switches found.
    </div>
</div>
@else
@foreach($switches as $sw)
<div class="card shadow-sm mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <div>
            <span class="badge {{ $sw->statusBadgeClass() }} me-2"><i class="bi bi-circle-fill me-1" style="font-size:7px"></i>{{ ucfirst($sw->status) }}</span>
            <strong>{{ $sw->name }}</strong>
            <span class="text-muted ms-2">{{ $sw->model }} &middot; {{ $sw->serial }}</span>
        </div>
        <div class="small text-muted">
            {{ $sw->branch?->name ?: 'Unassigned' }}
            &middot; {{ $sw->port_count }} ports
            &middot; {{ $sw->connectedPortPercent() }}% connected
        </div>
    </div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-1">
            @foreach($sw->ports->sortBy('port_id') as $port)
            @php
                $hue = $port->vlan ? (($port->vlan * 37) % 360) : 0;
                $isConnected = $port->isConnected();
                $isDisabled = $port->isDisabled();
                $bgStyle = '';
                $bgClass = '';

                if ($isDisabled) {
                    $bgClass = 'bg-secondary bg-opacity-25';
                } elseif ($isConnected && $port->vlan) {
                    $bgStyle = "background:hsl({$hue},65%,50%);color:#fff;";
                } elseif ($isConnected) {
                    $bgClass = 'bg-success';
                } else {
                    $bgClass = 'bg-secondary bg-opacity-50';
                }
            @endphp
            <div class="port-tile d-flex flex-column align-items-center justify-content-center border rounded {{ $bgClass }}"
                 style="width:52px;height:52px;font-size:10px;cursor:pointer;{{ $bgStyle }}"
                 data-bs-toggle="tooltip" data-bs-html="true"
                 title="<strong>{{ $port->label() }}</strong><br>Status: {{ $port->status ?? 'Unknown' }}<br>VLAN: {{ $port->vlan ?: '-' }}<br>Speed: {{ $port->speedLabel() }}<br>Client: {{ $port->client_hostname ?: ($port->client_mac ?: '-') }}{{ $port->is_uplink ? '<br><em>Uplink</em>' : '' }}">
                <span class="fw-bold {{ $isConnected && $port->vlan ? 'text-white' : ($isConnected ? 'text-white' : 'text-dark') }}">{{ $port->port_id }}</span>
                @if($port->is_uplink || ($sw->uplink_port_ids && $sw->isManualUplink($port->port_id)))
                <i class="bi bi-arrow-up-short {{ $isConnected ? 'text-white' : 'text-dark' }}" style="font-size:11px;margin-top:-3px"></i>
                @endif
                @if($port->vlan && $isConnected)
                <span class="text-white" style="font-size:8px;margin-top:-3px">V{{ $port->vlan }}</span>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</div>
@endforeach
@endif

@endsection

@push('scripts')
<script>
// Init Bootstrap tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
    tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
});
</script>
@endpush
