@extends('layouts.admin')
@section('title', 'Assets by Employee')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-person-badge me-2"></i>Assets by Employee</h4>
        <div class="d-flex gap-2">
            @if($employee)
                <a href="{{ route('admin.itam.reports.by-employee', ['employee' => $employee->id, 'csv' => 1]) }}" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-filetype-csv me-1"></i>Export CSV
                </a>
                <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer me-1"></i>Print</button>
            @endif
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-3 d-print-none">
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label small">Employee</label>
                    <select name="employee" class="form-select form-select-sm">
                        <option value="">— Select employee —</option>
                        @foreach($employees as $e)
                            <option value="{{ $e->id }}" @selected($employee?->id === $e->id)>{{ $e->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100">View</button>
                </div>
            </form>
        </div>
    </div>

    @if($employee)
        <h5 class="mb-3">{{ $employee->name }}</h5>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white">
                <strong>Currently Assigned</strong>
                <span class="badge bg-success ms-2">{{ $current->count() }}</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Asset Code</th><th>Name</th><th>Branch</th><th>Assigned</th><th>Condition</th></tr>
                    </thead>
                    <tbody>
                        @forelse($current as $a)
                            <tr>
                                <td><code>{{ $a->device?->asset_code }}</code></td>
                                <td>{{ $a->device?->name }}</td>
                                <td>{{ $a->device?->branch?->name ?? '—' }}</td>
                                <td>{{ $a->assigned_date?->format('d M Y') }}</td>
                                <td><span class="badge {{ $a->conditionBadgeClass() }}">{{ $a->condition }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-3 text-muted">No active assignments.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white">
                <strong>History (Returned)</strong>
                <span class="badge bg-secondary ms-2">{{ $history->count() }}</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr><th>Asset Code</th><th>Name</th><th>Branch</th><th>Assigned</th><th>Returned</th><th>Condition</th></tr>
                    </thead>
                    <tbody>
                        @forelse($history as $a)
                            <tr>
                                <td><code>{{ $a->device?->asset_code }}</code></td>
                                <td>{{ $a->device?->name }}</td>
                                <td>{{ $a->device?->branch?->name ?? '—' }}</td>
                                <td>{{ $a->assigned_date?->format('d M Y') }}</td>
                                <td>{{ $a->returned_date?->format('d M Y') }}</td>
                                <td><span class="badge {{ $a->conditionBadgeClass() }}">{{ $a->condition }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-3 text-muted">No history.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="alert alert-info">Select an employee above to view their asset history.</div>
    @endif
</div>
@endsection
