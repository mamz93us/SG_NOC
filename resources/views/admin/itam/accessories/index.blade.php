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
                    @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No accessories found.</td></tr>
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
                        <select name="assign_to" class="form-select" required>
                            <option value="employee">Employee</option>
                            <option value="device">Device</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ID</label>
                        <input type="number" name="assignable_id" class="form-control" placeholder="Enter employee or device ID" required>
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
function openAssign(id, name) {
    document.getElementById('assignName').textContent = name;
    document.getElementById('assignForm').action = `/admin/itam/accessories/${id}/assign`;
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
