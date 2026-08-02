@extends('layouts.hr')

@section('title', 'Terminations')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-dash-fill me-2 text-danger"></i>Termination Requests
        </h4>
        <small class="text-muted">Start and track the leaver process for departing employees</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('portal.hr.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>HR Portal
        </a>
        <a href="{{ route('portal.hr.offboarding.create') }}" class="btn btn-danger btn-sm">
            <i class="bi bi-plus-circle me-1"></i>New Termination
        </a>
    </div>
</div>

@if($requests->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox display-4 text-muted"></i>
        <h5 class="mt-3 fw-semibold">No termination requests yet</h5>
        <p class="text-muted mb-3">Raise one when an employee resigns or is leaving.</p>
        <a href="{{ route('portal.hr.offboarding.create') }}" class="btn btn-danger">
            <i class="bi bi-plus-circle me-1"></i>New Termination Request
        </a>
    </div>
</div>
@else
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Employee</th>
                    <th>Manager</th>
                    <th>Branch</th>
                    <th>Last Day</th>
                    <th>Status</th>
                    <th>Raised</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requests as $r)
                @php
                    $p     = $r->payload ?? [];
                    $state = $states[$r->id] ?? null;
                @endphp
                <tr>
                    <td class="text-muted small">#{{ $r->id }}</td>
                    <td>
                        <div class="fw-semibold">{{ $p['employee_name'] ?? '—' }}</div>
                        <div class="text-muted small">{{ $p['upn'] ?? '' }}</div>
                    </td>
                    <td class="small">
                        {{ $p['manager_name'] ?? '—' }}
                        <div class="text-muted">{{ $p['manager_email'] ?? '' }}</div>
                    </td>
                    <td class="small">{{ $r->branch?->name ?? '—' }}</td>
                    <td class="small">
                        {{ isset($p['last_day']) ? \Carbon\Carbon::parse($p['last_day'])->format('M j, Y') : '—' }}
                    </td>
                    <td>
                        <span class="badge {{ $r->statusBadgeClass() }}">
                            {{ ucfirst(str_replace('_', ' ', $r->status)) }}
                        </span>
                        @if($state)
                            <div class="text-muted small mt-1">{{ ucfirst(str_replace('_', ' ', $state->status)) }}</div>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $r->created_at->diffForHumans() }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<div class="text-muted small mt-2">
    Showing the most recent {{ $requests->count() }} requests.
</div>
@endif
@endsection
