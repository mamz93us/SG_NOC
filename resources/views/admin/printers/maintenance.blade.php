@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-tools me-2 text-primary"></i>Maintenance Log &mdash; {{ $printer->printer_name }}</h4>
        <small class="text-muted">{{ $printer->branch?->name ?? '—' }}</small>
    </div>
    <a href="{{ route('admin.printers.show', $printer->id) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Printer</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="row g-4">
    <div class="col-12 col-lg-4">
        {{-- Add Log Form --}}
        @can('manage-printers')
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent"><strong><i class="bi bi-plus-circle me-1"></i>Add Maintenance Record</strong></div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.printers.maintenance.store', $printer->id) }}">
                    @csrf
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Type <span class="text-danger">*</span></label>
                        <select name="type" class="form-select form-select-sm" required>
                            <option value="toner_change">Toner Change</option>
                            <option value="service">Service</option>
                            <option value="repair">Repair</option>
                            <option value="inspection">Inspection</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="performed_at" class="form-control form-control-sm" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Performed By</label>
                        <input type="text" name="performed_by_name" class="form-control form-control-sm" value="{{ auth()->user()->name }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Cost (SAR)</label>
                        <input type="number" name="cost" class="form-control form-control-sm" step="0.01" min="0">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Description</label>
                        <textarea name="description" class="form-control form-control-sm" rows="3"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i>Save Record</button>
                </form>
            </div>
        </div>
        @endcan
    </div>

    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-transparent">
                <strong><i class="bi bi-clock-history me-1"></i>Maintenance History</strong>
                <span class="badge bg-secondary ms-2">{{ $logs->total() }}</span>
            </div>
            <div class="card-body p-0">
                @if($logs->isEmpty())
                <div class="text-center py-4 text-muted small"><i class="bi bi-clipboard-x d-block display-5 mb-2"></i>No maintenance records yet.</div>
                @else
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Type</th>
                                <th>Date</th>
                                <th>Performed By</th>
                                <th>Cost</th>
                                <th>Description</th>
                                <th class="pe-3"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($logs as $log)
                            <tr>
                                <td class="ps-3"><span class="badge {{ $log->typeBadgeClass() }}"><i class="bi {{ $log->typeIcon() }} me-1"></i>{{ $log->typeLabel() }}</span></td>
                                <td class="text-nowrap">{{ $log->performed_at->format('d M Y') }}</td>
                                <td>{{ $log->performerName() }}</td>
                                <td>{{ $log->cost ? number_format($log->cost, 2) . ' SAR' : '—' }}</td>
                                <td class="text-muted">{{ Str::limit($log->description, 60) }}</td>
                                <td class="pe-3">
                                    @can('manage-printers')
                                    <form method="POST" action="{{ route('admin.printers.maintenance.destroy', [$printer->id, $log->id]) }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-xs btn-outline-danger" onclick="return confirm('Delete this log entry?')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                    @endcan
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 border-top">{{ $logs->links() }}</div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
