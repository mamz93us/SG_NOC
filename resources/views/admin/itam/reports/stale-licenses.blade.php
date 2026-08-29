@extends('layouts.admin')
@section('title', 'Stale Licenses')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3 d-print-none">
        <h4 class="mb-0"><i class="bi bi-exclamation-triangle me-2 text-danger"></i>Stale Licenses</h4>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.itam.reports.stale-licenses', ['csv' => 1]) }}" class="btn btn-sm btn-outline-success">
                <i class="bi bi-filetype-csv me-1"></i>Export CSV
            </a>
            <a href="{{ route('admin.itam.reports.index') }}" class="btn btn-sm btn-outline-secondary">Back</a>
        </div>
    </div>

    <p class="text-muted small mb-3">
        Licenses still assigned to an employee who is terminated or whose Azure account has been disabled — a paid
        seat someone else could be using, or worse, access a former employee may still technically hold.
    </p>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-danger">{{ $assignments->total() }}</div>
                    <div class="small text-muted">Stale Assignment{{ $assignments->total() === 1 ? '' : 's' }}</div>
                </div>
            </div>
        </div>
        @forelse($wastedByCurrency as $currency => $total)
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-warning">{{ $currency }} {{ number_format($total, 2) }}</div>
                    <div class="small text-muted">Wasted Cost ({{ $currency }})</div>
                </div>
            </div>
        </div>
        @empty
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center">
                <div class="card-body py-3">
                    <div class="display-6 fw-bold text-muted">—</div>
                    <div class="small text-muted">Wasted Cost</div>
                </div>
            </div>
        </div>
        @endforelse
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Employee</th>
                        <th>Status</th>
                        <th>License</th>
                        <th>Vendor</th>
                        <th>Cost</th>
                        <th>Assigned</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($assignments as $la)
                    @php $emp = $la->assignable; @endphp
                    <tr>
                        <td>
                            @if($emp)
                                <a href="{{ route('admin.employees.show', $emp) }}">{{ $emp->name }}</a>
                                <div class="small text-muted">{{ $emp->email }}</div>
                            @else
                                <span class="text-muted">— (deleted employee)</span>
                            @endif
                        </td>
                        <td>
                            @if($emp?->status === 'terminated')
                                <span class="badge bg-danger">Terminated</span>
                                @if($emp->terminated_date)<div class="small text-muted">{{ $emp->terminated_date->format('d M Y') }}</div>@endif
                            @endif
                            @if($emp?->azure_disabled_at)
                                <span class="badge bg-warning text-dark">Azure Disabled</span>
                                <div class="small text-muted">{{ $emp->azure_disabled_at->format('d M Y') }}</div>
                            @endif
                        </td>
                        <td class="fw-semibold">{{ $la->license?->license_name ?? '—' }}</td>
                        <td>{{ $la->license?->vendorDisplay() ?: '—' }}</td>
                        <td class="font-monospace">
                            @if($la->license?->cost)
                                {{ $la->license->currency ?? 'USD' }} {{ number_format($la->license->cost, 2) }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="small">{{ $la->assigned_date?->format('d M Y') ?? '—' }}</td>
                        <td class="text-end">
                            @can('manage-licenses')
                            <form action="{{ route('admin.itam.licenses.unassign', [$la->license_id, $la->id]) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Release this license{{ $la->license?->identityLicense ? ' — this also revokes it live via Microsoft Graph' : '' }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-lg me-1"></i>Release
                                </button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center py-5 text-muted">No stale license assignments found. 🎉</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $assignments->links() }}</div>
</div>
@endsection
