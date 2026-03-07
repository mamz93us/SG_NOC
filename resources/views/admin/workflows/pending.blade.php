@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-clock-fill me-2 text-warning"></i>Pending Approvals</h4>
        <small class="text-muted">Workflow requests awaiting your approval</small>
    </div>
    <a href="{{ route('admin.workflows.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-list me-1"></i>All Workflows
    </a>
</div>


@if($workflows->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-check-circle-fill display-4 d-block mb-2 text-success"></i>
        <p class="mb-0">No pending approvals. You're all caught up!</p>
    </div>
</div>
@else
<div class="row g-3">
    @foreach($workflows as $wf)
    @php $step = $wf->currentStepRecord(); @endphp
    <div class="col-12 col-md-6">
        <div class="card shadow-sm border-warning border-2 h-100">
            <div class="card-header bg-warning bg-opacity-10 d-flex align-items-center gap-2 py-2">
                <span class="badge {{ $wf->typeBadgeClass() }}">{{ $wf->typeLabel() }}</span>
                <strong class="ms-1">{{ $wf->title }}</strong>
                <span class="ms-auto text-muted small">{{ $wf->created_at->diffForHumans() }}</span>
            </div>
            <div class="card-body py-3">
                <div class="row g-2 small">
                    <div class="col-6">
                        <div class="text-muted">Requested by</div>
                        <div class="fw-semibold">{{ $wf->requester?->name ?? '—' }}</div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted">Branch</div>
                        <div class="fw-semibold">{{ $wf->branch?->name ?? '—' }}</div>
                    </div>
                    @if($step)
                    <div class="col-6">
                        <div class="text-muted">Current Step</div>
                        <div class="fw-semibold">{{ $step->step_number }}/{{ $wf->total_steps }} — {{ $step->approverRoleLabel() }}</div>
                    </div>
                    @endif
                </div>
                @if($wf->description)
                <div class="mt-2 text-muted small">{{ Str::limit($wf->description, 100) }}</div>
                @endif
            </div>
            <div class="card-footer bg-transparent py-2 d-flex gap-2">
                <a href="{{ route('admin.workflows.show', $wf->id) }}" class="btn btn-sm btn-outline-primary flex-fill">
                    <i class="bi bi-eye me-1"></i>Review
                </a>
                <form method="POST" action="{{ route('admin.workflows.approve', $wf->id) }}" class="flex-fill">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success w-100">
                        <i class="bi bi-check-lg me-1"></i>Approve
                    </button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $wf->id }}">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        </div>
    </div>

    {{-- Reject modal --}}
    <div class="modal fade" id="rejectModal{{ $wf->id }}" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form method="POST" action="{{ route('admin.workflows.reject', $wf->id) }}">
                    @csrf
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Reject Request</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="small text-muted mb-2">Rejecting: <strong>{{ $wf->title }}</strong></p>
                        <label class="form-label small">Reason / Comments</label>
                        <textarea name="comments" class="form-control form-control-sm" rows="3" placeholder="Explain why this request is rejected..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-sm btn-danger"><i class="bi bi-x-lg me-1"></i>Reject</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endforeach
</div>
@endif
@endsection
