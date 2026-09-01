@extends('layouts.admin')

@section('title', 'My Tickets')

@php
    use App\Services\Ticketing\TicketStatus;
    $labels = TicketStatus::labels();
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-life-preserver me-2 text-primary"></i>My Tickets</h4>
        <small class="text-muted">
            Live from the IT ticketing system
            @if($canViewOthers && $email)
                &mdash; showing <span class="font-monospace">{{ $email }}</span>
            @endif
        </small>
    </div>
    <div class="d-flex gap-2">
        @can('create-tickets')
            <a href="{{ route('admin.tickets.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Raise a Ticket
            </a>
        @endcan
        @can('view-tickets')
            <a href="{{ route('admin.tickets.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i>Submission Log
            </a>
        @endcan
    </div>
</div>

@if($error)
    <div class="alert alert-warning d-flex gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div class="small">
            The ticketing system could not be reached, so this list may be incomplete.
            <div class="font-monospace mt-1">{{ $error }}</div>
        </div>
    </div>
@endif

{{-- Summary: the two numbers the page exists to answer, then a breakdown. --}}
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">Still open</div>
                <div class="display-6 fw-bold text-primary">{{ $summary['live'] }}</div>
                <div class="small text-muted">Open, in progress, or waiting on you</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold">All time</div>
                <div class="display-6 fw-bold">{{ $summary['total'] }}</div>
                <div class="small text-muted">Tickets raised in total</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-6">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body">
                <div class="text-muted small text-uppercase fw-semibold mb-2">By status</div>
                <div class="d-flex flex-wrap gap-2">
                    @forelse($labels as $id => $label)
                        @php $count = $summary['by_status'][$id] ?? 0; @endphp
                        <a href="{{ route('admin.tickets.tracker', array_filter(['email' => $canViewOthers ? $email : null, 'status' => $id])) }}"
                           class="btn btn-sm {{ $status === $id ? 'btn-' . TicketStatus::colour($id) : 'btn-outline-' . TicketStatus::colour($id) }} {{ $count === 0 ? 'opacity-50' : '' }}">
                            {{ $label }} <span class="badge bg-light text-dark ms-1">{{ $count }}</span>
                        </a>
                    @empty
                        <span class="text-muted small">No data.</span>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white d-flex flex-wrap gap-2 align-items-center">
        <span class="fw-semibold">
            {{ $status === TicketStatus::ALL ? 'All tickets' : ($labels[$status] ?? 'Tickets') }}
            <span class="text-muted fw-normal">({{ count($tickets) }})</span>
        </span>

        <form method="GET" action="{{ route('admin.tickets.tracker') }}" class="ms-auto d-flex gap-2 flex-wrap">
            @if($canViewOthers)
                <input type="email" name="email" list="trackerDirectory" value="{{ $email }}"
                       class="form-control form-control-sm" style="min-width: 260px"
                       placeholder="name@samirgroup.com" autocomplete="off">
                <datalist id="trackerDirectory">
                    @foreach($directory as $person)
                        @php $addr = $person->mail ?: $person->user_principal_name; @endphp
                        @if($addr)
                            <option value="{{ $addr }}">{{ $person->display_name }}{{ $person->department ? ' — ' . $person->department : '' }}</option>
                        @endif
                    @endforeach
                </datalist>
            @endif
            <select name="status" class="form-select form-select-sm" style="width: auto">
                <option value="{{ TicketStatus::ALL }}" @selected($status === TicketStatus::ALL)>All statuses</option>
                <option value="{{ TicketStatus::OPEN_AND_IN_PROGRESS }}" @selected($status === TicketStatus::OPEN_AND_IN_PROGRESS)>Open + In Progress</option>
                @foreach($labels as $id => $label)
                    <option value="{{ $id }}" @selected($status === $id)>{{ $label }}</option>
                @endforeach
            </select>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-search"></i></button>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 90px">Ticket</th>
                    <th>Title</th>
                    <th class="d-none d-lg-table-cell">Category</th>
                    <th style="width: 150px">Status</th>
                    <th class="d-none d-md-table-cell" style="width: 110px">Priority</th>
                    <th class="d-none d-md-table-cell" style="width: 130px">Raised</th>
                    <th style="width: 60px"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($tickets as $t)
                    <tr>
                        <td class="font-monospace">#{{ $t['id'] }}</td>
                        <td>
                            <div class="fw-semibold">{{ $t['title'] }}</div>
                            @if($t['engineer_name'])
                                <div class="small text-muted"><i class="bi bi-person-gear me-1"></i>{{ $t['engineer_name'] }}</div>
                            @endif
                        </td>
                        <td class="d-none d-lg-table-cell small">
                            {{ $t['category_name'] ?? '—' }}
                            @if($t['subcategory_name'])
                                <div class="text-muted">{{ $t['subcategory_name'] }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ TicketStatus::colour($t['status_id']) }}">
                                {{ $t['status_name'] ?? '—' }}
                            </span>
                        </td>
                        <td class="d-none d-md-table-cell small">{{ $t['priority_name'] ?? '—' }}</td>
                        <td class="d-none d-md-table-cell small text-muted">
                            {{ $t['created_at'] ? \Illuminate\Support\Str::of($t['created_at'])->replace('T', ' ')->limit(16, '') : '—' }}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('admin.tickets.tracker.show', array_merge(['ticket' => $t['id']], array_filter(['email' => $canViewOthers ? $email : null, 'status' => $status]))) }}"
                               class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-inbox fs-2 d-block mb-2 opacity-50"></i>
                            @if($error)
                                Nothing could be loaded.
                            @elseif($status === TicketStatus::ALL)
                                No tickets on record for this address.
                            @else
                                No tickets with this status.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
