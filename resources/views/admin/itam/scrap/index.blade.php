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

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
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
                            <td>#{{ $r->id }}</td>
                            <td>{{ $r->title }}</td>
                            <td>{{ $r->requester?->name ?? '—' }}</td>
                            <td><span class="badge {{ $r->statusBadgeClass() }}">{{ ucfirst($r->status) }}</span></td>
                            <td><span class="text-muted small">{{ $r->current_step }}/{{ $r->total_steps }}</span></td>
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
                        <tr><td colspan="7" class="text-center py-5 text-muted">No scrap requests yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $requests->links() }}</div>
</div>
@endsection
