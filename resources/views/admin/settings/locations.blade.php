@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 fw-bold"><i class="bi bi-geo-alt-fill me-2 text-primary"></i>Locations</h4>
    @can('manage-settings')
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addBranchModal">
        <i class="bi bi-plus-lg me-1"></i>Add Branch
    </button>
    @endcan
</div>


<div class="alert alert-info small py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Location hierarchy:</strong> Branch → Floor → Rack (network equipment) / Office (rooms).
    Set an <strong>Extension Range</strong> on each floor so onboarding automatically assigns the right IP phone number for each area.
</div>

@if($branches->isEmpty())
<div class="card shadow-sm">
    <div class="text-center py-5 text-muted">
        <i class="bi bi-geo-alt display-4 d-block mb-2"></i>No branches yet. Add one to get started.
    </div>
</div>
@endif

<div class="accordion" id="branchAccordion">
@foreach($branches as $branch)
<div class="card shadow-sm mb-3">
    <div class="card-header d-flex justify-content-between align-items-center py-2">
        <button class="btn btn-link fw-bold text-start text-decoration-none flex-grow-1 p-0"
                data-bs-toggle="collapse" data-bs-target="#branch{{ $branch->id }}">
            <i class="bi bi-building me-2 text-primary"></i>{{ $branch->name }}
            <span class="badge bg-secondary ms-2">{{ $branch->networkFloors->count() }} floor(s)</span>
        </button>
        @can('manage-settings')
        <div class="d-flex gap-1 ms-2">
            <button class="btn btn-sm btn-outline-primary" onclick="openAddFloorModal({{ $branch->id }}, '{{ addslashes($branch->name) }}')">
                <i class="bi bi-plus-lg me-1"></i>Floor
            </button>
            <button class="btn btn-sm btn-outline-secondary" onclick="openEditBranchModal({{ $branch->id }}, '{{ addslashes($branch->name) }}')">
                <i class="bi bi-pencil"></i>
            </button>
            <form method="POST" action="{{ route('admin.settings.branches.destroy', $branch) }}" class="d-inline"
                  onsubmit="return confirm('Delete branch \'{{ addslashes($branch->name) }}\'? All floors, racks and offices will be deleted too.')">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
            </form>
        </div>
        @endcan
    </div>

    <div id="branch{{ $branch->id }}" class="collapse @if($loop->first) show @endif">
        <div class="card-body p-0">
            @if($branch->networkFloors->isEmpty())
            <div class="text-muted small text-center py-3">No floors. Click "+ Floor" to add one.</div>
            @else
            <div class="accordion accordion-flush" id="floorsAccordion{{ $branch->id }}">
                @foreach($branch->networkFloors as $floor)
                <div class="accordion-item border-0 border-top">
                    <div class="accordion-header d-flex align-items-center px-3 py-2 bg-light gap-2">
                        <button class="btn btn-link p-0 text-start text-decoration-none flex-grow-1 fw-semibold small"
                                data-bs-toggle="collapse" data-bs-target="#floor{{ $floor->id }}">
                            <i class="bi bi-layers me-2 text-secondary"></i>{{ $floor->name }}
                            <span class="text-muted fw-normal ms-1">({{ $floor->racks->count() }} rack(s), {{ $floor->offices->count() }} office(s))</span>
                            @if($floor->ext_range_start && $floor->ext_range_end)
                                <span class="badge bg-primary ms-2" title="IP Phone extension range for this floor">
                                    <i class="bi bi-telephone-fill me-1"></i>Ext {{ $floor->ext_range_start }}–{{ $floor->ext_range_end }}
                                </span>
                            @else
                                <span class="badge bg-light text-muted border ms-2" title="No extension range set">
                                    <i class="bi bi-telephone me-1"></i>No ext range
                                </span>
                            @endif
                        </button>
                        @can('manage-settings')
                        <div class="d-flex gap-1">
                            <button class="btn btn-sm btn-outline-success" style="font-size:.7rem;padding:.2rem .4rem"
                                    onclick="openAddOfficeModal({{ $floor->id }}, '{{ addslashes($floor->name) }}')">
                                <i class="bi bi-plus-lg"></i> Office
                            </button>
                            <button class="btn btn-sm btn-outline-info" style="font-size:.7rem;padding:.2rem .4rem"
                                    onclick="openAddRackModal({{ $floor->id }}, '{{ addslashes($floor->name) }}')">
                                <i class="bi bi-plus-lg"></i> Rack
                            </button>
                            <button class="btn btn-sm btn-outline-secondary" style="font-size:.7rem;padding:.2rem .4rem"
                                    onclick="openEditFloorModal({{ $floor->id }}, '{{ addslashes($floor->name) }}', '{{ addslashes($floor->description ?? '') }}', {{ $floor->sort_order }}, {{ $floor->ext_range_start ?? 'null' }}, {{ $floor->ext_range_end ?? 'null' }})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="POST" action="{{ route('admin.settings.floors.destroy', $floor) }}" class="d-inline"
                                  onsubmit="return confirm('Delete floor \'{{ addslashes($floor->name) }}\'?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger" style="font-size:.7rem;padding:.2rem .4rem"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                        @endcan
                    </div>

                    <div id="floor{{ $floor->id }}" class="collapse">
                        <div class="row g-0">
                            {{-- Offices column --}}
                            <div class="col-md-6 border-end">
                                <div class="px-3 py-2">
                                    <h6 class="small fw-semibold text-muted mb-2"><i class="bi bi-door-open me-1"></i>Offices / Rooms</h6>
                                    @if($floor->offices->isEmpty())
                                    <p class="text-muted small mb-0">No offices.</p>
                                    @else
                                    <ul class="list-group list-group-flush small">
                                        @foreach($floor->offices as $office)
                                        <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-geo me-1 text-muted"></i>{{ $office->name }}</span>
                                            @can('manage-settings')
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-outline-secondary p-0 px-1" style="font-size:.7rem"
                                                        onclick="openEditOfficeModal({{ $office->id }}, '{{ addslashes($office->name) }}', '{{ addslashes($office->description ?? '') }}', {{ $office->sort_order }})">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="{{ route('admin.settings.offices.destroy', $office) }}" class="d-inline"
                                                      onsubmit="return confirm('Delete office \'{{ addslashes($office->name) }}\'?')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger p-0 px-1" style="font-size:.7rem"><i class="bi bi-x"></i></button>
                                                </form>
                                            </div>
                                            @endcan
                                        </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                </div>
                            </div>
                            {{-- Racks column --}}
                            <div class="col-md-6">
                                <div class="px-3 py-2">
                                    <h6 class="small fw-semibold text-muted mb-2"><i class="bi bi-server me-1"></i>Racks</h6>
                                    @if($floor->racks->isEmpty())
                                    <p class="text-muted small mb-0">No racks.</p>
                                    @else
                                    <ul class="list-group list-group-flush small">
                                        @foreach($floor->racks as $rack)
                                        <li class="list-group-item px-0 py-1 d-flex justify-content-between align-items-center">
                                            <span><i class="bi bi-hdd-rack me-1 text-muted"></i>{{ $rack->name }}
                                                @if($rack->capacity)<small class="text-muted">({{ $rack->capacity }}U)</small>@endif
                                            </span>
                                            @can('manage-settings')
                                            <div class="d-flex gap-1">
                                                <button class="btn btn-sm btn-outline-secondary p-0 px-1" style="font-size:.7rem"
                                                        onclick="openEditRackModal({{ $rack->id }}, '{{ addslashes($rack->name) }}', '{{ addslashes($rack->description ?? '') }}', {{ $rack->capacity ?? 0 }}, {{ $rack->sort_order }})">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" action="{{ route('admin.settings.racks.destroy', $rack) }}" class="d-inline"
                                                      onsubmit="return confirm('Delete rack \'{{ addslashes($rack->name) }}\'?')">
                                                    @csrf @method('DELETE')
                                                    <button type="submit" class="btn btn-sm btn-outline-danger p-0 px-1" style="font-size:.7rem"><i class="bi bi-x"></i></button>
                                                </form>
                                            </div>
                                            @endcan
                                        </li>
                                        @endforeach
                                    </ul>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endforeach
            </div>
            @endif
        </div>
    </div>
</div>
@endforeach
</div>

{{-- ─── Modals ──────────────────────────────────────────────────────────── --}}
@can('manage-settings')

{{-- Add Branch --}}
<div class="modal fade" id="addBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.settings.branches.store') }}">
                @csrf
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-building me-2"></i>Add Branch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch ID <span class="text-danger">*</span></label>
                        <input type="number" name="id" class="form-control" required min="1" placeholder="Unique integer ID">
                        <div class="form-text">A unique numeric ID for this branch (cannot be changed later).</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="255" placeholder="e.g. Head Office">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Add Branch</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Branch --}}
<div class="modal fade" id="editBranchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editBranchForm" action="">
                @csrf @method('PUT')
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-building me-2"></i>Edit Branch</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editBranchName" class="form-control" required maxlength="255">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Floor --}}
<div class="modal fade" id="addFloorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="addFloorForm" action="{{ route('admin.settings.floors.store') }}">
                @csrf
                <input type="hidden" name="branch_id" id="addFloorBranchId">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-layers me-2"></i>Add Floor — <span id="addFloorBranchName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Floor Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100" placeholder="e.g. Ground Floor">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                    <hr class="my-2">
                    <p class="small fw-semibold text-muted mb-2"><i class="bi bi-telephone-fill me-1"></i>IP Phone Extension Range</p>
                    <div class="form-text mb-2">Extensions assigned during onboarding will be picked from this range for employees on this floor.</div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small">Range Start</label>
                            <input type="number" name="ext_range_start" class="form-control form-control-sm" min="100" max="99999" placeholder="e.g. 1000">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Range End</label>
                            <input type="number" name="ext_range_end" class="form-control form-control-sm" min="100" max="99999" placeholder="e.g. 1099">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Add Floor</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Floor --}}
<div class="modal fade" id="editFloorModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editFloorForm" action="">
                @csrf @method('PUT')
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-layers me-2"></i>Edit Floor</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Floor Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editFloorName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="editFloorDesc" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="editFloorSort" class="form-control" min="0">
                    </div>
                    <hr class="my-2">
                    <p class="small fw-semibold text-muted mb-2"><i class="bi bi-telephone-fill me-1"></i>IP Phone Extension Range</p>
                    <div class="form-text mb-2">Extensions assigned during onboarding will be picked from this range for employees on this floor.</div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label small">Range Start</label>
                            <input type="number" name="ext_range_start" id="editFloorExtStart" class="form-control form-control-sm" min="100" max="99999" placeholder="e.g. 1000">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">Range End</label>
                            <input type="number" name="ext_range_end" id="editFloorExtEnd" class="form-control form-control-sm" min="100" max="99999" placeholder="e.g. 1099">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Office --}}
<div class="modal fade" id="addOfficeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.settings.offices.store') }}">
                @csrf
                <input type="hidden" name="floor_id" id="addOfficeFloorId">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-door-open me-2"></i>Add Office — <span id="addOfficeFloorName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Office / Room Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100" placeholder="e.g. Finance Office">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">Add Office</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Office --}}
<div class="modal fade" id="editOfficeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editOfficeForm" action="">
                @csrf @method('PUT')
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-door-open me-2"></i>Edit Office</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editOfficeName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="editOfficeDesc" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="editOfficeSort" class="form-control" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Add Rack --}}
<div class="modal fade" id="addRackModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.settings.racks.store') }}">
                @csrf
                <input type="hidden" name="floor_id" id="addRackFloorId">
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-server me-2"></i>Add Rack — <span id="addRackFloorName"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Rack Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required maxlength="100" placeholder="e.g. Rack A">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity (U units)</label>
                        <input type="number" name="capacity" class="form-control" min="1" max="100" placeholder="42">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info btn-sm">Add Rack</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Edit Rack --}}
<div class="modal fade" id="editRackModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editRackForm" action="">
                @csrf @method('PUT')
                <div class="modal-header"><h5 class="modal-title"><i class="bi bi-server me-2"></i>Edit Rack</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editRackName" class="form-control" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" id="editRackDesc" class="form-control" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Capacity (U)</label>
                        <input type="number" name="capacity" id="editRackCap" class="form-control" min="1" max="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Sort Order</label>
                        <input type="number" name="sort_order" id="editRackSort" class="form-control" min="0">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script>
const BASE = '{{ url("admin/settings") }}';

function openEditBranchModal(id, name) {
    document.getElementById('editBranchForm').action = BASE + '/branches/' + id;
    document.getElementById('editBranchName').value  = name;
    new bootstrap.Modal(document.getElementById('editBranchModal')).show();
}
function openAddFloorModal(branchId, branchName) {
    document.getElementById('addFloorBranchId').value   = branchId;
    document.getElementById('addFloorBranchName').textContent = branchName;
    new bootstrap.Modal(document.getElementById('addFloorModal')).show();
}
function openEditFloorModal(id, name, desc, sort, extStart, extEnd) {
    document.getElementById('editFloorForm').action      = BASE + '/floors/' + id;
    document.getElementById('editFloorName').value       = name;
    document.getElementById('editFloorDesc').value       = desc;
    document.getElementById('editFloorSort').value       = sort;
    document.getElementById('editFloorExtStart').value   = extStart ?? '';
    document.getElementById('editFloorExtEnd').value     = extEnd   ?? '';
    new bootstrap.Modal(document.getElementById('editFloorModal')).show();
}
function openAddOfficeModal(floorId, floorName) {
    document.getElementById('addOfficeFloorId').value      = floorId;
    document.getElementById('addOfficeFloorName').textContent = floorName;
    new bootstrap.Modal(document.getElementById('addOfficeModal')).show();
}
function openEditOfficeModal(id, name, desc, sort) {
    document.getElementById('editOfficeForm').action = BASE + '/offices/' + id;
    document.getElementById('editOfficeName').value  = name;
    document.getElementById('editOfficeDesc').value  = desc;
    document.getElementById('editOfficeSort').value  = sort;
    new bootstrap.Modal(document.getElementById('editOfficeModal')).show();
}
function openAddRackModal(floorId, floorName) {
    document.getElementById('addRackFloorId').value      = floorId;
    document.getElementById('addRackFloorName').textContent = floorName;
    new bootstrap.Modal(document.getElementById('addRackModal')).show();
}
function openEditRackModal(id, name, desc, cap, sort) {
    document.getElementById('editRackForm').action = BASE + '/racks/' + id;
    document.getElementById('editRackName').value  = name;
    document.getElementById('editRackDesc').value  = desc;
    document.getElementById('editRackCap').value   = cap;
    document.getElementById('editRackSort').value  = sort;
    new bootstrap.Modal(document.getElementById('editRackModal')).show();
}
</script>
@endpush
@endsection
