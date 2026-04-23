@extends('layouts.portal')

@section('title', 'HR Onboarding')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-plus-fill me-2 text-primary"></i>HR Onboarding Requests
        </h4>
        <small class="text-muted">Submit and track onboarding requests for new hires</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('portal.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Portal
        </a>
        <a href="{{ route('portal.hr.onboarding.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>New Request
        </a>
    </div>
</div>

@if($requests->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox display-4 text-muted"></i>
        <h5 class="mt-3 fw-semibold">No onboarding requests yet</h5>
        <p class="text-muted mb-3">Submit your first new-hire request to get started.</p>
        <a href="{{ route('portal.hr.onboarding.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>New Onboarding Request
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
                    <th>New Hire</th>
                    <th>Manager</th>
                    <th>Branch</th>
                    <th>Start Date</th>
                    <th>Status</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requests as $r)
                @php
                    $p = $r->payload ?? [];
                    $newHire = trim(($p['first_name'] ?? '') . ' ' . ($p['last_name'] ?? ''));
                    $startDate = $p['suggested_start_date'] ?? $p['start_date'] ?? null;
                @endphp
                <tr>
                    <td class="text-muted small">#{{ $r->id }}</td>
                    <td>
                        <div class="fw-semibold">{{ $newHire ?: '—' }}</div>
                        <div class="text-muted small">{{ $p['job_title'] ?? '' }}</div>
                    </td>
                    <td class="small">
                        {{ $p['manager_name'] ?? '—' }}
                        <div class="text-muted">{{ $p['manager_email'] ?? '' }}</div>
                    </td>
                    <td class="small">{{ $r->branch?->name ?? '—' }}</td>
                    <td class="small">
                        {{ $startDate ? \Carbon\Carbon::parse($startDate)->format('M j, Y') : '—' }}
                    </td>
                    <td>
                        <span class="badge {{ $r->statusBadgeClass() }}">
                            {{ ucfirst(str_replace('_', ' ', $r->status)) }}
                        </span>
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
