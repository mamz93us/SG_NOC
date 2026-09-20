@extends('layouts.admin')
@section('title', 'Asset Scrap Requests')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-trash3 me-2"></i>Asset Scrap Requests</h4>
        <div class="d-flex gap-2">
            @can('request-scrap')
                <a href="{{ route('admin.itam.scrap.create') }}" class="btn btn-sm btn-danger">
                    <i class="bi bi-plus-circle me-1"></i>New Scrap Request
                </a>
            @endcan
            <a href="{{ route('admin.itam.dashboard') }}" class="btn btn-sm btn-outline-secondary">ITAM Dashboard</a>
        </div>
    </div>

    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
    @if(session('warning'))<div class="alert alert-warning">{{ session('warning') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    <div class="row g-3 mb-4">
        @foreach(['pending' => 'warning', 'approved' => 'success', 'rejected' => 'secondary', 'completed' => 'success'] as $st => $color)
            <div class="col-6 col-md-3">
                <a href="?status={{ $st }}" class="text-decoration-none">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body py-3 text-center">
                            <div class="display-6 fw-bold text-{{ $color }}">{{ $statusCounts[$st] ?? 0 }}</div>
                            <div class="small text-muted text-uppercase">{{ $st }}</div>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>

    <form method="POST" action="{{ route('admin.itam.scrap.bulk-approve') }}" id="bulkApproveForm">
        @csrf
        @if($waitingForMe > 0)
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#bulkApproveModal" data-bulk-open disabled>
                    <i class="bi bi-check2-all me-1"></i>Approve selected (<span data-bulk-count>0</span>)
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bulk-all>
                    Select the {{ $waitingForMe }} waiting for you
                </button>
                <span class="text-muted small">Approving the last step scraps the assets in the request.</span>
            </div>
        @endif

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        @if($waitingForMe > 0)
                            <th style="width:2.5rem"><input type="checkbox" class="form-check-input" data-bulk-toggle title="Select every request on this page waiting for you"></th>
                        @endif
                        <th>#</th>
                        <th>Title</th>
                        <th>Requested By</th>
                        <th>Status</th>
                        <th>Step</th>
                        <th>Date</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($requests as $r)
                        <tr>
                            @if($waitingForMe > 0)
                                <td>
                                    @if($awaitingMe[$r->id] ?? false)
                                        <input type="checkbox" class="form-check-input" name="ids[]" value="{{ $r->id }}" data-bulk-item>
                                    @endif
                                </td>
                            @endif
                            <td>#{{ $r->id }}</td>
                            <td>{{ $r->title }}</td>
                            <td>{{ $r->requester?->name ?? '—' }}</td>
                            <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst($r->status) }}</span></td>
                            <td>
                                <span class="text-muted small">{{ $r->current_step }}/{{ $r->total_steps }}</span>
                                @if($awaitingMe[$r->id] ?? false)
                                    <span class="badge bg-warning text-dark">waiting for you</span>
                                @endif
                            </td>
                            <td>{{ $r->created_at->format('d M Y') }}</td>
                            <td>
                                <a href="{{ route('admin.itam.scrap.show', $r->id) }}" class="btn btn-sm btn-outline-primary">View</a>
                                @if($r->status === 'approved')
                                    <a href="{{ route('admin.itam.scrap.print', $r->id) }}" target="_blank" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ $waitingForMe > 0 ? 8 : 7 }}" class="text-center py-5 text-muted">No scrap requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    </form>
    <div class="mt-3">{{ $requests->links() }}</div>
</div>

@if($waitingForMe > 0)
    {{-- Outside the form on purpose: the inputs join it by its id, so nothing depends on where the modal sits in the DOM. --}}
    <div class="modal fade" id="bulkApproveModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check2-all me-2"></i>Approve scrap requests</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2">Approving <strong><span data-bulk-count>0</span></strong> request(s) as your step.</p>
                    <p class="small text-muted">
                        A request on its last step is scrapped straight away: its assets are marked scrapped and any
                        assignment is closed. One on an earlier step moves to the next approver.
                    </p>
                    <label class="form-label small fw-semibold" for="bulkApproveComments">Comment <span class="text-muted fw-normal">(optional, recorded on every step you approve)</span></label>
                    <textarea class="form-control form-control-sm" id="bulkApproveComments" name="comments" form="bulkApproveForm" rows="2" maxlength="1000"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="bulkApproveForm" class="btn btn-sm btn-success"><i class="bi bi-check2-all me-1"></i>Approve</button>
                </div>
            </div>
        </div>
    </div>
    <script>
    (function () {
        const form = document.getElementById('bulkApproveForm');
        if (!form) return;
        const items = Array.from(form.querySelectorAll('[data-bulk-item]'));
        const toggle = form.querySelector('[data-bulk-toggle]');
        const open = form.querySelector('[data-bulk-open]');
        const all = form.querySelector('[data-bulk-all]');
        const counts = document.querySelectorAll('[data-bulk-count]');

        function refresh() {
            const n = items.filter(i => i.checked).length;
            counts.forEach(c => c.textContent = n);
            if (open) open.disabled = n === 0;
            if (toggle) toggle.checked = n > 0 && n === items.length;
        }

        items.forEach(i => i.addEventListener('change', refresh));
        if (toggle) toggle.addEventListener('change', () => { items.forEach(i => i.checked = toggle.checked); refresh(); });
        if (all) all.addEventListener('click', () => { items.forEach(i => i.checked = true); refresh(); });
        refresh();
    })();
    </script>
@endif
@endsection
