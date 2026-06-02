@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-code me-2 text-primary"></i>GDMS Config Templates</h4>
        <small class="text-muted">Device configuration templates pushed to phones (by model / group / site)</small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-phones')
        <form method="POST" action="{{ route('admin.gdms.templates.sync') }}">
            @csrf
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Sync from GDMS</button>
        </form>
        @endcan
        <a href="{{ route('admin.phones.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-telephone me-1"></i>Phones</a>
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2">{{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2">{{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Type</th><th>Model</th><th>Scope</th><th>Last Synced</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($templates as $t)
                <tr>
                    <td class="fw-semibold">{{ $t->name }}</td>
                    <td><span class="badge bg-secondary">{{ $t->type }}</span></td>
                    <td>{{ $t->model ?? '—' }}</td>
                    <td class="small">{{ $t->scope_ref ?? '—' }}</td>
                    <td class="small text-muted">{{ $t->synced_at?->diffForHumans() ?? '—' }}</td>
                    <td class="text-end">
                        @can('manage-phones')
                        <a href="{{ route('admin.gdms.templates.edit', $t) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit / Assign</a>
                        @endcan
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-4">
                    No templates cached yet. @can('manage-phones')Click <strong>Sync from GDMS</strong> to pull them.@endcan
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
