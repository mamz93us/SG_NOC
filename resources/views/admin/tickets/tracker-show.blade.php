@extends('layouts.admin')

@section('title', 'Ticket #' . $ticket['id'])

@php
    use App\Services\Ticketing\TicketStatus;
    $fmt = fn (?string $d) => $d ? \Illuminate\Support\Str::of($d)->replace('T', ' ')->limit(16, '') : '—';
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-1 fw-bold">
            <span class="font-monospace text-muted">#{{ $ticket['id'] }}</span>
            {{ $ticket['title'] }}
        </h4>
        <span class="badge bg-{{ TicketStatus::colour($ticket['status_id']) }}">{{ $ticket['status_name'] ?? '—' }}</span>
        @if($ticket['priority_name'])
            <span class="badge bg-light text-dark border">{{ $ticket['priority_name'] }} priority</span>
        @endif
        @if($ticket['type_name'])
            <span class="badge bg-light text-dark border">{{ $ticket['type_name'] }}</span>
        @endif
    </div>
    <a href="{{ $backUrl }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-card-text me-1 text-primary"></i>Description
            </div>
            <div class="card-body">
                <p class="mb-0" style="white-space: pre-wrap">{{ $ticket['description'] ?: '—' }}</p>

                @if($ticket['assigned_task_desc'])
                    <hr>
                    <div class="small text-muted text-uppercase fw-semibold mb-1">Engineer note</div>
                    <p class="mb-0" style="white-space: pre-wrap">{{ $ticket['assigned_task_desc'] }}</p>
                @endif
            </div>
        </div>

        @if($ticket['attachments'])
            <div class="card shadow-sm border-0 mb-4">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-paperclip me-1 text-primary"></i>Attachments
                    <span class="text-muted fw-normal">({{ count($ticket['attachments']) }})</span>
                </div>
                <ul class="list-group list-group-flush">
                    @foreach($ticket['attachments'] as $a)
                        <li class="list-group-item d-flex align-items-center gap-2">
                            <i class="bi bi-file-earmark"></i>
                            <span>{{ $a['name'] }}</span>
                            @if($a['type'])
                                <span class="badge bg-light text-dark border ms-auto">{{ $a['type'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                {{-- The API returns an attachmentApiId but publishes no endpoint
                     to fetch the bytes, so these are listed, not linked. --}}
                <div class="card-footer bg-white small text-muted">
                    Files live in the ticketing system — open the ticket there to download them.
                </div>
            </div>
        @endif

        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-clock-history me-1 text-primary"></i>History
            </div>
            <div class="card-body">
                @forelse($ticket['timeline'] as $step)
                    <div class="d-flex gap-3 pb-3 mb-3 {{ ! $loop->last ? 'border-bottom' : '' }}">
                        <div class="text-primary"><i class="bi bi-record-circle"></i></div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between flex-wrap gap-2">
                                <span class="fw-semibold">{{ $step['status_name'] ?? 'Update' }}</span>
                                <span class="small text-muted">{{ $fmt($step['created_at']) }}</span>
                            </div>
                            @if($step['comments'])
                                <div class="mt-1" style="white-space: pre-wrap">{{ $step['comments'] }}</div>
                            @endif
                            @if($step['attachment_name'])
                                <div class="small text-muted mt-1">
                                    <i class="bi bi-paperclip me-1"></i>{{ $step['attachment_name'] }}
                                </div>
                            @endif
                            <div class="small text-muted mt-1">
                                @if($step['created_by']) by {{ $step['created_by'] }} @endif
                                @if($step['assigned_to']) &middot; assigned to {{ $step['assigned_to'] }} @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="text-muted mb-0">Nothing has been logged on this ticket yet.</p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1 text-primary"></i>Details
            </div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted fw-normal">Category</dt>
                    <dd class="col-7">{{ $ticket['category_name'] ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Sub-category</dt>
                    <dd class="col-7">{{ $ticket['subcategory_name'] ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Raised by</dt>
                    <dd class="col-7 text-break">{{ $ticket['created_by'] ?? '—' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Raised</dt>
                    <dd class="col-7">{{ $fmt($ticket['created_at']) }}</dd>

                    <dt class="col-5 text-muted fw-normal">Last update</dt>
                    <dd class="col-7">{{ $fmt($ticket['updated_at']) }}</dd>

                    <dt class="col-5 text-muted fw-normal">Assigned to</dt>
                    <dd class="col-7 text-break">{{ $ticket['engineer_name'] ?? 'Not yet assigned' }}</dd>

                    <dt class="col-5 text-muted fw-normal">Department</dt>
                    <dd class="col-7">{{ $ticket['department_name'] ?? '—' }}</dd>

                    @if($ticket['estimated_finish_at'])
                        <dt class="col-5 text-muted fw-normal">Target</dt>
                        <dd class="col-7">{{ $fmt($ticket['estimated_finish_at']) }}</dd>
                    @endif

                    @if($ticket['sla_hours'])
                        <dt class="col-5 text-muted fw-normal">SLA</dt>
                        <dd class="col-7">{{ $ticket['sla_hours'] }} hours</dd>
                    @endif

                    @if($ticket['completed_at'])
                        <dt class="col-5 text-muted fw-normal">Completed</dt>
                        <dd class="col-7">{{ $fmt($ticket['completed_at']) }}</dd>
                    @endif

                    @if($ticket['closed_at'])
                        <dt class="col-5 text-muted fw-normal">Closed</dt>
                        <dd class="col-7">{{ $fmt($ticket['closed_at']) }}</dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
