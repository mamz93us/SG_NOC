@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Landlines</h4>
        <small class="text-muted">Landline registry across branches</small>
    </div>
    @can('manage-extensions')
    <a href="{{ route('admin.telecom.landlines.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add Landline
    </a>
    @endcan
</div>

{{-- Summary --}}
<div class="row g-3 mb-3">
    @php
        $total = $landlines->total();
        $active = \App\Models\Landline::where('status', 'active')->count();
        $disconnected = \App\Models\Landline::where('status', 'disconnected')->count();
        $spare = \App\Models\Landline::where('status', 'spare')->count();
    @endphp
    <div class="col-auto"><span class="badge bg-primary-subtle text-primary border px-3 py-2">Total: {{ $total }}</span></div>
    <div class="col-auto"><span class="badge bg-success-subtle text-success border px-3 py-2">Active: {{ $active }}</span></div>
    <div class="col-auto"><span class="badge bg-danger-subtle text-danger border px-3 py-2">Disconnected: {{ $disconnected }}</span></div>
    <div class="col-auto"><span class="badge bg-warning-subtle text-warning border px-3 py-2">Spare: {{ $spare }}</span></div>
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Number / Provider / Notes" value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="branch" class="form-select form-select-sm">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}" {{ request('branch') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Statuses</option>
            @foreach($statuses as $key => $label)
            <option value="{{ $key }}" {{ request('status') == $key ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.telecom.landlines.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($landlines->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-telephone display-4 d-block mb-2"></i>No landlines found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Branch</th>
                        <th>Phone Number</th>
                        <th>Provider</th>
                        <th>FXO Port</th>
                        <th>Gateway</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($landlines as $l)
                    <tr>
                        <td class="fw-semibold">{{ $l->branch?->name ?: '—' }}</td>
                        <td class="font-monospace fw-bold">{{ $l->phone_number }}</td>
                        <td>{{ $l->provider ?: '—' }}</td>
                        <td>{{ $l->fxo_port ?: '—' }}</td>
                        <td>{{ $l->gateway?->name ?: '—' }}</td>
                        <td><span class="badge {{ $l->statusBadgeClass() }}">{{ ucfirst($l->status) }}</span></td>
                        <td class="text-muted">{{ Str::limit($l->notes, 40) ?: '—' }}</td>
                        <td class="text-nowrap">
                            @can('manage-extensions')
                            <a href="{{ route('admin.telecom.landlines.edit', $l) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            <form method="POST" action="{{ route('admin.telecom.landlines.destroy', $l) }}" class="d-inline" onsubmit="return confirm('Delete this landline?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $landlines->links() }}</div>
        @endif
    </div>
</div>

@endsection
