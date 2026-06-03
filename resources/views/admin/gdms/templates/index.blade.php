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

<div class="alert alert-info py-2 small">
    <i class="bi bi-info-circle me-1"></i>Read-only view of GDMS <strong>group templates</strong>. Create, edit, and assign templates in the GDMS web console — the GDMS API only exposes the template list.
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Name</th><th>Devices</th><th>Description</th><th>Last Synced</th></tr>
            </thead>
            <tbody>
            @forelse($templates as $t)
                <tr>
                    <td class="fw-semibold">{{ $t->name }}</td>
                    <td>{{ data_get($t->raw, 'deviceCount', '—') }}</td>
                    <td class="small text-muted">{{ data_get($t->raw, 'description') ?: '—' }}</td>
                    <td class="small text-muted">{{ $t->synced_at?->diffForHumans() ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-4">
                    No templates cached yet. @can('manage-phones')Click <strong>Sync from GDMS</strong> to pull them.@endcan
                </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@endsection
