@extends('layouts.admin')
@section('title', 'Licenses')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-key me-2"></i>Software Licenses</h4>
        @can('manage-licenses')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addLicenseModal">
            <i class="bi bi-plus-lg me-1"></i>Add License
        </button>
        @endcan
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    {{-- Filters --}}
    <form method="GET" class="mb-3">
        <div class="d-flex gap-2 flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="{{ request('search') }}" style="max-width:250px">
            <select name="type" class="form-select form-select-sm" style="max-width:150px">
                <option value="">All Types</option>
                @foreach($licenseTypes as $t)
                <option value="{{ $t }}" {{ request('type')===$t ? 'selected' : '' }}>{{ ucfirst($t) }}</option>
                @endforeach
            </select>
            <select name="status" class="form-select form-select-sm" style="max-width:150px">
                <option value="">All Status</option>
                <option value="active" {{ request('status')==='active' ? 'selected' : '' }}>Active</option>
                <option value="expiring" {{ request('status')==='expiring' ? 'selected' : '' }}>Expiring Soon</option>
                <option value="expired" {{ request('status')==='expired' ? 'selected' : '' }}>Expired</option>
            </select>
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
            @if(request()->anyFilled(['search','type','status']))
            <a href="{{ route('admin.itam.licenses.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            @endif
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>License</th>
                        <th>Vendor</th>
                        <th>Type</th>
                        <th>Seats Used</th>
                        <th>Expiry</th>
                        <th>Cost</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($licenses as $lic)
                    <tr>
                        <td class="fw-semibold">{{ $lic->license_name }}</td>
                        <td>{{ $lic->vendor ?: '—' }}</td>
                        <td><span class="badge bg-secondary">{{ ucfirst($lic->license_type) }}</span></td>
                        <td style="min-width:150px">
                            <div class="d-flex align-items-center gap-2">
                                <div class="progress flex-grow-1" style="height:8px">
                                    <div class="progress-bar {{ $lic->seatUsagePercent() >= 100 ? 'bg-danger' : ($lic->seatUsagePercent() >= 80 ? 'bg-warning' : 'bg-success') }}"
                                         style="width:{{ min(100, $lic->seatUsagePercent()) }}%"></div>
                                </div>
                                <small class="text-muted">{{ $lic->usedSeats() }}/{{ $lic->seats }}</small>
                            </div>
                        </td>
                        <td>
                            @if($lic->expiry_date)
                                <span class="badge bg-{{ $lic->expiryBadgeClass() }}">{{ $lic->expiry_date->format('d M Y') }}</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="font-monospace">${{ $lic->cost ? number_format($lic->cost, 0) : '—' }}</td>
                        <td class="text-end">
                            @can('manage-licenses')
                            <button class="btn btn-sm btn-outline-primary" title="Assign"
                                onclick="openAssign({{ $lic->id }}, '{{ addslashes($lic->license_name) }}', {{ $lic->availableSeats() }})"
                                data-bs-toggle="modal" data-bs-target="#assignModal"
                                @if($lic->availableSeats() <= 0) disabled @endif>
                                <i class="bi bi-person-plus"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary"
                                onclick="editLicense({{ json_encode($lic) }})"
                                data-bs-toggle="modal" data-bs-target="#editLicenseModal">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('admin.itam.licenses.destroy', $lic) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this license?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No licenses found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $licenses->links() }}</div>
</div>

{{-- Add Modal --}}
<div class="modal fade" id="addLicenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form action="{{ route('admin.itam.licenses.store') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add License</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.itam.licenses._form')
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Edit Modal --}}
<div class="modal fade" id="editLicenseModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="editLicenseForm" method="POST">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit License</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.itam.licenses._form', ['editing' => true])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- Assign Modal --}}
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="assignForm" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-person-plus me-1"></i>Assign License</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">License: <strong id="assignLicenseName"></strong> | Available: <span id="assignAvailable" class="badge bg-success"></span></p>
                    <div class="mb-3">
                        <label class="form-label">Assign To <span class="text-danger">*</span></label>
                        <select name="assignable_type" class="form-select" id="assignableType" required onchange="loadAssignables(this.value)">
                            <option value="">Select type...</option>
                            <option value="device">Device</option>
                            <option value="employee">Employee</option>
                        </select>
                    </div>
                    <div class="mb-3" id="assignableIdGroup" style="display:none">
                        <label class="form-label">Select <span id="assignableTypeLabel">Item</span></label>
                        <select name="assignable_id" class="form-select" id="assignableId" required>
                            <option value="">Loading...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Assigned Date <span class="text-danger">*</span></label>
                        <input type="date" name="assigned_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Assign</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
let currentLicenseId = null;

function openAssign(licenseId, name, available) {
    currentLicenseId = licenseId;
    document.getElementById('assignLicenseName').textContent = name;
    document.getElementById('assignAvailable').textContent = available;
    document.getElementById('assignForm').action = `/admin/itam/licenses/${licenseId}/assign`;
    document.getElementById('assignableType').value = '';
    document.getElementById('assignableIdGroup').style.display = 'none';
}

async function loadAssignables(type) {
    if (!type) { document.getElementById('assignableIdGroup').style.display = 'none'; return; }
    document.getElementById('assignableTypeLabel').textContent = type === 'device' ? 'Device' : 'Employee';
    document.getElementById('assignableIdGroup').style.display = '';
    const select = document.getElementById('assignableId');
    select.innerHTML = '<option>Loading...</option>';
    const endpoint = type === 'device' ? '/admin/devices' : '/admin/employees';
    try {
        const resp = await fetch(endpoint + '?format=json', { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await resp.json();
        select.innerHTML = '<option value="">Select...</option>' + (data.items || data).map(i => `<option value="${i.id}">${i.name}</option>`).join('');
    } catch(e) {
        select.innerHTML = '<option value="">Error loading — enter ID manually</option>';
    }
}

function editLicense(lic) {
    const form = document.getElementById('editLicenseForm');
    form.action = `/admin/itam/licenses/${lic.id}`;
    form.querySelector('[name=license_name]').value = lic.license_name || '';
    form.querySelector('[name=vendor]').value = lic.vendor || '';
    form.querySelector('[name=license_type]').value = lic.license_type || '';
    form.querySelector('[name=purchase_date]').value = lic.purchase_date || '';
    form.querySelector('[name=expiry_date]').value = lic.expiry_date || '';
    form.querySelector('[name=cost]').value = lic.cost || '';
    form.querySelector('[name=seats]').value = lic.seats || 1;
    form.querySelector('[name=notes]').value = lic.notes || '';
}
</script>
@endpush
