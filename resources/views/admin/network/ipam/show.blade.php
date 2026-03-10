@extends('layouts.admin')
@section('title', 'Subnet: ' . $subnet->cidr)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">
            <i class="bi bi-grid-3x3 me-2"></i>IP Grid: <code>{{ $subnet->cidr }}</code>
            @if($subnet->vlan) <span class="badge bg-info ms-2">VLAN {{ $subnet->vlan }}</span> @endif
        </h4>
        <a href="{{ route('admin.network.ipam.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    {{-- Subnet Info --}}
    <div class="row g-3 mb-4">
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-2">
                    <div class="fw-bold">{{ $subnet->branch?->name ?? '-' }}</div>
                    <div class="text-muted small">Branch</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-2">
                    <div class="fw-bold">{{ $subnet->gateway ?? '-' }}</div>
                    <div class="text-muted small">Gateway</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-2">
                    <div class="fw-bold">{{ $subnet->total_ips }}</div>
                    <div class="text-muted small">Total IPs</div>
                </div>
            </div>
        </div>
        <div class="col-md-2">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-2">
                    <div class="fw-bold">{{ $subnet->utilizationPercent() }}%</div>
                    <div class="text-muted small">Utilization</div>
                </div>
            </div>
        </div>
    </div>

    {{-- Legend --}}
    <div class="d-flex gap-3 mb-3 flex-wrap">
        <span><span class="badge bg-success">&nbsp;&nbsp;</span> Available</span>
        <span><span class="badge bg-primary">&nbsp;&nbsp;</span> Reserved</span>
        <span><span class="badge bg-info">&nbsp;&nbsp;</span> DHCP</span>
        <span><span class="badge bg-secondary">&nbsp;&nbsp;</span> Static</span>
        <span><span class="badge bg-danger">&nbsp;&nbsp;</span> Conflict</span>
        <span><span class="badge bg-dark">&nbsp;&nbsp;</span> Offline</span>
    </div>

    {{-- IP Grid --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <div class="d-flex flex-wrap gap-1">
                @foreach($grid as $cell)
                    @php
                        $bgClass = match($cell['status']) {
                            'available' => 'bg-success',
                            'reserved'  => 'bg-primary',
                            'dhcp'      => 'bg-info',
                            'static'    => 'bg-secondary',
                            'conflict'  => 'bg-danger',
                            'offline'   => 'bg-dark',
                            default     => 'bg-light text-dark border',
                        };
                        $lastOctet = last(explode('.', $cell['ip']));
                        $tooltip = $cell['ip'];
                        if ($cell['device_name']) $tooltip .= " - " . $cell['device_name'];
                        if ($cell['mac']) $tooltip .= " (" . $cell['mac'] . ")";
                    @endphp
                    <div class="d-inline-flex align-items-center justify-content-center {{ $bgClass }} text-white rounded"
                         style="width:40px;height:32px;font-size:11px;cursor:pointer"
                         data-bs-toggle="tooltip"
                         data-bs-placement="top"
                         title="{{ $tooltip }}">
                        {{ $lastOctet }}
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (el) { return new bootstrap.Tooltip(el); });
});
</script>
@endpush
@endsection
