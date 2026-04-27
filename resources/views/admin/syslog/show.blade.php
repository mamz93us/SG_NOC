@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-journal-code me-2 text-primary"></i>Syslog Message #{{ $message->id }}</h4>
        <small class="text-muted">{{ $message->received_at->toDateTimeString() }} ({{ $message->received_at->diffForHumans() }})</small>
    </div>
    <a href="{{ route('admin.syslog.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Syslog</a>
</div>

<div class="row g-3">
    <div class="col-md-5">
        <div class="card shadow-sm border-0">
            <div class="card-header py-2"><strong>Parsed fields</strong></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <th class="w-50">Severity</th>
                            <td><span class="badge {{ $message->severityBadgeClass() }}">{{ $message->severity }} — {{ $message->severityLabel() }}</span></td>
                        </tr>
                        <tr>
                            <th>Facility</th>
                            <td>{{ $message->facility ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Source type</th>
                            <td>
                                @if($message->source_type)
                                <span class="badge {{ $message->sourceTypeBadgeClass() }}">{{ $message->source_type }}</span>
                                @if($message->source_id) <span class="text-muted small ms-1">#{{ $message->source_id }}</span>@endif
                                @else
                                <span class="text-muted">unclassified</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th>Host</th>
                            <td class="font-monospace">{{ $message->host }}</td>
                        </tr>
                        <tr>
                            <th>Source IP</th>
                            <td class="font-monospace">{{ $message->source_ip }}</td>
                        </tr>
                        <tr>
                            <th>Program</th>
                            <td>{{ $message->program ?: '—' }}</td>
                        </tr>
                        <tr>
                            <th>Device time</th>
                            <td class="text-muted small">{{ $message->device_time?->toDateTimeString() ?? '—' }}</td>
                        </tr>
                        <tr>
                            <th>Received at</th>
                            <td class="text-muted small">{{ $message->received_at->toDateTimeString() }}</td>
                        </tr>
                        <tr>
                            <th>Processed at</th>
                            <td class="text-muted small">{{ $message->processed_at?->toDateTimeString() ?? 'pending' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-3 d-flex gap-2 flex-wrap">
            <a href="{{ route('admin.syslog.index', ['host' => $message->host, 'since' => '24h']) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-funnel me-1"></i>All from {{ $message->host }}
            </a>
            @if($message->source_type)
            <a href="{{ route('admin.syslog.index', ['source_type' => $message->source_type, 'since' => '24h']) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-funnel me-1"></i>All {{ $message->source_type }}
            </a>
            @endif
        </div>
    </div>
    <div class="col-md-7">
        <div class="card shadow-sm border-0 mb-3">
            <div class="card-header py-2"><strong>Message</strong></div>
            <div class="card-body">
                <pre class="mb-0 small" style="white-space:pre-wrap;word-break:break-word">{{ $message->message }}</pre>
            </div>
        </div>

        @if($message->raw)
        <div class="card shadow-sm border-0">
            <div class="card-header py-2"><strong>Raw packet</strong></div>
            <div class="card-body bg-dark text-light">
                <pre class="mb-0 small text-light" style="white-space:pre-wrap;word-break:break-word">{{ $message->raw }}</pre>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
