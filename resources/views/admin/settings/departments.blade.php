@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2 text-primary"></i>Departments</h4>
    @can('manage-settings')
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDeptModal">
        <i class="bi bi-plus-lg me-1"></i>Add Department
    </button>
    @endcan
</div>


<div class="alert alert-info small py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Departments are used to categorise assets (printers, devices). Link them when adding or editing assets.
</div>

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($departments->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-diagram-3 display-4 d-block mb-2"></i>No departments yet. Add one to get started.
        </div>
        @else
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>Description</th>
                    <th class="text-center" style="width:80px">Sort</th>
                    @can('manage-settings')
                    <th class="text-end" style="width:120px">Actions</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @foreach($departments as $dept)
                <tr>
                    <td class="fw-semibold">{{ $dept->name }}</td>
                    <td class="text-muted small">{{ $dept->description ?: '—' }}</td>
                    <td class="text-center text-muted small">{{ $dept->sort_order ?: '—' }}</td>
                    @can('manage-settings')
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                                onclick="openEditDeptModal({{ $dept->id }}, '{{ addslashes($dept->name) }}', '{{ addslashes($dept->description ?? '') }}', {{ $dept->sort_order ?? 0 }})">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST"
                              action="{{ route('admin.settings.departments.destroy', $dept) }}"
                              class="d-inline"
                              onsubmit="return confirm('Delete department «{{ addslashes($dept->name) }}»?\nLinked assets will have their department cleared.')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                    @endcan
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>

@can('manage-settings')

{{-- ── Add Department Modal ────────────────────────────────────── --}}
<div class="modal fade" id="addDeptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="{{ route('admin.settings.departments.store') }}" class="modal-content">
            @csrf
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Department</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control form-control-sm"
                           required maxlength="100" placeholder="e.g. Finance, HR, IT"
                           autofocus>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" maxlength="255">
                </div>
                <div>
                    <label class="form-label small">Sort Order <span class="text-muted">(lower = first)</span></label>
                    <input type="number" name="sort_order" class="form-control form-control-sm" value="0" min="0">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Add</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Department Modal ────────────────────────────────────── --}}
<div class="modal fade" id="editDeptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <form method="POST" id="editDeptForm" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit Department</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label fw-semibold small">Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="editDeptName" class="form-control form-control-sm"
                           required maxlength="100">
                </div>
                <div class="mb-2">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" id="editDeptDesc" class="form-control form-control-sm" maxlength="255">
                </div>
                <div>
                    <label class="form-label small">Sort Order</label>
                    <input type="number" name="sort_order" id="editDeptSort" class="form-control form-control-sm" min="0">
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endcan

@push('scripts')
<script>
function openEditDeptModal(id, name, description, sortOrder) {
    document.getElementById('editDeptForm').action = `/admin/settings/departments/${id}`;
    document.getElementById('editDeptName').value   = name;
    document.getElementById('editDeptDesc').value   = description;
    document.getElementById('editDeptSort').value   = sortOrder;
    new bootstrap.Modal(document.getElementById('editDeptModal')).show();
}
</script>
@endpush

@endsection
