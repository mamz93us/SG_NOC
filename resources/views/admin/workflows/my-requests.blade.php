@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-badge me-2 text-primary"></i>My Requests</h4>
        <small class="text-muted">Workflow requests submitted by you</small>
    </div>
    @can('manage-workflows')
    <a href="{{ route('admin.workflows.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Request
    </a>
    @endcan
</div>


@if($workflows->isEmpty())
<div class="card shadow-sm border-0">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-diagram-2-fill display-4 d-block mb-2"></i>
        <p class="mb-1">You haven't submitted any requests yet.</p>
        @can('manage-workflows')
        <a href="{{ route('admin.workflows.create') }}" class="btn btn-primary btn-sm mt-2"><i class="bi bi-plus-lg me-1"></i>Submit a Request</a>
        @endcan
    </div>
</div>
@else
<div class="row g-3">
    @foreach($workflows as $wf)
    <div class="col-12 col-md-6 col-xl-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
                <span class="badge {{ $wf->statusBadgeClass() }}">{{ ucfirst($wf->status) }}</span>
                <span class="badge {{ $wf->typeBadgeClass() }} ms-1">{{ $wf->typeLabel() }}</span>
                <span class="ms-auto text-muted small">{{ $wf->created_at->diffForHumans() }}</span>
            </div>
            <div class="card-body py-3">
                <h6 class="fw-bold mb-1">{{ $wf->title }}</h6>
                @if($wf->description)
                <p class="text-muted small mb-2">{{ Str::limit($wf->description, 80) }}</p>
                @endif
                @if($wf->branch)
                <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i>{{ $wf->branch->name }}</div>
                @endif
                @if($wf->total_steps > 0)
                <div class="mt-2">
                    <div class="progress" style="height:5px">
                        <div class="progress-bar bg-primary" style="width:{{ $wf->progressPercent() }}%"></div>
                    </div>
                    <div class="text-muted mt-1" style="font-size:.7rem">Step {{ $wf->current_step }} of {{ $wf->total_steps }}</div>
                </div>
                @endif
            </div>
            <div class="card-footer bg-transparent py-2">
                <a href="{{ route('admin.workflows.show', $wf->id) }}" class="btn btn-sm btn-outline-primary w-100">
                    <i class="bi bi-eye me-1"></i>View Details
                </a>
            </div>
        </div>
    </div>
    @endforeach
</div>
<div class="mt-3">{{ $workflows->links() }}</div>
@endif
@endsection
