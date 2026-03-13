@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-cpu me-2 text-primary"></i>Device Inventory</h4>
        <small class="text-muted">All managed assets across branches</small>
    </div>
    @can('manage-assets')
    <a href="{{ route('admin.devices.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Add Device
    </a>
    @endcan
</div>

{{-- Filters --}}
<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Name / IP / MAC / Serial / Code" value="{{ request('search') }}">
    </div>
    <div class="col-auto">
        <select name="type" class="form-select form-select-sm">
            <option value="">All Types</option>
            @foreach($types as $t)
            <option value="{{ $t }}" {{ request('type') == $t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
            @endforeach
        </select>
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
            <option value="active"      {{ request('status') == 'active'      ? 'selected' : '' }}>Active</option>
            <option value="available"   {{ request('status') == 'available'   ? 'selected' : '' }}>Available</option>
            <option value="assigned"    {{ request('status') == 'assigned'    ? 'selected' : '' }}>Assigned</option>
            <option value="maintenance" {{ request('status') == 'maintenance' ? 'selected' : '' }}>Maintenance</option>
            <option value="retired"     {{ request('status') == 'retired'     ? 'selected' : '' }}>Retired</option>
        </select>
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
        <a href="{{ route('admin.devices.index') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
    </div>
</form>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    {{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
    {{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
</div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($devices->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-cpu display-4 d-block mb-2"></i>No devices found.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Asset Code</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Name</th>
                        <th>Model</th>
                        <th>Branch</th>
                        <th>Assigned To</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($devices as $d)
                    @php $assignment = $d->currentAssignment; @endphp
                    <tr>
                        <td class="font-monospace text-muted">
                            {{ $d->asset_code ?: '—' }}
                        </td>
                        <td>
                            <span class="badge {{ $d->statusBadgeClass() }}">{{ ucfirst($d->status) }}</span>
                        </td>
                        <td>
                            <span class="badge {{ $d->typeBadgeClass() }}">
                                <i class="bi {{ $d->typeIcon() }} me-1"></i>{{ $d->typeLabel() }}
                            </span>
                        </td>
                        <td class="fw-semibold">{{ $d->name }}</td>
                        <td class="text-muted">
                            {{ $d->deviceModel?->name ?: ($d->model ?: '—') }}
                        </td>
                        <td>{{ $d->branch?->name ?: '—' }}</td>
                        <td>
                            @if($assignment)
                            <span class="text-primary fw-semibold">{{ $assignment->employee->name }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $d->updated_at->diffForHumans() }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                <a href="{{ route('admin.devices.show', $d) }}" class="btn btn-sm btn-outline-primary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @can('manage-assets')
                                <a href="{{ route('admin.devices.edit', $d) }}" class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @if($d->isUserEquipment())
                                    @if(!$assignment)
                                    <button class="btn btn-sm btn-outline-success" title="Assign"
                                            onclick="dvQuickAssignOpen({{ $d->id }}, '{{ addslashes($d->name) }}')">
                                        <i class="bi bi-person-plus"></i>
                                    </button>
                                    @else
                                    <button class="btn btn-sm btn-outline-warning" title="Return"
                                            onclick="dvQuickReturnOpen({{ $d->id }}, '{{ addslashes($d->name) }}', '{{ addslashes($assignment->employee->name) }}')">
                                        <i class="bi bi-box-arrow-left"></i>
                                    </button>
                                    @endif
                                @endif
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $devices->links() }}</div>
        @endif
    </div>
</div>

{{-- ── Quick Assign Modal ── --}}
@can('manage-assets')
<div class="modal fade" id="dvAssignModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="dvAssignForm" method="POST">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-person-plus me-1"></i>Assign — <span id="dvAssignName"></span></h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                        <select name="employee_id" class="form-select" required>
                            <option value="">— Select Employee —</option>
                            @foreach($employees ?? [] as $emp)
                            <option value="{{ $emp->id }}">{{ $emp->name }}{{ $emp->employee_id ? ' ('.$emp->employee_id.')' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Assigned Date <span class="text-danger">*</span></label>
                            <input type="date" name="assigned_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Condition</label>
                            <select name="condition" class="form-select">
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Assign</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Quick Return Modal ── --}}
<div class="modal fade" id="dvReturnModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="dvReturnForm" method="POST">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-box-arrow-left me-1"></i>Return — <span id="dvReturnName"></span></h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-2">Returning from <strong id="dvReturnEmployee"></strong>.</p>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Return Date <span class="text-danger">*</span></label>
                            <input type="date" name="returned_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Condition</label>
                            <select name="condition" class="form-select">
                                <option value="good">Good</option>
                                <option value="fair">Fair</option>
                                <option value="poor">Poor</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-2">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm">Confirm Return</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script>
const dv_assignBase = '{{ rtrim(url("admin/devices"), "/") }}/';

function dvQuickAssignOpen(id, name) {
    document.getElementById('dvAssignName').textContent = name;
    document.getElementById('dvAssignForm').action = dv_assignBase + id + '/assign';
    new bootstrap.Modal(document.getElementById('dvAssignModal')).show();
}

function dvQuickReturnOpen(id, name, employee) {
    document.getElementById('dvReturnName').textContent = name;
    document.getElementById('dvReturnEmployee').textContent = employee;
    document.getElementById('dvReturnForm').action = dv_assignBase + id + '/return';
    new bootstrap.Modal(document.getElementById('dvReturnModal')).show();
}
</script>
@endpush

@endsection
