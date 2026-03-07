@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-exclamation-triangle-fill me-2 text-danger"></i>NOC Events</h4>
        <small class="text-muted">Rule-based alert event log</small>
    </div>
    <a href="{{ route('admin.noc.dashboard') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2 me-1"></i>NOC Dashboard</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<form method="GET" class="mb-3 d-flex gap-2 flex-wrap">
    <select name="module" class="form-select form-select-sm" style="max-width:140px" onchange="this.form.submit()">
        <option value="">All Modules</option>
        @foreach(['network','identity','voip','assets'] as $m)
        <option value="{{ $m }}" {{ request('module') === $m ? 'selected' : '' }}>{{ ucfirst($m) }}</option>
        @endforeach
    </select>
    <select name="severity" class="form-select form-select-sm" style="max-width:130px" onchange="this.form.submit()">
        <option value="">All Severity</option>
        @foreach(['critical','warning','info'] as $s)
        <option value="{{ $s }}" {{ request('severity') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
    <select name="status" class="form-select form-select-sm" style="max-width:140px" onchange="this.form.submit()">
        <option value="">Open Events</option>
        <option value="resolved" {{ request('status') === 'resolved' ? 'selected' : '' }}>Resolved</option>
        <option value="all" {{ request('status') === 'all' ? 'selected' : '' }}>All</option>
    </select>
    @if(request()->anyFilled(['module','severity','status']))
    <a href="{{ route('admin.noc.events') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
    @endif
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($events->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-check-circle-fill display-4 d-block mb-2 text-success"></i>
            No events found. Everything looks good!
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Severity</th>
                        <th>Module</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>First Seen</th>
                        <th>Last Seen</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                    <tr class="{{ $event->status === 'resolved' ? 'table-light text-muted' : '' }}">
                        <td class="ps-3"><span class="badge {{ $event->severityBadgeClass() }}">{{ ucfirst($event->severity) }}</span></td>
                        <td><i class="{{ $event->moduleIcon() }} me-1"></i>{{ ucfirst($event->module) }}</td>
                        <td class="fw-semibold">{{ $event->title }}</td>
                        <td class="text-muted">{{ Str::limit($event->message, 80) }}</td>
                        <td><span class="badge {{ $event->statusBadgeClass() }}">{{ ucfirst($event->status) }}</span></td>
                        <td class="text-muted text-nowrap">{{ $event->first_seen->diffForHumans() }}</td>
                        <td class="text-muted text-nowrap">{{ $event->last_seen->diffForHumans() }}</td>
                        <td class="pe-3">
                            @can('manage-noc')
                            @if($event->status === 'open')
                            <form method="POST" action="{{ route('admin.noc.events.acknowledge', $event->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-xs btn-outline-secondary me-1">Ack</button>
                            </form>
                            @endif
                            @if(in_array($event->status, ['open','acknowledged']))
                            <form method="POST" action="{{ route('admin.noc.events.resolve', $event->id) }}" class="d-inline">
                                @csrf
                                <button type="submit" class="btn btn-xs btn-outline-success">Resolve</button>
                            </form>
                            @endif
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top">{{ $events->withQueryString()->links() }}</div>
        @endif
    </div>
</div>
@endsection
