@extends('layouts.admin')

@section('title', 'Ticket ' . ($ticket->ticket_id ? '#' . $ticket->ticket_id : 'Submission'))

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-ticket-perforated me-2 text-primary"></i>
            {{ $ticket->ticket_id ? 'Ticket #' . $ticket->ticket_id : 'Ticket submission' }}
        </h4>
        <small class="text-muted">Submitted {{ $ticket->created_at?->format('d M Y H:i') }}</small>
    </div>
    <a href="{{ route('admin.tickets.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to submissions
    </a>
</div>

@if(! $ticket->isCreated())
    <div class="alert alert-danger">
        <div class="fw-semibold mb-1">
            <i class="bi bi-exclamation-octagon-fill me-1"></i>The ticketing system rejected this submission
            @if($ticket->http_status)
                <span class="badge bg-danger ms-1">HTTP {{ $ticket->http_status }}</span>
            @endif
        </div>
        <pre class="mb-0 small text-break" style="white-space:pre-wrap">{{ $ticket->error }}</pre>
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">{{ $ticket->title }}</div>
            <div class="card-body">
                <pre class="mb-0 small" style="white-space:pre-wrap">{{ $ticket->description }}</pre>
            </div>
        </div>

        @if($ticket->response)
            <div class="card shadow-sm border-0 mt-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-code-slash me-1 text-primary"></i>API response
                </div>
                <div class="card-body">
                    <pre class="mb-0 small" style="white-space:pre-wrap">{{ json_encode($ticket->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">Details</div>
            <table class="table table-sm mb-0">
                <tbody>
                    <tr>
                        <th class="text-muted fw-normal">Outcome</th>
                        <td class="text-end">
                            <span class="badge {{ $ticket->statusBadgeClass() }}">
                                {{ $ticket->isCreated() ? 'Created' : 'Failed' }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Requester</th>
                        <td class="text-end">
                            {{ $ticket->requester_name ?: '—' }}
                            <div class="small text-muted font-monospace">{{ $ticket->requester_email }}</div>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Azure id</th>
                        <td class="text-end small font-monospace text-break">{{ $ticket->requester_azure_id ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Raised by</th>
                        <td class="text-end">{{ $ticket->submitted_by_name ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Category</th>
                        <td class="text-end">
                            {{ $ticket->category_name ?: '—' }}
                            <span class="text-muted small">({{ $ticket->category_id }})</span>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Sub-category</th>
                        <td class="text-end">
                            {{ $ticket->subcategory_name ?: '—' }}
                            <span class="text-muted small">({{ $ticket->subcategory_id }})</span>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Type</th>
                        <td class="text-end">
                            {{ $ticket->type_name ?: '—' }}
                            <span class="text-muted small">({{ $ticket->type_id }})</span>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Priority</th>
                        <td class="text-end">
                            {{ $ticket->priority_name ?: '—' }}
                            <span class="text-muted small">({{ $ticket->priority_id }})</span>
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Channel</th>
                        <td class="text-end">{{ $ticket->channel_id ?: '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted fw-normal">Attachment</th>
                        <td class="text-end small">
                            @if($ticket->attachment_name)
                                {{ $ticket->attachment_name }}
                                @if($ticket->attachmentSizeLabel())
                                    <span class="text-muted">({{ $ticket->attachmentSizeLabel() }})</span>
                                @endif
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="small text-muted mt-2 px-1">
            The file itself is streamed straight to the ticketing system and is not kept on the NOC.
        </div>
    </div>
</div>
@endsection
