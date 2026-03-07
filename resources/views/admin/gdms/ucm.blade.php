@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-hdd-network me-2 text-primary"></i>UCM Status
        </h4>
        <small class="text-muted">Live status from each configured UCM server</small>
    </div>
    <a href="{{ route('admin.gdms.ucm') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </a>
</div>

@if(count($results) === 0)
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-circle me-2"></i>
        No active UCM servers found. Add one in <a href="{{ route('admin.settings.index') }}">Settings</a>.
    </div>
@else

@php
    $totalOnline      = collect($results)->filter(fn($r) => $r['online'])->count();
    $totalOffline     = collect($results)->filter(fn($r) => !$r['online'])->count();
    $totalExts        = collect($results)->sum(fn($r) => $r['summary']['total']);
    $totalIdle        = collect($results)->sum(fn($r) => $r['summary']['idle']);
    $totalInUse       = collect($results)->sum(fn($r) => $r['summary']['inuse']);
    $totalUnavail     = collect($results)->sum(fn($r) => $r['summary']['unavailable']);
    $totalTrunks      = collect($results)->sum(fn($r) => $r['trunk_summary']['total']);
    $totalReachable   = collect($results)->sum(fn($r) => $r['trunk_summary']['reachable']);
    $totalUnreachable = collect($results)->sum(fn($r) => $r['trunk_summary']['unreachable']);
@endphp
<div class="d-flex flex-wrap gap-2 mb-4">
    <span class="badge bg-success fs-6 px-3 py-2"><i class="bi bi-circle-fill me-1" style="font-size:9px"></i>{{ $totalOnline }} UCM Online</span>
    @if($totalOffline)<span class="badge bg-danger fs-6 px-3 py-2"><i class="bi bi-circle-fill me-1" style="font-size:9px"></i>{{ $totalOffline }} UCM Offline</span>@endif
    <span class="badge bg-secondary fs-6 px-3 py-2">{{ $totalExts }} Extensions</span>
    <span class="badge bg-info text-dark fs-6 px-3 py-2">{{ $totalIdle }} Idle</span>
    @if($totalInUse)<span class="badge bg-warning text-dark fs-6 px-3 py-2">{{ $totalInUse }} In Use</span>@endif
    @if($totalUnavail)<span class="badge bg-secondary fs-6 px-3 py-2">{{ $totalUnavail }} Unavailable</span>@endif
    <span class="badge bg-primary fs-6 px-3 py-2">{{ $totalTrunks }} Trunks</span>
    @if($totalReachable)<span class="badge bg-success fs-6 px-3 py-2">{{ $totalReachable }} Trunk Reachable</span>@endif
    @if($totalUnreachable)<span class="badge bg-danger fs-6 px-3 py-2">{{ $totalUnreachable }} Trunk Unreachable</span>@endif
</div>

@foreach($results as $i => $result)
@php
    $server          = $result['server'];
    $online          = $result['online'];
    $sys             = $result['system'];
    $gen             = $result['general'];
    $exts            = $result['extensions'];
    $trunks          = $result['trunks'];
    $sum             = $result['summary'];
    $tsum            = $result['trunk_summary'];
    $mac             = $result['mac'];
    $uptime          = $sys['up-time-formatted'] ?? $sys['up-time'] ?? null;
    $cardBorder      = $online ? 'border-success' : 'border-danger';
    $headerBg        = $online ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10';
    $extCollapseId   = 'extList_' . $i;
    $trunkCollapseId = 'trunkList_' . $i;
@endphp
<div class="card mb-4 shadow-sm border-2 {{ $cardBorder }}">
    <div class="card-header {{ $headerBg }} d-flex align-items-center gap-2 flex-wrap py-2">
        @if($online)
            <span class="badge bg-success px-2 py-1"><i class="bi bi-circle-fill me-1" style="font-size:8px"></i>Online</span>
        @else
            <span class="badge bg-danger px-2 py-1"><i class="bi bi-circle-fill me-1" style="font-size:8px"></i>Offline</span>
        @endif
        <strong class="fs-6">{{ $server->name }}</strong>
        <span class="text-muted small font-monospace">{{ $server->url }}</span>
        @if($online && !empty($gen['product-model']))
            <span class="badge bg-primary ms-auto">{{ $gen['product-model'] }}</span>
        @endif
    </div>
    <div class="card-body py-3">
        @if($result['error'])
            <div class="alert alert-danger py-2 mb-0 small d-flex align-items-start gap-2">
                <i class="bi bi-exclamation-triangle-fill mt-1 flex-shrink-0"></i>
                <span class="font-monospace">{{ $result['error'] }}</span>
            </div>
        @else
        <div class="row g-3">

            <div class="col-12 col-lg-3">
                <h6 class="text-muted text-uppercase small fw-semibold mb-2">System Info</h6>
                <table class="table table-sm table-borderless mb-0 small">
                    <tbody>
                        @if(!empty($gen['product-model']))<tr><th class="text-muted ps-0" style="width:42%">Model</th><td>{{ $gen['product-model'] }}</td></tr>@endif
                        @if(!empty($gen['prog-version']))<tr><th class="text-muted ps-0">Firmware</th><td class="font-monospace">{{ $gen['prog-version'] }}</td></tr>@endif
                        @if($uptime)<tr><th class="text-muted ps-0">Uptime</th><td class="font-monospace fw-semibold">{{ $uptime }}</td></tr>@endif
                        @if(!empty($sys['system-time']))<tr><th class="text-muted ps-0">Time</th><td class="small text-muted">{{ $sys['system-time'] }}</td></tr>@endif
                        @if(!empty($sys['serial-number']))<tr><th class="text-muted ps-0">Serial</th><td class="font-monospace small text-muted">{{ $sys['serial-number'] }}</td></tr>@endif
                        @if($mac)<tr><th class="text-muted ps-0">MAC</th><td class="font-monospace small">{{ $mac }}</td></tr>@endif
                        @if($server->cloud_domain)<tr><th class="text-muted ps-0">Wave Domain</th><td class="font-monospace small">{{ $server->cloud_domain }}</td></tr>@endif
                    </tbody>
                </table>
            </div>

            <div class="col-12 col-md-6 col-lg-2">
                <h6 class="text-muted text-uppercase small fw-semibold mb-2">Extensions</h6>
                <div class="d-flex flex-column gap-1">
                    <div class="d-flex align-items-center gap-2"><span class="badge bg-secondary" style="min-width:2.5rem">{{ $sum['total'] }}</span><span class="small">Total</span></div>
                    <div class="d-flex align-items-center gap-2"><span class="badge bg-success" style="min-width:2.5rem">{{ $sum['idle'] }}</span><span class="small">Idle</span></div>
                    @if($sum['inuse'])<div class="d-flex align-items-center gap-2"><span class="badge bg-warning text-dark" style="min-width:2.5rem">{{ $sum['inuse'] }}</span><span class="small">In Use / Ringing</span></div>@endif
                    @if($sum['unavailable'])<div class="d-flex align-items-center gap-2"><span class="badge bg-danger" style="min-width:2.5rem">{{ $sum['unavailable'] }}</span><span class="small">Unavailable</span></div>@endif
                    @if($sum['other'])<div class="d-flex align-items-center gap-2"><span class="badge bg-secondary bg-opacity-50" style="min-width:2.5rem">{{ $sum['other'] }}</span><span class="small text-muted">Other</span></div>@endif
                </div>
            </div>
            <div class="col-12 col-md-6 col-lg-2">
                <h6 class="text-muted text-uppercase small fw-semibold mb-2">Trunks</h6>
                <div class="d-flex flex-column gap-1">
                    <div class="d-flex align-items-center gap-2"><span class="badge bg-primary" style="min-width:2.5rem">{{ $tsum['total'] }}</span><span class="small">Total</span></div>
                    @if($tsum['reachable'])<div class="d-flex align-items-center gap-2"><span class="badge bg-success" style="min-width:2.5rem">{{ $tsum['reachable'] }}</span><span class="small">Reachable</span></div>@endif
                    @if($tsum['unreachable'])<div class="d-flex align-items-center gap-2"><span class="badge bg-danger" style="min-width:2.5rem">{{ $tsum['unreachable'] }}</span><span class="small">Unreachable</span></div>@endif
                </div>
            </div>

            <div class="col-12 col-lg-5">
                @if(count($exts) > 0)
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="text-muted text-uppercase small fw-semibold mb-0">Extension List</h6>
                        <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $extCollapseId }}">Show / Hide</button>
                    </div>
                    <div class="collapse" id="{{ $extCollapseId }}">
                        <div class="table-responsive" style="max-height:220px;overflow-y:auto">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="table-light sticky-top"><tr><th>Ext</th><th>Name</th><th>Type</th><th>Status</th><th>Address</th></tr></thead>
                                <tbody>
                                    @foreach($exts as $ext)
                                    @php
                                        $extStatus = $ext['status'] ?? '';
                                        $extBadge  = match(strtolower($extStatus)) {
                                            'idle'                   => 'bg-success',
                                            'inuse','busy','ringing' => 'bg-warning text-dark',
                                            'unavailable'            => 'bg-danger',
                                            default                  => 'bg-secondary',
                                        };
                                    @endphp
                                    <tr>
                                        <td class="font-monospace fw-semibold">{{ $ext['extension'] ?? '---' }}</td>
                                        <td class="text-truncate" style="max-width:110px">{{ $ext['fullname'] ?? '' }}</td>
                                        <td class="text-muted small">{{ $ext['account_type'] ?? '' }}</td>
                                        <td><span class="badge {{ $extBadge }} small">{{ $extStatus }}</span></td>
                                        <td class="font-monospace small text-muted text-truncate" style="max-width:120px">{{ $ext['addr'] ?? '-' }}</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
                @if(count($trunks) > 0)
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <h6 class="text-muted text-uppercase small fw-semibold mb-0">Trunk List</h6>
                        <button class="btn btn-sm btn-outline-primary py-0 px-2" type="button" data-bs-toggle="collapse" data-bs-target="#{{ $trunkCollapseId }}">Show / Hide</button>
                    </div>
                    <div class="collapse" id="{{ $trunkCollapseId }}">
                        <div class="table-responsive" style="max-height:220px;overflow-y:auto">
                            <table class="table table-sm table-hover mb-0 small">
                                <thead class="table-light sticky-top"><tr><th>#</th><th>Name</th><th>Host</th><th>Type</th><th>Status</th></tr></thead>
                                <tbody>
                                    @foreach($trunks as $trunk)
                                    @php
                                        $ts = strtolower($trunk['status'] ?? '');
                                        $trunkBadge = str_contains($ts, 'unreachable')
                                            ? 'bg-danger'
                                            : (str_contains($ts, 'reachable') ? 'bg-success' : 'bg-secondary');
                                    @endphp
                                    <tr>
                                        <td class="font-monospace text-muted">{{ $trunk['trunk_index'] ?? '' }}</td>
                                        <td class="fw-semibold">{{ $trunk['trunk_name'] ?? '---' }}</td>
                                        <td class="font-monospace small text-truncate" style="max-width:130px">{{ $trunk['host'] ?? '-' }}</td>
                                        <td class="text-muted small">{{ $trunk['trunk_type'] ?? '' }}</td>
                                        <td><span class="badge {{ $trunkBadge }} small">{{ $trunk['status'] ?? '-' }}</span></td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                @endif
            </div>

        </div>
        @endif
    </div>
</div>
@endforeach

@endif
@endsection
