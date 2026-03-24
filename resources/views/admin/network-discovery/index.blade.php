@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-radar me-2 text-primary"></i>Network Discovery</h4>
        <small class="text-muted">Scan IP ranges to discover and import network devices</small>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-circle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- ── New Scan Form ── --}}
@can('manage-printers')
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold py-3">
        <i class="bi bi-plus-circle me-2 text-primary"></i>New Scan
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.network-discovery.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Scan Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                           placeholder="e.g. Branch A — Floor 2"
                           value="{{ old('name') }}" required>
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">IP Range <span class="text-danger">*</span></label>
                    <input type="text" name="range_input" class="form-control font-monospace @error('range_input') is-invalid @enderror"
                           placeholder="192.168.1.0/24 or 192.168.1.1-254"
                           value="{{ old('range_input') }}" required>
                    <div class="form-text">Supports CIDR, last-octet range, full range, or single IP</div>
                    @error('range_input')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">Branch</label>
                    <select name="branch_id" class="form-select">
                        <option value="">— Any —</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" @selected(old('branch_id') == $b->id)>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label fw-semibold">SNMP Community</label>
                    <input type="text" name="snmp_community" class="form-control font-monospace"
                           placeholder="public" value="{{ old('snmp_community', 'public') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label fw-semibold">Timeout (s)</label>
                    <input type="number" name="snmp_timeout" class="form-control"
                           min="1" max="10" value="{{ old('snmp_timeout', 2) }}">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-play-fill me-1"></i>Scan
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan

{{-- ── Past Scans ── --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold py-3">
        <i class="bi bi-clock-history me-2 text-secondary"></i>Scan History
    </div>
    @if($scans->isEmpty())
    <div class="card-body text-muted text-center py-5">
        <i class="bi bi-radar fs-2 d-block mb-2"></i>No scans yet. Run your first scan above.
    </div>
    @else
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Range</th>
                    <th>Branch</th>
                    <th>Status</th>
                    <th>Hosts</th>
                    <th>Reachable</th>
                    <th>Duration</th>
                    <th>Created by</th>
                    <th>Started</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach($scans as $scan)
                <tr>
                    <td class="fw-semibold">
                        <a href="{{ route('admin.network-discovery.show', $scan) }}" class="text-decoration-none">
                            {{ $scan->name }}
                        </a>
                    </td>
                    <td class="font-monospace text-muted small">{{ $scan->range_input }}</td>
                    <td>{{ $scan->branch?->name ?? '—' }}</td>
                    <td>
                        <span class="badge bg-{{ $scan->statusBadgeClass() }}">
                            @if($scan->status === 'running')<span class="spinner-border spinner-border-sm me-1" style="width:.65rem;height:.65rem"></span>@endif
                            {{ ucfirst($scan->status) }}
                        </span>
                    </td>
                    <td>{{ $scan->total_hosts ?: '—' }}</td>
                    <td>
                        @if($scan->reachable_count > 0)
                            <span class="text-success fw-semibold">{{ $scan->reachable_count }}</span>
                        @else
                            <span class="text-muted">0</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $scan->duration() ?? '—' }}</td>
                    <td class="text-muted small">{{ $scan->creator?->name ?? '—' }}</td>
                    <td class="text-muted small">{{ $scan->started_at?->format('d M Y H:i') ?? $scan->created_at->format('d M Y H:i') }}</td>
                    <td class="text-end">
                        <a href="{{ route('admin.network-discovery.show', $scan) }}"
                           class="btn btn-sm btn-outline-primary me-1" title="View Results">
                            <i class="bi bi-eye"></i>
                        </a>
                        @can('manage-printers')
                        <form action="{{ route('admin.network-discovery.destroy', $scan) }}" method="POST" class="d-inline"
                              onsubmit="return confirm('Delete this scan and all its results?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
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
    @if($scans->hasPages())
    <div class="card-footer bg-white">{{ $scans->links() }}</div>
    @endif
    @endif
</div>

@endsection
