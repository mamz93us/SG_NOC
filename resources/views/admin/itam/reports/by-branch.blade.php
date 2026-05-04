@extends('layouts.admin')
@section('title', 'Assets by Branch')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-building me-2"></i>Assets by Branch</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.by-branch', array_merge(request()->all(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label small">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected($branchId === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100">Filter</button>
                </div>
            </form>
        </div>
    </div>

    @forelse($grouped as $branchId => $devices)
        @php($branch = $branches->firstWhere('id', $branchId))
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>{{ $branch?->name ?? 'Unassigned' }}</strong>
                <span class="badge bg-primary ms-2">{{ $devices->count() }} assets</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset Code</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Assigned To</th>
                            <th>Storage</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($devices as $d)
                            <tr>
                                <td><code>{{ $d->asset_code }}</code></td>
                                <td>{{ $d->name }}</td>
                                <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                                <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                                <td>{{ $d->currentAssignment?->employee?->name ?? '—' }}</td>
                                <td>{{ $d->storage_location ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="alert alert-info">No assets found.</div>
    @endforelse
</div>
@endsection
