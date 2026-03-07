@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-clipboard2-pulse-fill me-2 text-primary"></i>License Inventory Monitors</h4>
        <small class="text-muted">Get alerted when license seats run low</small>
    </div>
    @can('manage-license-monitors')
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addMonitorModal">
        <i class="bi bi-plus-circle me-1"></i>Add Monitor
    </button>
    @endcan
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>License</th>
                    <th class="text-center">Total</th>
                    <th class="text-center">Consumed</th>
                    <th class="text-center">Available</th>
                    <th class="text-center">Threshold</th>
                    <th>Last Alert</th>
                    <th class="text-center">Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($monitors as $monitor)
            @php
                $lic       = $monitor->identityLicense;
                $total     = $lic->enabled  ?? 0;
                $consumed  = $lic->consumed ?? 0;
                $available = $lic->available ?? max(0, $total - $consumed);
                $color     = $monitor->availabilityColor();
            @endphp
            <tr>
                <td>
                    <div class="fw-semibold">{{ $monitor->display_name }}</div>
                    <div class="text-muted small font-monospace">{{ $monitor->sku_id }}</div>
                </td>
                <td class="text-center">{{ number_format($total) }}</td>
                <td class="text-center">{{ number_format($consumed) }}</td>
                <td class="text-center">
                    <span class="fw-bold text-{{ $color }}">{{ number_format($available) }}</span>
                </td>
                <td class="text-center">
                    <span class="badge bg-warning text-dark">≤ {{ $monitor->critical_threshold }}</span>
                </td>
                <td class="small text-muted">
                    {{ $monitor->last_alerted_at ? \Carbon\Carbon::parse($monitor->last_alerted_at)->diffForHumans() : 'Never' }}
                </td>
                <td class="text-center">
                    @can('manage-license-monitors')
                    <form method="POST" action="{{ route('admin.license-monitors.toggle', $monitor->id) }}" class="d-inline">
                        @csrf @method('PATCH')
                        <div class="form-check form-switch d-flex justify-content-center m-0">
                            <input class="form-check-input" type="checkbox" onchange="this.form.submit()"
                                   {{ $monitor->is_active ? 'checked' : '' }}>
                        </div>
                    </form>
                    @else
                        @if($monitor->is_active)
                        <span class="badge bg-success">On</span>
                        @else
                        <span class="badge bg-secondary">Off</span>
                        @endif
                    @endcan
                </td>
                <td class="text-end">
                    @can('manage-license-monitors')
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editMonitor{{ $monitor->id }}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" action="{{ route('admin.license-monitors.destroy', $monitor->id) }}" class="d-inline"
                          onsubmit="return confirm('Delete this monitor?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                    </form>
                    @endcan
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No license monitors configured. Add one to get alerted when seats run low.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('manage-license-monitors')
{{-- Edit Modals --}}
@foreach($monitors as $monitor)
<div class="modal fade" id="editMonitor{{ $monitor->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.license-monitors.update', $monitor->id) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit Monitor: {{ $monitor->display_name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Display Name</label>
                        <input type="text" name="display_name" class="form-control" value="{{ $monitor->display_name }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Critical Threshold</label>
                        <div class="input-group">
                            <span class="input-group-text">≤</span>
                            <input type="number" name="critical_threshold" class="form-control" value="{{ $monitor->critical_threshold }}" min="0" required>
                            <span class="input-group-text">available seats</span>
                        </div>
                        <small class="text-muted">A purchase workflow will be triggered when available seats reach this number.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- Add Monitor Modal --}}
<div class="modal fade" id="addMonitorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.license-monitors.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add License Monitor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">License <span class="text-danger">*</span></label>
                        <select name="sku_id" class="form-select" required onchange="fillDisplayName(this)">
                            <option value="">— Select License —</option>
                            @foreach($licenses as $lic)
                            <option value="{{ $lic->sku_id }}" data-name="{{ $lic->display_name }}">
                                {{ $lic->display_name }} ({{ $lic->available ?? ($lic->enabled - $lic->consumed) }} available)
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Display Name</label>
                        <input type="text" name="display_name" id="monitorDisplayName" class="form-control" placeholder="Auto-filled from license">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Critical Threshold <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">≤</span>
                            <input type="number" name="critical_threshold" class="form-control" value="5" min="0" required>
                            <span class="input-group-text">available seats</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Add Monitor</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script>
function fillDisplayName(sel) {
    const opt = sel.options[sel.selectedIndex];
    const nameField = document.getElementById('monitorDisplayName');
    if (opt && opt.dataset.name) nameField.value = opt.dataset.name;
}
</script>
@endpush
@endsection
