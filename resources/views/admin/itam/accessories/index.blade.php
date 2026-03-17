@extends('layouts.admin')
@section('title', 'Accessories')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>Accessories</h4>
        @can('manage-accessories')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAccessoryModal">
            <i class="bi bi-plus-lg me-1"></i>Add Accessory
        </button>
        @endcan
    </div>

    @if(session('success'))<div class="alert alert-success py-2">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger py-2">{{ session('error') }}</div>@endif

    <form method="GET" class="mb-3">
        <div class="d-flex gap-2 flex-wrap">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search..." value="{{ request('search') }}" style="max-width:250px">
            <select name="category" class="form-select form-select-sm" style="max-width:150px">
                <option value="">All Categories</option>
                @foreach($categories as $cat)
                <option value="{{ $cat }}" {{ request('category')===$cat ? 'selected' : '' }}>{{ ucfirst($cat) }}</option>
                @endforeach
            </select>
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filter</button>
            @if(request()->anyFilled(['search','category']))
            <a href="{{ route('admin.itam.accessories.index') }}" class="btn btn-outline-secondary btn-sm">Clear</a>
            @endif
        </div>
    </form>

    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Available</th>
                        <th class="text-center">Assigned</th>
                        <th>Cost</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($accessories as $acc)
                    <tr>
                        <td class="fw-semibold">{{ $acc->name }}</td>
                        <td><span class="badge bg-secondary">{{ $acc->category ?: '—' }}</span></td>
                        <td>{{ $acc->supplier?->name ?: '—' }}</td>
                        <td class="text-center">{{ $acc->quantity_total }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $acc->availabilityBadgeClass() }}">{{ $acc->quantity_available }}</span>
                        </td>
                        <td class="text-center">
                            @if($acc->active_assignments_count > 0)
                            <button class="btn btn-sm btn-link text-decoration-none p-0" onclick="toggleAccAssignments({{ $acc->id }})" title="View assignments">
                                <span class="badge bg-info">{{ $acc->active_assignments_count }}</span>
                            </button>
                            @else
                            <span class="text-muted">0</span>
                            @endif
                        </td>
                        <td class="font-monospace">${{ $acc->purchase_cost ? number_format($acc->purchase_cost, 0) : '—' }}</td>
                        <td class="text-end">
                            @can('manage-accessories')
                            <button class="btn btn-sm btn-outline-primary" title="Assign"
                                onclick="openAssign({{ $acc->id }}, '{{ addslashes($acc->name) }}')"
                                data-bs-toggle="modal" data-bs-target="#assignModal"
                                @if(!$acc->isAvailable()) disabled @endif>
                                <i class="bi bi-person-plus"></i>
                            </button>
                            <button class="btn btn-sm btn-outline-secondary"
                                onclick="editAccessory({{ json_encode($acc) }})"
                                data-bs-toggle="modal" data-bs-target="#editAccessoryModal">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('admin.itam.accessories.destroy', $acc) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Delete this accessory?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                            @endcan
                        </td>
                    </tr>
                    {{-- Expandable assignment detail row --}}
                    <tr id="accAssignRow{{ $acc->id }}" class="d-none">
                        <td colspan="8" class="bg-light p-0">
                            <div class="p-3">
                                <h6 class="fw-semibold small mb-2"><i class="bi bi-people me-1"></i>Active Assignments for {{ $acc->name }}</h6>
                                @if($acc->activeAssignments && $acc->activeAssignments->count() > 0)
                                <table class="table table-sm table-bordered mb-0 bg-white">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Assigned To</th>
                                            <th>Type</th>
                                            <th>Date</th>
                                            <th>Notes</th>
                                            <th class="text-end">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($acc->activeAssignments as $asgn)
                                        <tr>
                                            <td>
                                                @if($asgn->employee)
                                                    <a href="{{ route('admin.employees.show', $asgn->employee) }}">{{ $asgn->employee->name }}</a>
                                                @elseif($asgn->device)
                                                    <a href="{{ route('admin.devices.show', $asgn->device) }}">{{ $asgn->device->name }}</a>
                                                @else
                                                    <span class="text-muted">—</span>
                                                @endif
                                            </td>
                                            <td><span class="badge bg-secondary">{{ $asgn->employee_id ? 'Employee' : 'Device' }}</span></td>
                                            <td class="small">{{ $asgn->assigned_date?->format('d M Y') }}</td>
                                            <td class="small text-muted">{{ $asgn->notes ?: '—' }}</td>
                                            <td class="text-end">
                                                @can('manage-accessories')
                                                <form action="{{ route('admin.itam.accessories.return', [$acc, $asgn]) }}" method="POST" class="d-inline"
                                                      onsubmit="return confirm('Return this accessory?')">
                                                    @csrf @method('PATCH')
                                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Return">
                                                        <i class="bi bi-arrow-return-left"></i> Return
                                                    </button>
                                                </form>
                                                @endcan
                                            </td>
                                        </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @else
                                <p class="text-muted small mb-0">No active assignments.</p>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No accessories found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $accessories->links() }}</div>
</div>

{{-- Add Modal --}}
<div class="modal fade" id="addAccessoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form action="{{ route('admin.itam.accessories.store') }}" method="POST">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Accessory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.itam.accessories._form', ['suppliers' => $suppliers, 'categories' => $categories])
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
<div class="modal fade" id="editAccessoryModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="editAccessoryForm" method="POST">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit Accessory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.itam.accessories._form', ['suppliers' => $suppliers, 'categories' => $categories, 'editing' => true])
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
                    <h5 class="modal-title fw-semibold"><i class="bi bi-person-plus me-1"></i>Assign Accessory</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">Accessory: <strong id="assignName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Assign To</label>
                        <select name="assign_to" id="accAssignTo" class="form-select" required onchange="accLoadAssignables(this.value)">
                            <option value="employee">Employee</option>
                            <option value="device">Device</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select <span id="accAssignLabel">Employee</span></label>
                        <input type="text" id="accSearchInput" class="form-control mb-1" placeholder="Type to search by name, email, IP..." autocomplete="off">
                        <select name="assignable_id" id="accAssignableSelect" class="form-select" required size="5" style="min-height:120px">
                            <option value="">Select type above, then search...</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Date</label>
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
let accSearchTimer = null;

function openAssign(id, name) {
    document.getElementById('assignName').textContent = name;
    document.getElementById('assignForm').action = `/admin/itam/accessories/${id}/assign`;
    document.getElementById('accSearchInput').value = '';
    accLoadAssignables('employee');
}

async function accLoadAssignables(type, search = '') {
    document.getElementById('accAssignLabel').textContent = type === 'device' ? 'Device' : 'Employee';
    const select = document.getElementById('accAssignableSelect');
    select.innerHTML = '<option>Loading...</option>';
    try {
        const resp = await fetch(`/admin/api/search-assignables?type=${type}&q=${encodeURIComponent(search)}`, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await resp.json();
        if (data.length === 0) {
            select.innerHTML = '<option value="">No results found</option>';
        } else {
            select.innerHTML = data.map(i => `<option value="${i.id}">${i.name}</option>`).join('');
        }
    } catch(e) {
        select.innerHTML = '<option value="">Error loading</option>';
    }
}

document.getElementById('accSearchInput').addEventListener('input', function() {
    clearTimeout(accSearchTimer);
    const type = document.getElementById('accAssignTo').value;
    const q = this.value;
    accSearchTimer = setTimeout(() => accLoadAssignables(type, q), 300);
});

function toggleAccAssignments(id) {
    const row = document.getElementById('accAssignRow' + id);
    if (row) row.classList.toggle('d-none');
}

function editAccessory(a) {
    const form = document.getElementById('editAccessoryForm');
    form.action = `/admin/itam/accessories/${a.id}`;
    form.querySelector('[name=name]').value = a.name || '';
    form.querySelector('[name=category]').value = a.category || '';
    form.querySelector('[name=quantity_total]').value = a.quantity_total || 0;
    form.querySelector('[name=quantity_available]').value = a.quantity_available || 0;
    if (form.querySelector('[name=supplier_id]')) form.querySelector('[name=supplier_id]').value = a.supplier_id || '';
    form.querySelector('[name=purchase_cost]').value = a.purchase_cost || '';
    form.querySelector('[name=notes]').value = a.notes || '';
}
</script>
@endpush
