@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-start mb-4">
    <div class="d-flex align-items-center gap-2">
        <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i></a>
        <span class="badge {{ $device->typeBadgeClass() }} fs-6"><i class="bi {{ $device->typeIcon() }} me-1"></i>{{ $device->typeLabel() }}</span>
        <h4 class="mb-0 fw-bold">{{ $device->name }}</h4>
        <span class="badge {{ $device->statusBadgeClass() }}">{{ ucfirst($device->status) }}</span>
    </div>
    @can('manage-assets')
    <a href="{{ route('admin.devices.edit', $device) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    @endcan
</div>


<div class="row g-3">
    {{-- Device Info --}}
    <div class="col-md-5">
        <div class="card shadow-sm h-100">
            <div class="card-header py-2"><h6 class="mb-0 fw-semibold"><i class="bi bi-info-circle me-2"></i>Device Info</h6></div>
            <div class="card-body">
                <table class="table table-sm table-borderless small mb-0">
                    <tr><th class="text-muted w-40">Model</th><td>{{ $device->model ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Serial</th><td class="font-monospace">{{ $device->serial_number ?: '—' }}</td></tr>
                    <tr><th class="text-muted">IP</th><td class="font-monospace">{{ $device->ip_address ?: '—' }}</td></tr>
                    <tr><th class="text-muted">MAC</th><td class="font-monospace">{{ $device->mac_address ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Branch</th><td>{{ $device->branch?->name ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Location</th><td>{{ $device->location_description ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Warranty Exp.</th><td>{{ $device->warranty_expiry?->format('M d, Y') ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Firmware</th><td class="font-monospace">{{ $device->firmware_version ?: '—' }}</td></tr>
                    <tr><th class="text-muted">Source</th><td><span class="badge bg-secondary">{{ ucfirst($device->source) }}</span></td></tr>
                    <tr><th class="text-muted">Updated</th><td>{{ $device->updated_at->diffForHumans() }}</td></tr>
                </table>
                @if($device->notes)
                <hr class="my-2">
                <p class="small text-muted mb-0">{{ $device->notes }}</p>
                @endif
            </div>
        </div>
    </div>

    {{-- Credentials --}}
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-key-fill me-2"></i>Credentials</h6>
                @can('manage-credentials')
                <a href="{{ route('admin.credentials.create') }}?device_id={{ $device->id }}" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-plus-lg"></i> Add
                </a>
                @endcan
            </div>
            <div class="card-body p-0">
                @if($device->credentials->isEmpty())
                <div class="text-center py-4 text-muted small">No credentials linked to this device.</div>
                @else
                <table class="table table-sm table-hover align-middle mb-0 small">
                    <thead class="table-light"><tr><th>Title</th><th>Category</th><th>Username</th><th>Added by</th><th></th></tr></thead>
                    <tbody>
                        @foreach($device->credentials as $cred)
                        <tr>
                            <td class="fw-semibold">{{ $cred->title }}</td>
                            <td><span class="badge {{ $cred->categoryBadgeClass() }}">{{ $cred->categoryLabel() }}</span></td>
                            <td class="font-monospace text-muted">{{ $cred->username ?: '—' }}</td>
                            <td class="text-muted">{{ $cred->creator?->name ?: '—' }}</td>
                            <td>
                                @can('manage-credentials')
                                <a href="{{ route('admin.credentials.edit', $cred) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
                                @endcan
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
    </div>
</div>

@can('manage-assets')
<div class="mt-4">
    <form method="POST" action="{{ route('admin.devices.destroy', $device) }}"
          onsubmit="return confirm('Delete device \'{{ addslashes($device->name) }}\'? This cannot be undone.')">
        @csrf @method('DELETE')
        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1"></i>Delete Device</button>
    </form>
</div>
@endcan
@endsection
