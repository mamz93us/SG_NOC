@extends('layouts.admin')

@section('title', 'Ticket Submissions')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Ticket Submissions</h4>
        <small class="text-muted">
            Tickets raised from the NOC. The ticketing system owns their live status — this is the submit log.
        </small>
    </div>
    @can('create-tickets')
        <a href="{{ route('admin.tickets.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Create Ticket
        </a>
    @endcan
</div>

@if (session('success'))
    <div class="alert alert-success d-flex gap-2 align-items-start">
        <i class="bi bi-check-circle-fill fs-5"></i>
        <div>{{ session('success') }}</div>
    </div>
@endif

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-center">
            <div class="col-md-6">
                <input type="search" name="q" value="{{ $q }}" class="form-control form-control-sm"
                       placeholder="Search title, requester email or ticket number…">
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All outcomes</option>
                    <option value="created" @selected($status === 'created')>Created</option>
                    <option value="failed" @selected($status === 'failed')>Failed</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-outline-primary flex-grow-1">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                @if($q || $status)
                    <a href="{{ route('admin.tickets.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
                @endif
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:110px">Ticket</th>
                    <th>Title</th>
                    <th style="min-width:180px">Requester</th>
                    <th style="min-width:150px">Category</th>
                    <th style="width:110px">Priority</th>
                    <th style="min-width:150px">Raised by</th>
                    <th style="width:150px">When</th>
                    <th style="width:100px" class="text-center">Result</th>
                </tr>
            </thead>
            <tbody>
                @forelse($tickets as $ticket)
                    <tr>
                        <td class="font-monospace">
                            <a href="{{ route('admin.tickets.show', $ticket) }}">
                                {{ $ticket->ticket_id ? '#' . $ticket->ticket_id : '—' }}
                            </a>
                        </td>
                        <td>
                            <div class="fw-semibold text-truncate" style="max-width:320px">{{ $ticket->title }}</div>
                            @if($ticket->attachment_name)
                                <div class="small text-muted">
                                    <i class="bi bi-paperclip"></i>
                                    {{ $ticket->attachment_name }}
                                    @if($ticket->attachmentSizeLabel())
                                        <span class="text-body-tertiary">({{ $ticket->attachmentSizeLabel() }})</span>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td>
                            <div>{{ $ticket->requester_name ?: '—' }}</div>
                            <div class="small text-muted font-monospace">{{ $ticket->requester_email }}</div>
                        </td>
                        <td class="small">
                            {{ $ticket->category_name ?: $ticket->category_id }}
                            @if($ticket->subcategory_name || $ticket->subcategory_id)
                                <div class="text-muted">{{ $ticket->subcategory_name ?: $ticket->subcategory_id }}</div>
                            @endif
                        </td>
                        <td class="small">{{ $ticket->priority_name ?: $ticket->priority_id }}</td>
                        <td class="small">{{ $ticket->submitted_by_name ?: '—' }}</td>
                        <td class="small text-muted" title="{{ $ticket->created_at }}">
                            {{ $ticket->created_at?->diffForHumans() }}
                        </td>
                        <td class="text-center">
                            <span class="badge {{ $ticket->statusBadgeClass() }}">
                                {{ $ticket->isCreated() ? 'Created' : 'Failed' }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-3 d-block mb-2"></i>
                            No tickets have been raised from the NOC yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

@if($tickets->hasPages())
    <div class="mt-3">{{ $tickets->links() }}</div>
@endif
@endsection
