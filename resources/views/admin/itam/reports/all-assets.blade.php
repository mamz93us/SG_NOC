@extends('layouts.admin')
@section('title', 'All Assets Report')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-grid-3x3-gap me-2"></i>All Assets Report</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.all-assets', array_merge(request()->all(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-printer me-1"></i>Print
            </button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="GET" class="row g-2">
                <div class="col-md-3"><input type="text" name="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Search code/name/serial..."></div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Any status</option>
                        @foreach(['active','available','assigned','maintenance','retired','scrapped'] as $s)
                            <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">All branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int)request('branch') === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="condition" class="form-select form-select-sm">
                        <option value="">Any condition</option>
                        @foreach(['new','used','refurbished','damaged'] as $c)
                            <option value="{{ $c }}" @selected(request('condition') === $c)>{{ ucfirst($c) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
                <div class="col-md-1"><a href="{{ route('admin.itam.reports.all-assets') }}" class="btn btn-sm btn-outline-secondary w-100">Clear</a></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Asset Code</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Branch</th>
                        <th>Assigned To</th>
                        <th>Storage</th>
                        <th>Condition</th>
                        <th>Purchase Cost</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($devices as $d)
                        <tr>
                            <td><code>{{ $d->asset_code }}</code></td>
                            <td>{{ $d->name }}</td>
                            <td><span class="badge bg-secondary">{{ $d->type }}</span></td>
                            <td><span class="badge {{ $d->statusBadgeClass() }}">{{ $d->status }}</span></td>
                            <td>{{ $d->branch?->name ?? '—' }}</td>
                            <td>{{ $d->currentAssignment?->employee?->name ?? '—' }}</td>
                            <td>{{ $d->storage_location ?? '—' }}</td>
                            <td><span class="badge {{ $d->conditionBadgeClass() }}">{{ $d->conditionLabel() }}</span></td>
                            <td class="text-end">{{ $d->purchase_cost ? number_format($d->purchase_cost, 2) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center py-5 text-muted">No assets match your filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-print-none">{{ $devices->links() }}</div>
</div>
@endsection
