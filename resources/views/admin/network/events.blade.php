@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-clock-history me-2 text-primary"></i>Change Monitor
        </h4>
        <small class="text-muted">Network events from Meraki — read-only</small>
    </div>
</div>

{{-- ── Filters ── --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <select name="serial" class="form-select form-select-sm">
            <option value="">All Switches</option>
            @foreach($switches as $sw)
            <option value="{{ $sw->serial }}" {{ request('serial') == $sw->serial ? 'selected' : '' }}>
                {{ $sw->name ?: $sw->serial }}
            </option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="type" class="form-select form-select-sm" style="max-width:220px">
            <option value="">All Event Types</option>
            @foreach($eventTypes as $t)
            <option value="{{ $t }}" {{ request('type') == $t ? 'selected' : '' }}>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="network" class="form-select form-select-sm">
            <option value="">All Networks</option>
            @foreach($networks as $net)
            <option value="{{ $net->network_id }}" {{ request('network') == $net->network_id ? 'selected' : '' }}>
                {{ $net->network_name ?: $net->network_id }}
            </option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.network.events') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($events->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clock-history display-4 mb-3 d-block"></i>
            No events found. Run a sync to fetch event data from Meraki.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Time</th>
                        <th>Event Type</th>
                        <th>Switch</th>
                        <th>Network</th>
                        <th>Description</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                    <tr>
                        <td class="font-monospace text-muted small text-nowrap">
                            {{ $event->occurred_at ? $event->occurred_at->format('Y-m-d H:i:s') : '-' }}
                        </td>
                        <td>
                            <span class="badge {{ $event->typeBadgeClass() }} small">
                                {{ $event->event_type ?: 'unknown' }}
                            </span>
                        </td>
                        <td>
                            @if($event->switch_serial)
                                @if($event->networkSwitch)
                                <a href="{{ route('admin.network.switch-detail', $event->switch_serial) }}"
                                   class="text-decoration-none">{{ $event->networkSwitch->name }}</a>
                                @else
                                <span class="font-monospace text-muted">{{ $event->switch_serial }}</span>
                                @endif
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $event->network_id ?: '-' }}</td>
                        <td>{{ Str::limit($event->description, 80) }}</td>
                        <td>
                            @if($event->details)
                            <button class="btn btn-sm btn-outline-secondary py-0 px-1"
                                type="button"
                                data-bs-toggle="collapse"
                                data-bs-target="#event_{{ $event->id }}">
                                <i class="bi bi-chevron-down" style="font-size:10px"></i>
                            </button>
                            @endif
                        </td>
                    </tr>
                    @if($event->details)
                    <tr class="collapse" id="event_{{ $event->id }}">
                        <td colspan="6" class="bg-light">
                            <pre class="small mb-0 p-2 font-monospace text-muted" style="max-height:150px;overflow:auto">{{ json_encode($event->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        </td>
                    </tr>
                    @endif
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2">
            {{ $events->links() }}
        </div>
        @endif
    </div>
</div>

@endsection
