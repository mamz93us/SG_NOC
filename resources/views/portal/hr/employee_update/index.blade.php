@extends('layouts.hr')

@section('title', 'Employee Data Changes')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-pencil-square me-2 text-info"></i>Employee Data Changes
        </h4>
        <small class="text-muted">Request a correction to an employee's record — IT applies it once approved</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('portal.hr.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>HR Portal
        </a>
        <a href="{{ route('portal.hr.employee-update.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-circle me-1"></i>New Change Request
        </a>
    </div>
</div>

@if($requests->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox display-4 text-muted"></i>
        <h5 class="mt-3 fw-semibold">No change requests yet</h5>
        <p class="text-muted mb-3">Raise one to correct a job title, department, branch, manager or phone.</p>
        <a href="{{ route('portal.hr.employee-update.create') }}" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>New Change Request
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
                    <th>Requested changes</th>
                    <th>Status</th>
                    <th>Raised</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requests as $r)
                @php
                    $p       = $r->payload ?? [];
                    $changes = $p['changes'] ?? [];
                @endphp
                <tr>
                    <td class="text-muted small">#{{ $r->id }}</td>
                    <td>
                        <div class="fw-semibold">{{ $p['employee_name'] ?? '—' }}</div>
                        <div class="text-muted small">{{ $p['employee_email'] ?? '' }}</div>
                    </td>
                    <td class="small">
                        @forelse($changes as $c)
                            <div>
                                <span class="fw-semibold">{{ $c['label'] ?? $c['field'] }}:</span>
                                <span class="text-muted text-decoration-line-through">{{ $c['from_label'] ?? '—' }}</span>
                                <i class="bi bi-arrow-right mx-1"></i>
                                <span>{{ $c['to_label'] ?? '—' }}</span>
                            </div>
                        @empty
                            <span class="text-muted">—</span>
                        @endforelse
                    </td>
                    <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
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
