@extends('layouts.admin')
@section('title', 'Transfer History')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-arrow-left-right me-2"></i>Transfer History</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.transfers', array_merge(request()->all(), ['csv' => 1])) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print</button>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small">From</label>
                    <input type="date" name="from" value="{{ request('from') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">To</label>
                    <input type="date" name="to" value="{{ request('to') }}" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Branch</label>
                    <select name="branch" class="form-select form-select-sm">
                        <option value="">All Branches</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected((int)request('branch') === $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Employee</label>
                    <select name="employee" class="form-select form-select-sm">
                        <option value="">Any Employee</option>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}" @selected((int)request('employee') === $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
                <div class="col-md-1"><a href="{{ route('admin.itam.reports.transfers') }}" class="btn btn-sm btn-outline-secondary w-100">Clear</a></div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Asset</th>
                        <th>From</th>
                        <th>To</th>
                        <th>By</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($events as $e)
                        @php
                            $m = $e->meta ?? [];
                            $sourceType = $m['source_type'] ?? ($m['from_employee_id'] ?? null ? 'employee' : null);
                            $targetType = $m['target_type'] ?? ($e->event_type === 'transferred' ? 'employee' : 'branch_store');
                            $fromLabel = match($sourceType) {
                                'employee'        => $m['from_employee'] ?? '—',
                                'branch_store'    => ($m['from_branch_name'] ?? 'Branch') . ' Store',
                                'universal_store' => 'Universal Store',
                                default           => $m['from_employee'] ?? '—',
                            };
                            $toLabel = match($targetType) {
                                'employee'        => $m['to_employee'] ?? '—',
                                'branch_store'    => ($m['to_branch_name'] ?? $m['branch_name'] ?? 'Branch') . ' Store',
                                'universal_store' => 'Universal Store',
                                default           => $m['to_employee'] ?? $m['branch_name'] ?? '—',
                            };
                        @endphp
                        <tr>
                            <td>{{ $e->created_at?->format('d M Y H:i') }}</td>
                            <td>
                                @if($e->event_type === 'transferred')
                                    <span class="badge bg-primary"><i class="bi bi-arrow-left-right me-1"></i>Transfer</span>
                                @else
                                    <span class="badge bg-info"><i class="bi bi-box-seam me-1"></i>To Storage</span>
                                @endif
                            </td>
                            <td>
                                <code>{{ $e->device?->asset_code ?? '—' }}</code>
                                <span class="text-muted small">{{ $e->device?->name }}</span>
                            </td>
                            <td>
                                {{ $fromLabel }}
                                @if(!empty($m['from_storage_location']))
                                    <small class="text-muted d-block">{{ $m['from_storage_location'] }}</small>
                                @endif
                            </td>
                            <td>
                                {{ $toLabel }}
                                @if(!empty($m['storage_location']))
                                    <small class="text-muted d-block">{{ $m['storage_location'] }}</small>
                                @endif
                            </td>
                            <td>{{ $e->user?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center py-5 text-muted">No transfer history matching your filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3 d-print-none">{{ $events->links() }}</div>
</div>
@endsection
