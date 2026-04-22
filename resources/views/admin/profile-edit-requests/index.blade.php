@extends('layouts.admin')

@section('content')
<style>
    .diff-row { font-size: 13px; }
    .diff-row .from { text-decoration: line-through; color: var(--bs-danger); }
    .diff-row .arrow { color: var(--bs-secondary-color); margin: 0 6px; }
    .diff-row .to { color: var(--bs-success); font-weight: 600; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-gear me-2 text-primary"></i>Profile Edit Requests
        </h4>
        <small class="text-muted">Review and approve employee-submitted profile changes.</small>
    </div>

    <div class="btn-group" role="group">
        <a href="?status=pending"  class="btn btn-sm {{ $status === 'pending'  ? 'btn-warning'   : 'btn-outline-warning'  }}">
            Pending <span class="badge bg-dark ms-1">{{ $counts['pending'] }}</span>
        </a>
        <a href="?status=approved" class="btn btn-sm {{ $status === 'approved' ? 'btn-success'   : 'btn-outline-success'  }}">
            Approved <span class="badge bg-dark ms-1">{{ $counts['approved'] }}</span>
        </a>
        <a href="?status=rejected" class="btn btn-sm {{ $status === 'rejected' ? 'btn-danger'    : 'btn-outline-danger'   }}">
            Rejected <span class="badge bg-dark ms-1">{{ $counts['rejected'] }}</span>
        </a>
        <a href="?status=all"      class="btn btn-sm {{ $status === 'all'      ? 'btn-secondary' : 'btn-outline-secondary' }}">All</a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show py-2">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($requests->isEmpty())
    <div class="card shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-inbox display-5 text-secondary d-block mb-2"></i>
            <h5 class="fw-semibold">No {{ $status !== 'all' ? $status : '' }} requests</h5>
            <p class="text-muted mb-0">Submitted profile edits will appear here for review.</p>
        </div>
    </div>
@else
    <div class="row g-3">
        @foreach($requests as $r)
            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                            <div>
                                <div class="d-flex align-items-center gap-2 mb-1">
                                    <strong>{{ $r->employee->name ?? $r->user->name }}</strong>
                                    <span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst($r->status) }}</span>
                                </div>
                                <div class="small text-muted">
                                    <i class="bi bi-envelope me-1"></i>{{ $r->user->email }}
                                    @if($r->employee?->branch)
                                        &middot; <i class="bi bi-geo-alt me-1"></i>{{ $r->employee->branch->name }}
                                    @endif
                                    &middot; Submitted {{ $r->created_at->diffForHumans() }}
                                </div>
                            </div>
                            @if($r->status !== 'pending')
                                <div class="small text-muted text-end">
                                    Reviewed {{ $r->reviewed_at?->diffForHumans() }}
                                    @if($r->reviewer)
                                        by {{ $r->reviewer->name }}
                                    @endif
                                </div>
                            @endif
                        </div>

                        <div class="bg-light rounded p-3 mb-3" style="background: var(--bs-tertiary-bg) !important;">
                            <div class="small fw-semibold text-muted mb-2">REQUESTED CHANGES</div>
                            @foreach(($r->requested_changes ?? []) as $field => $change)
                                <div class="diff-row mb-1">
                                    <strong>{{ ucwords(str_replace('_', ' ', $field)) }}:</strong>
                                    <span class="from">{{ $change['from'] ?? '—' }}</span>
                                    <span class="arrow">&rarr;</span>
                                    <span class="to">{{ $change['to'] }}</span>
                                </div>
                            @endforeach
                            @if($r->note)
                                <div class="mt-2 small"><strong>User note:</strong> <em>{{ $r->note }}</em></div>
                            @endif
                        </div>

                        @if($r->reviewer_note)
                            <div class="small mb-3">
                                <strong>Reviewer note:</strong> {{ $r->reviewer_note }}
                            </div>
                        @endif

                        @if($r->status === 'pending')
                            <div class="d-flex gap-2 flex-wrap">
                                {{-- Approve --}}
                                <form method="POST" action="{{ route('admin.profile-edit-requests.approve', $r->id) }}" class="d-inline">
                                    @csrf
                                    <input type="hidden" name="reviewer_note" value="">
                                    <button type="submit" class="btn btn-success btn-sm"
                                            onclick="return confirm('Approve and apply these changes to the employee record?')">
                                        <i class="bi bi-check-lg me-1"></i>Approve &amp; Apply
                                    </button>
                                </form>

                                {{-- Reject (with note prompt) --}}
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        data-bs-toggle="collapse" data-bs-target="#reject-{{ $r->id }}">
                                    <i class="bi bi-x-lg me-1"></i>Reject
                                </button>
                            </div>

                            <div class="collapse mt-3" id="reject-{{ $r->id }}">
                                <form method="POST" action="{{ route('admin.profile-edit-requests.reject', $r->id) }}">
                                    @csrf
                                    <div class="input-group">
                                        <input type="text" name="reviewer_note" class="form-control form-control-sm"
                                               placeholder="Reason for rejection (optional)" maxlength="1000">
                                        <button type="submit" class="btn btn-danger btn-sm">Confirm Reject</button>
                                    </div>
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-3">
        {{ $requests->links() }}
    </div>
@endif
@endsection
