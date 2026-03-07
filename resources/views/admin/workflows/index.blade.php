@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-2-fill me-2 text-primary"></i>All Workflows</h4>
        <small class="text-muted">Complete workflow request history</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.workflows.pending') }}" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-clock-fill me-1"></i>Pending Approvals
        </a>
        <a href="{{ route('admin.workflows.my-requests') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-person me-1"></i>My Requests
        </a>
        @can('manage-workflows')
        <a href="{{ route('admin.workflows.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Request
        </a>
        @endcan
    </div>
</div>


{{-- Filters --}}
<form method="GET" class="mb-3 d-flex gap-2 flex-wrap">
    <select name="status" class="form-select form-select-sm" style="max-width:150px" onchange="this.form.submit()">
        <option value="">All Status</option>
        @foreach(['draft','pending','approved','rejected','executing','completed','failed'] as $s)
        <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
        @endforeach
    </select>
    <select name="type" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
        <option value="">All Types</option>
        @foreach(['create_user'=>'Create User','delete_user'=>'Delete User','license_change'=>'License Change','asset_assign'=>'Asset Assignment','asset_return'=>'Asset Return','extension_create'=>'Create Extension','extension_delete'=>'Delete Extension','other'=>'Other'] as $val => $label)
        <option value="{{ $val }}" {{ request('type') === $val ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    @if(request()->anyFilled(['status','type']))
    <a href="{{ route('admin.workflows.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
    @endif
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($workflows->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-diagram-2-fill display-4 d-block mb-2"></i>
            No workflows found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">#</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Requested By</th>
                        <th>Branch</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Created</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($workflows as $wf)
                    <tr>
                        <td class="ps-3 text-muted">{{ $wf->id }}</td>
                        <td class="fw-semibold">{{ $wf->title }}</td>
                        <td><span class="badge {{ $wf->typeBadgeClass() }}">{{ $wf->typeLabel() }}</span></td>
                        <td>{{ $wf->requester?->name ?? '—' }}</td>
                        <td>{{ $wf->branch?->name ?? '—' }}</td>
                        <td><span class="badge {{ $wf->statusBadgeClass() }}">{{ ucfirst($wf->status) }}</span></td>
                        <td>
                            @if($wf->total_steps > 0)
                            <div class="progress" style="height:6px;min-width:60px">
                                <div class="progress-bar bg-primary" style="width:{{ $wf->progressPercent() }}%"></div>
                            </div>
                            <div class="text-muted" style="font-size:.7rem">{{ $wf->current_step }}/{{ $wf->total_steps }}</div>
                            @endif
                        </td>
                        <td class="text-muted">{{ $wf->created_at->diffForHumans() }}</td>
                        <td class="pe-3">
                            <a href="{{ route('admin.workflows.show', $wf->id) }}" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top d-flex justify-content-between align-items-center flex-wrap gap-2">
            <small class="text-muted">{{ $workflows->total() }} total requests</small>
            {{ $workflows->withQueryString()->links() }}
        </div>
        @endif
    </div>
</div>
@endsection
