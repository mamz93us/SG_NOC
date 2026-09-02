@extends('layouts.admin')

@section('title', 'Mail Delivery — Raw Events')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-0"><i class="bi bi-list-ul me-2 text-primary"></i>Raw SES Events</h4>
            <small class="text-muted">
                Every notification SES published, newest first. One message produces
                several rows — use the message log unless you are chasing deliverability.
            </small>
        </div>
        <a href="{{ route('admin.mail-delivery.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-envelope me-1"></i>Message log
        </a>
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Search recipient or subject</label>
                    <input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Event type</label>
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All types</option>
                        @foreach(['Send', 'Delivery', 'Open', 'Click', 'Bounce', 'Complaint', 'Reject', 'RenderingFailure'] as $type)
                            <option value="{{ $type }}" @selected(request('type') === $type)>{{ $type }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-sm btn-primary">Filter</button>
                    <a href="{{ route('admin.mail-delivery.events') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
                    <span class="ms-auto small text-muted align-self-center">
                        {{ number_format($events->total()) }} events
                    </span>
                </div>
            </div>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:160px;">When</th>
                            <th style="width:120px;">Event</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Detail</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($events as $event)
                        @php $msg = $messages[$event->ses_message_id] ?? null; @endphp
                        <tr>
                            <td class="small text-muted text-nowrap">{{ $event->created_at?->format('Y-m-d H:i:s') }}</td>
                            <td><span class="badge bg-secondary">{{ $event->event_type }}</span></td>
                            <td class="small">{{ $msg?->to_email ?? '—' }}</td>
                            <td class="small">
                                @if($msg)
                                    <a href="{{ route('admin.mail-delivery.show', $msg) }}"
                                       class="text-decoration-none d-inline-block text-truncate"
                                       style="max-width:320px" title="{{ $msg->subject }}">
                                        {{ $msg->subject ?: '(no subject)' }}
                                    </a>
                                @else
                                    <span class="text-muted">unknown message</span>
                                @endif
                            </td>
                            <td class="small text-muted">
                                @if($event->bounce_type){{ $event->bounce_type }}@endif
                                @if($event->complaint_type){{ $event->complaint_type }}@endif
                                @if($event->url)
                                    <span class="d-inline-block text-truncate" style="max-width:240px" title="{{ $event->url }}">{{ $event->url }}</span>
                                @endif
                                @if($event->country_name){{ $event->country_name }}@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center text-muted py-4">No events match.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $events->links() }}</div>
</div>
@endsection
