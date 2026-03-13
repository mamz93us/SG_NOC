@extends('layouts.admin')
@section('title', 'Azure Device Sync')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-microsoft me-2"></i>Azure Device Sync</h4>
            @if($lastSync)
            <small class="text-muted">Last sync: {{ \Carbon\Carbon::parse($lastSync)->diffForHumans() }}</small>
            @endif
        </div>
        <form action="{{ route('admin.itam.azure.sync') }}" method="POST">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-repeat me-1"></i>Sync Now
            </button>
        </form>
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    {{-- Pending Links --}}
    @if($pending->count() > 0)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-link-45deg me-1"></i>Pending Links</span>
            <span class="badge bg-warning text-dark">{{ $pending->count() }}</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Azure Device</th>
                        <th>OS</th>
                        <th>Serial</th>
                        <th>UPN</th>
                        <th>Proposed Link</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($pending as $az)
                    <tr>
                        <td class="fw-semibold">{{ $az->display_name }}</td>
                        <td>{{ $az->os }}</td>
                        <td class="font-monospace small">{{ $az->serial_number ?: '—' }}</td>
                        <td class="small">{{ $az->upn ?: '—' }}</td>
                        <td>
                            @if($az->device)
                            <a href="{{ route('admin.devices.show', $az->device) }}" class="text-decoration-none">
                                <i class="bi bi-pc-display me-1"></i>{{ $az->device->name }}
                            </a>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <form action="{{ route('admin.itam.azure.approve', $az) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-success" title="Approve Link">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                </form>
                                <form action="{{ route('admin.itam.azure.reject', $az) }}" method="POST">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                                <a href="{{ route('admin.itam.azure.create-device', $az) }}" class="btn btn-sm btn-outline-primary" title="Create New Device">
                                    <i class="bi bi-plus-lg"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- All Azure Devices --}}
    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold">All Azure Devices</span>
        </div>
        <div class="card-body border-bottom pb-3">
            <form method="GET" class="d-flex gap-2 flex-wrap">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="{{ request('search') }}" style="max-width:250px">
                <select name="status" class="form-select form-select-sm" style="max-width:150px">
                    <option value="">All Status</option>
                    @foreach($statuses as $s)
                    <option value="{{ $s }}" {{ request('status')===$s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                    @endforeach
                </select>
                <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
                @if(request()->anyFilled(['search','status']))
                <a href="{{ route('admin.itam.azure.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                @endif
            </form>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Device Name</th>
                        <th>OS</th>
                        <th>Serial</th>
                        <th>UPN</th>
                        <th>Status</th>
                        <th>Linked Asset</th>
                        <th>Last Sync</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($azureDevices as $az)
                    <tr>
                        <td class="fw-semibold">{{ $az->display_name }}</td>
                        <td>{{ $az->os }}{{ $az->os_version ? ' '.$az->os_version : '' }}</td>
                        <td class="font-monospace small">{{ $az->serial_number ?: '—' }}</td>
                        <td class="small">{{ $az->upn ?: '—' }}</td>
                        <td><span class="badge bg-{{ $az->linkStatusBadgeClass() }}">{{ $az->linkStatusLabel() }}</span></td>
                        <td>
                            @if($az->device)
                            <a href="{{ route('admin.devices.show', $az->device) }}" class="text-decoration-none small">{{ $az->device->name }}</a>
                            @else
                            <span class="text-muted small">—</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $az->last_sync_at?->diffForHumans() }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No Azure devices synced yet. Click "Sync Now" to begin.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $azureDevices->links() }}</div>
</div>
@endsection
