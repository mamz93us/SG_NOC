@php
    // Browser-users get the slim portal layout; everyone else sees the full admin chrome.
    $layout = auth()->user()?->isBrowserUser() ? 'layouts.portal' : 'layouts.admin';
    $labels = \App\Models\BrowserSessionEvent::eventTypeLabels();
@endphp
@extends($layout)

@section('title', 'Remote Browser — My History')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-clock-history me-2"></i>My Remote Browser History</h3>
        <a href="{{ route('admin.browser-portal.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card shadow-sm">
                <div class="card-header"><strong>Recent sessions</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Session</th>
                                <th>Status</th>
                                <th>Started</th>
                                <th>Stopped</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($sessions as $s)
                            <tr>
                                <td><code>{{ $s->session_id }}</code></td>
                                <td>
                                    <span class="badge bg-{{ $s->status === 'running' ? 'success' : ($s->status === 'starting' ? 'warning' : ($s->status === 'error' ? 'danger' : 'secondary')) }}">
                                        {{ $s->status }}
                                    </span>
                                </td>
                                <td><small>{{ $s->created_at?->diffForHumans() }}</small></td>
                                <td><small>{{ $s->stopped_at?->diffForHumans() ?? '—' }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No past sessions yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-header"><strong>Recent events</strong></div>
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>When</th>
                                <th>Event</th>
                                <th>Session</th>
                                <th>Message</th>
                            </tr>
                        </thead>
                        <tbody>
                        @forelse ($events as $e)
                            <tr>
                                <td><small>{{ $e->created_at?->format('Y-m-d H:i') }}</small></td>
                                <td><span class="badge bg-secondary">{{ $labels[$e->event_type] ?? $e->event_type }}</span></td>
                                <td>
                                    @if ($e->session_id)<code>{{ $e->session_id }}</code>@else<span class="text-muted">—</span>@endif
                                </td>
                                <td><small>{{ $e->message ?? '' }}</small></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No events yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
