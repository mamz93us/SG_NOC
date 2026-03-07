@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-building me-2 text-primary"></i>Network Locations
        </h4>
        <small class="text-muted">Manage floors and racks per branch — then assign switches to a location</small>
    </div>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addFloorModal">
        <i class="bi bi-plus-lg me-1"></i>Add Floor
    </button>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2" role="alert">
    <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2" role="alert">
    <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── Per-branch accordion ── --}}
@forelse($branches as $branch)

@php $hasFloors = $branch->networkFloors->isNotEmpty(); @endphp

<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <span class="fw-semibold">
            <i class="bi bi-geo-alt me-2 text-primary"></i>{{ $branch->name }}
        </span>
        <span class="badge bg-secondary">{{ $branch->networkFloors->count() }} floor(s)</span>
    </div>

    @if($hasFloors)
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th style="width:30px"></th>
                        <th>Floor</th>
                        <th>Description</th>
                        <th class="text-center">Racks</th>
                        <th class="text-center">Switches</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($branch->networkFloors as $floor)
                    {{-- ── Floor row ── --}}
                    <tr class="table-active">
                        <td class="ps-3">
                            <button class="btn btn-link btn-sm p-0 text-muted"
                                    data-bs-toggle="collapse"
                                    data-bs-target="#racks-floor-{{ $floor->id }}"
                                    title="Toggle racks">
                                <i class="bi bi-chevron-down"></i>
                            </button>
                        </td>
                        <td class="fw-semibold">
                            <i class="bi bi-layers me-1 text-primary"></i>{{ $floor->name }}
                        </td>
                        <td class="text-muted">{{ $floor->description ?: '—' }}</td>
                        <td class="text-center">
                            <span class="badge bg-secondary">{{ $floor->racks->count() }}</span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-info text-dark">{{ $floor->switches_count }}</span>
                        </td>
                        <td class="text-end">
                            <button class="btn btn-sm btn-outline-secondary"
                                    onclick="openAddRackModal({{ $floor->id }}, '{{ addslashes($floor->name) }}')"
                                    title="Add rack to this floor">
                                <i class="bi bi-plus-lg"></i> Rack
                            </button>
                            <button class="btn btn-sm btn-outline-primary"
                                    onclick="openEditFloorModal({{ $floor->id }}, {{ $floor->branch_id }}, '{{ addslashes($floor->name) }}', '{{ addslashes($floor->description ?? '') }}', {{ $floor->sort_order }})"
                                    title="Edit floor">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('admin.network.floors.destroy', $floor) }}"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete floor \'{{ addslashes($floor->name) }}\' and unlink its racks? Switches will keep their location data but lose rack/floor reference.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete floor">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>

                    {{-- ── Racks collapse ── --}}
                    <tr class="collapse show" id="racks-floor-{{ $floor->id }}">
                        <td colspan="6" class="p-0">
                            @if($floor->racks->isNotEmpty())
                            <table class="table table-sm mb-0 small">
                                <tbody>
                                    @foreach($floor->racks as $rack)
                                    <tr>
                                        <td style="width:30px"></td>
                                        <td style="width:50px"></td>
                                        <td>
                                            <i class="bi bi-server me-1 text-secondary"></i>
                                            <span class="text-secondary">{{ $rack->name }}</span>
                                        </td>
                                        <td class="text-muted">{{ $rack->description ?: '—' }}</td>
                                        <td class="text-center">
                                            @if($rack->capacity)
                                                <span class="badge bg-light text-dark border">{{ $rack->capacity }}U</span>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info text-dark">{{ $rack->switches_count }}</span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <button class="btn btn-sm btn-outline-primary"
                                                    onclick="openEditRackModal({{ $rack->id }}, {{ $rack->floor_id }}, '{{ addslashes($rack->name) }}', '{{ addslashes($rack->description ?? '') }}', {{ $rack->capacity ?? 'null' }}, {{ $rack->sort_order }})"
                                                    title="Edit rack">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <form method="POST" action="{{ route('admin.network.racks.destroy', $rack) }}"
                                                  class="d-inline"
                                                  onsubmit="return confirm('Delete rack \'{{ addslashes($rack->name) }}\'? Switches will lose their rack reference.')">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete rack">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            @else
                            <div class="text-muted small ps-5 py-2">No racks yet — click "+ Rack" to add one.</div>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @else
    <div class="card-body text-muted small py-3">
        No floors defined for this branch.
        <button class="btn btn-link btn-sm p-0"
                onclick="openAddFloorModal({{ $branch->id }}, '{{ addslashes($branch->name) }}')">Add one?</button>
    </div>
    @endif
</div>

@empty
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>No branches found. Create branches first, then add floors and racks.
</div>
@endforelse

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- ADD FLOOR MODAL                                                --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div class="modal fade" id="addFloorModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.network.floors.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-layers me-2"></i>Add Floor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                    <select name="branch_id" id="addFloorBranch" class="form-select" required>
                        <option value="">— Select branch —</option>
                        @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Floor Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Ground Floor, Floor 1, Basement" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" placeholder="Optional description" maxlength="255">
                </div>
                <div class="mb-1">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="0" min="0" max="999">
                    <div class="form-text">Lower numbers appear first (0 = top).</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Floor</button>
            </div>
        </form>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- EDIT FLOOR MODAL                                               --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div class="modal fade" id="editFloorModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="editFloorForm" class="modal-content">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Floor</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Floor Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="editFloorName" class="form-control" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="editFloorDesc" class="form-control" maxlength="255">
                </div>
                <div class="mb-1">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="editFloorSort" class="form-control" min="0" max="999">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- ADD RACK MODAL                                                  --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div class="modal fade" id="addRackModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.network.racks.store') }}" class="modal-content">
            @csrf
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-server me-2"></i>Add Rack</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="floor_id" id="addRackFloorId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Floor</label>
                    <input type="text" id="addRackFloorLabel" class="form-control" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Rack Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" placeholder="e.g. Rack A, Main Rack" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" placeholder="Optional description" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label">Capacity (U)</label>
                    <input type="number" name="capacity" class="form-control" placeholder="e.g. 42" min="1" max="100">
                    <div class="form-text">Rack units (optional).</div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" class="form-control" value="0" min="0" max="999">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Create Rack</button>
            </div>
        </form>
    </div>
</div>

{{-- ══════════════════════════════════════════════════════════════ --}}
{{-- EDIT RACK MODAL                                                 --}}
{{-- ══════════════════════════════════════════════════════════════ --}}
<div class="modal fade" id="editRackModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="editRackForm" class="modal-content">
            @csrf
            @method('PUT')
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Edit Rack</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Rack Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" id="editRackName" class="form-control" required maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" id="editRackDesc" class="form-control" maxlength="255">
                </div>
                <div class="mb-3">
                    <label class="form-label">Capacity (U)</label>
                    <input type="number" name="capacity" id="editRackCapacity" class="form-control" min="1" max="100">
                </div>
                <div class="mb-1">
                    <label class="form-label">Sort Order</label>
                    <input type="number" name="sort_order" id="editRackSort" class="form-control" min="0" max="999">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

@endsection

@push('scripts')
<script>
// ── Add Floor modal ──────────────────────────────────────────────
function openAddFloorModal(branchId, branchName) {
    const select = document.getElementById('addFloorBranch');
    if (select && branchId) {
        select.value = branchId;
    }
    new bootstrap.Modal(document.getElementById('addFloorModal')).show();
}

// ── Edit Floor modal ─────────────────────────────────────────────
function openEditFloorModal(id, branchId, name, desc, sortOrder) {
    const baseUrl = '{{ rtrim(route("admin.network.floors.update", ["floor" => "__ID__"]), "/") }}';
    document.getElementById('editFloorForm').action = baseUrl.replace('__ID__', id);
    document.getElementById('editFloorName').value  = name;
    document.getElementById('editFloorDesc').value  = desc;
    document.getElementById('editFloorSort').value  = sortOrder;
    new bootstrap.Modal(document.getElementById('editFloorModal')).show();
}

// ── Add Rack modal ───────────────────────────────────────────────
function openAddRackModal(floorId, floorName) {
    document.getElementById('addRackFloorId').value    = floorId;
    document.getElementById('addRackFloorLabel').value = floorName;
    new bootstrap.Modal(document.getElementById('addRackModal')).show();
}

// ── Edit Rack modal ──────────────────────────────────────────────
function openEditRackModal(id, floorId, name, desc, capacity, sortOrder) {
    const baseUrl = '{{ rtrim(route("admin.network.racks.update", ["rack" => "__ID__"]), "/") }}';
    document.getElementById('editRackForm').action    = baseUrl.replace('__ID__', id);
    document.getElementById('editRackName').value     = name;
    document.getElementById('editRackDesc').value     = desc;
    document.getElementById('editRackCapacity').value = capacity || '';
    document.getElementById('editRackSort').value     = sortOrder;
    new bootstrap.Modal(document.getElementById('editRackModal')).show();
}
</script>
@endpush
