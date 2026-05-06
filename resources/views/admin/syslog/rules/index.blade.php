@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bell-fill me-2 text-warning"></i>Syslog Alert Rules</h4>
        <small class="text-muted">Pattern → NocEvent. Matched messages create or refresh an open NOC alert.</small>
    </div>
    <div class="d-flex gap-2">
        <form method="POST" action="{{ route('admin.syslog.run-processors') }}">
            @csrf
            <button type="submit" class="btn btn-outline-secondary btn-sm" title="Run tagger + alert matcher now">
                <i class="bi bi-play-fill me-1"></i>Run now
            </button>
        </form>
        <a href="{{ route('admin.syslog.rules.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New rule
        </a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($rules->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-bell-slash display-4 d-block mb-2"></i>
            No alert rules yet. Create one to start turning syslog patterns into NOC events.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Name</th>
                        <th>Filters</th>
                        <th>Event</th>
                        <th>Cooldown</th>
                        <th>Matches</th>
                        <th>Last match</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rules as $r)
                    <tr class="{{ $r->enabled ? '' : 'table-light text-muted' }}">
                        <td class="ps-3">
                            @if(!$r->enabled)<i class="bi bi-pause-circle text-muted me-1" title="Disabled"></i>@endif
                            <span class="fw-semibold">{{ $r->name }}</span>
                        </td>
                        <td class="small text-muted">
                            <div>severity ≤ <strong>{{ $r->severity_max }}</strong></div>
                            @if($r->source_type)<div>source = <code>{{ $r->source_type }}</code></div>@endif
                            @if($r->host_contains)<div>host contains <code>{{ $r->host_contains }}</code></div>@endif
                            @if($r->message_regex)<div>message =~ <code>{{ \Illuminate\Support\Str::limit($r->message_regex, 60) }}</code></div>@endif
                        </td>
                        <td class="small">
                            <span class="badge bg-{{ $r->event_severity === 'critical' ? 'danger' : ($r->event_severity === 'warning' ? 'warning text-dark' : 'info text-dark') }}">{{ ucfirst($r->event_severity) }}</span>
                            <span class="text-muted ms-1">{{ $r->event_module }}</span>
                        </td>
                        <td class="small">{{ $r->cooldown_minutes }}m</td>
                        <td class="small">{{ number_format($r->match_count) }}</td>
                        <td class="small text-muted">{{ $r->last_matched_at?->diffForHumans() ?? '—' }}</td>
                        <td class="pe-3 text-end">
                            <a href="{{ route('admin.syslog.rules.edit', $r) }}" class="btn btn-xs btn-outline-secondary">Edit</a>
                            <form method="POST" action="{{ route('admin.syslog.rules.destroy', $r) }}" class="d-inline" onsubmit="return confirm('Delete rule &quot;{{ $r->name }}&quot;?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-xs btn-outline-danger">Delete</button>
                            </form>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
