@extends('layouts.admin')

@section('content')

{{-- ═══════════════════════════════════════════════════════════════════
     PAGE HEADER
══════════════════════════════════════════════════════════════════════ --}}
<div class="d-flex align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0 fw-bold">Dashboard</h1>
        <small class="text-muted">System overview — UCM stats cached for 5 minutes</small>
    </div>
    <div class="ms-auto">
        <a href="{{ route('admin.gdms.ucm') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-arrow-repeat me-1"></i>Full UCM Status
        </a>
    </div>
</div>

{{-- ═══════════════════════════════════════════════════════════════════
     ROW 1 — DIRECTORY STATS
══════════════════════════════════════════════════════════════════════ --}}
<div class="row g-3 mb-4">

    {{-- Contacts --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.contacts.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 bg-primary bg-gradient text-white">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-person-lines-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($contactCount) }}</div>
                        <div class="small opacity-75 mt-1">Contacts</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Branches --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.branches.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 bg-info bg-gradient text-white">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-diagram-3-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($branchCount) }}</div>
                        <div class="small opacity-75 mt-1">Branches</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- UCM Online --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.gdms.ucm') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 {{ $ucmOnline > 0 ? 'bg-success' : 'bg-secondary' }} bg-gradient text-white">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-router-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ $ucmOnline }}<span class="fs-5 opacity-75">/{{ count($ucmStats) }}</span></div>
                        <div class="small opacity-75 mt-1">UCM Online</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Phones requesting XML --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.phone-logs.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 bg-warning bg-gradient text-dark">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="rounded-circle bg-dark bg-opacity-10 d-flex align-items-center justify-content-center" style="width:52px;height:52px;flex-shrink:0;">
                        <i class="bi bi-phone-fill fs-4"></i>
                    </div>
                    <div>
                        <div class="fs-2 fw-bold lh-1">{{ number_format($phoneRequestCount) }}</div>
                        <div class="small opacity-75 mt-1">Phones Requesting XML</div>
                        <div class="small opacity-50">{{ number_format($totalXmlRequests) }} total requests</div>
                    </div>
                </div>
            </div>
        </a>
    </div>

</div>

{{-- ═══════════════════════════════════════════════════════════════════
     ROW 2 — UCM AGGREGATE EXTENSION & TRUNK STATS
══════════════════════════════════════════════════════════════════════ --}}
@if(count($ucmStats) > 0)
<div class="row g-3 mb-4">

    {{-- Total Extensions --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.extensions.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-telephone-fill text-primary fs-3 mb-2 d-block"></i>
                    <div class="fs-2 fw-bold text-dark">{{ number_format($totalExt) }}</div>
                    <div class="small text-muted">Total Extensions</div>
                    <div class="mt-2 d-flex justify-content-center gap-1 flex-wrap">
                        <span class="badge bg-success">{{ $totalIdle }} Idle</span>
                        <span class="badge bg-warning text-dark">{{ $totalInUse }} In Use</span>
                        <span class="badge bg-danger">{{ $totalUnavail }} Off</span>
                    </div>
                </div>
            </div>
        </a>
    </div>

    {{-- Trunks (reachable / total) --}}
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.trunks.index') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body text-center py-3">
                    <i class="bi bi-hdd-network-fill text-secondary fs-3 mb-2 d-block"></i>
                    <div class="fs-2 fw-bold text-dark">
                        {{ number_format($totalReachable) }}<span class="fs-5 text-muted">/{{ number_format($totalTrunks) }}</span>
                    </div>
                    <div class="small text-muted">Trunks Reachable</div>
                    @if($totalUnreachable > 0)
                        <div class="mt-1">
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">{{ $totalUnreachable }} Unreachable</span>
                        </div>
                    @endif
                </div>
            </div>
        </a>
    </div>

    {{-- Registered extensions --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <i class="bi bi-check2-circle text-success fs-3 mb-2 d-block"></i>
                <div class="fs-2 fw-bold text-dark">{{ number_format($totalIdle + $totalInUse) }}</div>
                <div class="small text-muted">Registered Extensions</div>
                @if($totalExt > 0)
                    <div class="small text-muted mt-1">
                        {{ round(($totalIdle + $totalInUse) / $totalExt * 100) }}% of total
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Unregistered --}}
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <i class="bi bi-x-circle {{ $totalUnavail > 0 ? 'text-danger' : 'text-muted' }} fs-3 mb-2 d-block"></i>
                <div class="fs-2 fw-bold text-dark">{{ number_format($totalUnavail) }}</div>
                <div class="small text-muted">Unregistered Extensions</div>
                @if($totalExt > 0)
                    <div class="small text-muted mt-1">
                        {{ round($totalUnavail / $totalExt * 100) }}% of total
                    </div>
                @endif
            </div>
        </div>
    </div>

</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════
     ROW 3 — PER-UCM SERVER CARDS
══════════════════════════════════════════════════════════════════════ --}}
@if(count($ucmStats) > 0)
<h5 class="fw-semibold mb-3"><i class="bi bi-router-fill me-2 text-primary"></i>UCM Servers</h5>
<div class="row g-3 mb-4">
    @foreach($ucmStats as $ucm)
    @php
        $s      = $ucm['stats'];
        $server = $ucm['server'];
        $online = $s['online'] ?? false;
        $mac    = $s['mac'] ?? null;
        $uptime = $s['uptime'] ?? null;
    @endphp
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid {{ $online ? '#198754' : '#dc3545' }} !important;">
            <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
                <span class="badge {{ $online ? 'bg-success' : 'bg-danger' }} rounded-pill">
                    <i class="bi bi-{{ $online ? 'wifi' : 'wifi-off' }} me-1"></i>{{ $online ? 'Online' : 'Offline' }}
                </span>
                <span class="fw-semibold text-dark">{{ $server->name }}</span>
                @if(!$server->is_active)
                    <span class="badge bg-secondary ms-auto">Disabled</span>
                @elseif($online)
                    <span class="badge bg-light text-dark ms-auto border">{{ $s['model'] ?? '' }}</span>
                @endif
            </div>
            <div class="card-body py-2">
                @if($online)
                    <div class="row g-2 text-center mb-2">
                        <div class="col-4">
                            <div class="p-2 rounded bg-light">
                                <div class="fs-4 fw-bold text-primary">{{ $s['extensions']['total'] }}</div>
                                <div class="text-muted" style="font-size:.72rem;">Extensions</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded bg-light">
                                <div class="fs-4 fw-bold text-secondary">{{ $s['trunk_counts']['total'] ?? 0 }}</div>
                                <div class="text-muted" style="font-size:.72rem;">Trunks</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div class="p-2 rounded bg-light">
                                <div class="fs-4 fw-bold {{ ($s['extensions']['unavailable'] ?? 0) > 0 ? 'text-danger' : 'text-success' }}">
                                    {{ ($s['extensions']['idle'] ?? 0) + ($s['extensions']['inuse'] ?? 0) }}
                                </div>
                                <div class="text-muted" style="font-size:.72rem;">Registered</div>
                            </div>
                        </div>
                    </div>

                    {{-- Extension status progress bar --}}
                    @if($s['extensions']['total'] > 0)
                    @php
                        $pIdle    = round($s['extensions']['idle']        / $s['extensions']['total'] * 100);
                        $pInUse   = round($s['extensions']['inuse']       / $s['extensions']['total'] * 100);
                        $pUnavail = round($s['extensions']['unavailable'] / $s['extensions']['total'] * 100);
                    @endphp
                    <div class="progress mb-2" style="height:6px;"
                         title="Idle: {{ $s['extensions']['idle'] }} | In Use: {{ $s['extensions']['inuse'] }} | Unavailable: {{ $s['extensions']['unavailable'] }}">
                        <div class="progress-bar bg-success" style="width:{{ $pIdle }}%"></div>
                        <div class="progress-bar bg-warning" style="width:{{ $pInUse }}%"></div>
                        <div class="progress-bar bg-danger"  style="width:{{ $pUnavail }}%"></div>
                    </div>
                    @endif

                    {{-- Details --}}
                    <table class="table table-sm table-borderless mb-1" style="font-size:.8rem;">
                        <tr>
                            <td class="text-muted ps-0" style="width:38%;">Serial No.</td>
                            <td class="fw-semibold font-monospace">{{ $s['serial'] ?? '-' }}</td>
                        </tr>
                        @if($mac)
                        <tr>
                            <td class="text-muted ps-0">MAC</td>
                            <td class="fw-semibold font-monospace">{{ $mac }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="text-muted ps-0">Firmware</td>
                            <td class="fw-semibold">{{ $s['firmware'] ?? '-' }}</td>
                        </tr>
                        @if($uptime)
                        <tr>
                            <td class="text-muted ps-0">Uptime</td>
                            <td class="fw-semibold font-monospace">{{ $uptime }}</td>
                        </tr>
                        @endif
                        @if($server->cloud_domain)
                        <tr>
                            <td class="text-muted ps-0">Wave Domain</td>
                            <td class="fw-semibold font-monospace small">{{ $server->cloud_domain }}</td>
                        </tr>
                        @endif
                    </table>

                    <div class="d-flex gap-1 flex-wrap">
                        <span class="badge bg-success-subtle text-success border border-success-subtle">{{ $s['extensions']['idle'] }} Idle</span>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">{{ $s['extensions']['inuse'] }} In Use</span>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">{{ $s['extensions']['unavailable'] }} Unavailable</span>
                    </div>
                @else
                    <div class="text-danger small py-2">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $s['error'] ?? 'Cannot connect to UCM' }}
                    </div>
                    <div class="text-muted small font-monospace">{{ $server->url }}</div>
                @endif
            </div>
            <div class="card-footer bg-transparent d-flex gap-2 py-2">
                <a href="{{ route('admin.extensions.index', ['ucm_id' => $server->id]) }}" class="btn btn-sm btn-outline-primary flex-fill">
                    <i class="bi bi-telephone me-1"></i>Extensions
                </a>
                <a href="{{ route('admin.trunks.index', ['ucm_id' => $server->id]) }}" class="btn btn-sm btn-outline-secondary flex-fill">
                    <i class="bi bi-hdd-network me-1"></i>Trunks
                </a>
            </div>
        </div>
    </div>
    @endforeach
</div>
@else
<div class="alert alert-info border-0 shadow-sm">
    <i class="bi bi-info-circle me-2"></i>No UCM servers configured yet.
    <a href="{{ route('admin.settings.index') }}" class="alert-link">Add one in Settings</a>.
</div>
@endif

{{-- ═══════════════════════════════════════════════════════════════════
     QUICK ACTIONS
══════════════════════════════════════════════════════════════════════ --}}
<h5 class="fw-semibold mb-3 mt-2"><i class="bi bi-lightning-fill me-2 text-warning"></i>Quick Actions</h5>
<div class="row g-3">
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.contacts.create') }}" class="btn btn-outline-primary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-person-plus-fill fs-4"></i>
            <span class="small">New Contact</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.extensions.index') }}" class="btn btn-outline-secondary w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-telephone-plus-fill fs-4"></i>
            <span class="small">Extensions</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('admin.activity-logs') }}" class="btn btn-outline-dark w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-shield-check fs-4"></i>
            <span class="small">Audit Log</span>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="{{ route('phonebook.xml') }}" target="_blank" class="btn btn-outline-success w-100 py-3 d-flex flex-column align-items-center gap-1">
            <i class="bi bi-filetype-xml fs-4"></i>
            <span class="small">Download XML</span>
        </a>
    </div>
</div>

@endsection
