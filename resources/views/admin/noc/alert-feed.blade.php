@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2 text-danger"></i>Alert Feed</h4>
        <small class="text-muted">Unified notification center &mdash; all system alerts in one place</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.noc.dashboard') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
    </div>
</div>

{{-- Stats --}}
<div class="row g-3 mb-4">
    <div class="col-auto">
        <div class="card border-0 shadow-sm {{ $criticalOpen > 0 ? 'border-start border-danger border-3' : '' }}">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-octagon-fill text-danger fs-4"></i>
                <div>
                    <div class="fw-bold fs-5 text-danger">{{ $criticalOpen }}</div>
                    <div class="text-muted small">Critical Open</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-circle-fill text-warning fs-4"></i>
                <div>
                    <div class="fw-bold fs-5">{{ $openCount }}</div>
                    <div class="text-muted small">Open Alerts</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-auto">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3 d-flex align-items-center gap-2">
                <i class="bi bi-eye-fill text-info fs-4"></i>
                <div>
                    <div class="fw-bold fs-5">{{ $ackedCount }}</div>
                    <div class="text-muted small">Acknowledged</div>
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Search alerts..." value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="severity" class="form-select form-select-sm">
            <option value="">All Severity</option>
            <option value="critical" {{ request('severity') == 'critical' ? 'selected' : '' }}>Critical</option>
            <option value="warning"  {{ request('severity') == 'warning'  ? 'selected' : '' }}>Warning</option>
            <option value="info"     {{ request('severity') == 'info'     ? 'selected' : '' }}>Info</option>
        </select>
    </div>
    <div class="col-auto">
        <select name="module" class="form-select form-select-sm">
            <option value="">All Modules</option>
            <option value="network"  {{ request('module') == 'network'  ? 'selected' : '' }}>Network</option>
            <option value="identity" {{ request('module') == 'identity' ? 'selected' : '' }}>Identity</option>
            <option value="voip"     {{ request('module') == 'voip'     ? 'selected' : '' }}>VoIP</option>
            <option value="assets"   {{ request('module') == 'assets'   ? 'selected' : '' }}>Assets</option>
            <option value="vpn"      {{ request('module') == 'vpn'      ? 'selected' : '' }}>VPN</option>
            <option value="snmp"     {{ request('module') == 'snmp'     ? 'selected' : '' }}>SNMP</option>
            <option value="ping"     {{ request('module') == 'ping'     ? 'selected' : '' }}>Ping</option>
        </select>
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            <option value="open"         {{ request('status') == 'open'         ? 'selected' : '' }}>Open</option>
            <option value="acknowledged" {{ request('status') == 'acknowledged' ? 'selected' : '' }}>Acknowledged</option>
            <option value="resolved"     {{ request('status') == 'resolved'     ? 'selected' : '' }}>Resolved</option>
        </select>
    </div>
    <div class="col-auto">
        <input type="date" name="date_from" class="form-control form-control-sm" value="{{ request('date_from') }}" title="From">
    </div>
    <div class="col-auto">
        <input type="date" name="date_to" class="form-control form-control-sm" value="{{ request('date_to') }}" title="To">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.noc.alerts') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

{{-- Alert List --}}
<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($events->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-emoji-smile display-4 d-block mb-2 text-success opacity-50"></i>
            <p class="mb-0">No alerts found matching your filters.</p>
        </div>
        @else
        @foreach($events as $event)
        <div class="d-flex align-items-start gap-3 p-3 border-bottom {{ $event->status === 'resolved' ? 'opacity-50' : '' }}"
             style="border-left: 4px solid {{ $event->severity === 'critical' ? '#dc3545' : ($event->severity === 'warning' ? '#ffc107' : '#0dcaf0') }} !important;">
            {{-- Severity icon --}}
            <div class="mt-1">
                <i class="bi {{ $event->severityIcon() }} fs-5 {{ $event->severity === 'critical' ? 'text-danger' : ($event->severity === 'warning' ? 'text-warning' : 'text-info') }}"></i>
            </div>

            {{-- Content --}}
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 mb-1">
                    <span class="badge {{ $event->severityBadgeClass() }}">{{ ucfirst($event->severity) }}</span>
                    <span class="badge bg-dark bg-opacity-10 text-dark border"><i class="{{ $event->moduleIcon() }} me-1"></i>{{ $event->moduleLabel() }}</span>
                    <span class="badge {{ $event->statusBadgeClass() }}">{{ ucfirst($event->status) }}</span>
                </div>
                <div class="fw-semibold">{{ $event->title }}</div>
                <div class="text-muted small">{{ Str::limit($event->message, 120) }}</div>

                {{-- Lifecycle dots --}}
                <div class="d-flex align-items-center gap-1 mt-2 small">
                    <span class="text-muted"><i class="bi bi-clock me-1"></i>{{ $event->first_seen->diffForHumans() }}</span>
                    @if($event->status !== 'open')
                    <span class="text-muted mx-1">&rarr;</span>
                    <span class="text-warning"><i class="bi bi-eye me-1"></i>Ack'd</span>
                    @endif
                    @if($event->status === 'resolved')
                    <span class="text-muted mx-1">&rarr;</span>
                    <span class="text-success"><i class="bi bi-check-circle me-1"></i>Resolved {{ $event->resolved_at?->diffForHumans() }}</span>
                    @endif
                </div>
            </div>

            {{-- Actions --}}
            <div class="text-nowrap">
                @can('manage-noc')
                @if($event->status === 'open')
                <form method="POST" action="{{ route('admin.noc.events.acknowledge', $event->id) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-outline-warning" title="Acknowledge"><i class="bi bi-eye"></i></button>
                </form>
                @endif
                @if($event->status !== 'resolved')
                <form method="POST" action="{{ route('admin.noc.events.resolve', $event->id) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm btn-outline-success" title="Resolve"><i class="bi bi-check-lg"></i></button>
                </form>
                @endif
                @endcan
            </div>
        </div>
        @endforeach
        <div class="p-3">{{ $events->links() }}</div>
        @endif
    </div>
</div>

@endsection
