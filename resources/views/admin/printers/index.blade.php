@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-printer-fill me-2 text-primary"></i>Printers</h4>
        <small class="text-muted">Printer inventory across all branches</small>
    </div>
    @can('manage-printers')
    <a href="{{ route('admin.printers.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add Printer
    </a>
    @endcan
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm" placeholder="Name / IP / Model" value="{{ request('search') }}">
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
        <select name="department" class="form-select form-select-sm">
            <option value="">All Departments</option>
            @foreach($departments as $dep)
            <option value="{{ $dep }}" {{ request('department') == $dep ? 'selected' : '' }}>{{ $dep }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.printers.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($printers->isEmpty())
        <div class="text-center py-5 text-muted"><i class="bi bi-printer display-4 d-block mb-2"></i>No printers found.</div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>Model</th><th>IP</th><th>MAC</th>
                        <th>Branch</th><th>Floor / Room</th><th>Department</th>
                        <th class="text-center">Credentials</th><th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($printers as $p)
                    <tr>
                        <td class="fw-semibold">{{ $p->printer_name }}</td>
                        <td class="text-muted">{{ $p->manufacturer ? $p->manufacturer . ' ' . $p->model : ($p->model ?: '—') }}</td>
                        <td class="font-monospace">{{ $p->ip_address ?: '—' }}</td>
                        <td class="font-monospace text-muted">{{ $p->mac_address ?: '—' }}</td>
                        <td>{{ $p->branch?->name ?: '—' }}</td>
                        <td class="text-muted">{{ $p->locationLabel() }}</td>
                        <td>{{ $p->department ?: '—' }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $p->device?->credentials->count() > 0 ? 'primary' : 'light text-muted border' }}">
                                {{ $p->device?->credentials->count() ?? 0 }}
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('admin.printers.show', $p) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-eye"></i></a>
                            @can('manage-printers')
                            <a href="{{ route('admin.printers.edit', $p) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                            @endcan
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $printers->links() }}</div>
        @endif
    </div>
</div>
@endsection
