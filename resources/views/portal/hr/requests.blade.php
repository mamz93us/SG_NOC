@extends('layouts.hr')

@section('title', 'My Requests')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-list-check me-2 text-primary"></i>My Requests</h4>
        <small class="text-muted">Everything you have raised from the HR portal</small>
    </div>
    <a href="{{ route('portal.hr.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>HR Portal
    </a>
</div>

<div class="card shadow-sm border-0 mb-3">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <label class="form-label small text-muted mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All types</option>
                    <option value="create_user" @selected(request('type') === 'create_user')>Onboarding</option>
                    <option value="employee_offboarding" @selected(request('type') === 'employee_offboarding')>Termination</option>
                    <option value="employee_update" @selected(request('type') === 'employee_update')>Data Change</option>
                </select>
            </div>
            <div class="col-12 col-md-4">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All statuses</option>
                    @foreach(['pending', 'awaiting_manager_form', 'manager_input_pending', 'approved', 'executing', 'completed', 'rejected', 'cancelled', 'failed'] as $s)
                        <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst(str_replace('_', ' ', $s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-4 d-flex gap-2">
                <button class="btn btn-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filter</button>
                <a href="{{ route('portal.hr.requests') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            </div>
        </form>
    </div>
</div>

@if($requests->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox display-4 text-muted"></i>
        <h5 class="mt-3 fw-semibold">No requests match</h5>
        <p class="text-muted mb-0">Try clearing the filters, or raise a new request from the HR portal.</p>
    </div>
</div>
@else
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Request</th>
                    <th>Type</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Raised</th>
                </tr>
            </thead>
            <tbody>
                @foreach($requests as $r)
                <tr>
                    <td class="text-muted small">#{{ $r->id }}</td>
                    <td>
                        <div class="fw-semibold">{{ $r->title }}</div>
                        @if($r->description)
                            <div class="text-muted small text-truncate" style="max-width: 480px;">{{ $r->description }}</div>
                        @endif
                    </td>
                    <td><span class="badge {{ $r->typeBadgeClass() }}">{{ $r->typeLabel() }}</span></td>
                    <td class="small">{{ $r->branch?->name ?? '—' }}</td>
                    <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst(str_replace('_', ' ', $r->status)) }}</span></td>
                    <td class="text-muted small">{{ $r->created_at->diffForHumans() }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $requests->links() }}</div>
@endif
@endsection
