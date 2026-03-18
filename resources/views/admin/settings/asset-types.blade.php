@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-tags-fill me-2 text-primary"></i>Asset Types & Code Settings</h4>
        <small class="text-muted">Manage device types, category codes, and asset code generation</small>
    </div>
    <a href="{{ route('admin.devices.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-cpu me-1"></i>Devices
    </a>
</div>

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

{{-- ── Asset Code Settings ── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-2">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-upc-scan me-2 text-primary"></i>Asset Code Settings</h6>
    </div>
    <div class="card-body">
        <form method="POST" action="{{ route('admin.settings.asset-types.settings') }}">
            @csrf
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Prefix</label>
                    <input type="text" name="itam_asset_prefix" class="form-control form-control-sm"
                           value="{{ old('itam_asset_prefix', $settings->itam_asset_prefix ?? 'SG') }}"
                           pattern="[A-Za-z0-9]+" maxlength="10" required>
                    <div class="form-text">e.g. SG, ABC</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold small">Number Padding</label>
                    <input type="number" name="itam_code_padding" class="form-control form-control-sm"
                           value="{{ old('itam_code_padding', $settings->itam_code_padding ?? 6) }}"
                           min="1" max="10" required>
                    <div class="form-text">Digits count (6 = 000001)</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold small">Company URL (for labels)</label>
                    <input type="url" name="itam_company_url" class="form-control form-control-sm"
                           value="{{ old('itam_company_url', $settings->itam_company_url) }}"
                           placeholder="https://company.com">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </div>
            </div>
            <div class="mt-2">
                <small class="text-muted">
                    <strong>Preview:</strong>
                    <code>{{ $settings->itam_asset_prefix ?? 'SG' }}-LAP-{{ str_pad('1', $settings->itam_code_padding ?? 6, '0', STR_PAD_LEFT) }}</code>
                </small>
            </div>
        </form>
    </div>
</div>

{{-- ── Asset Types Table ── --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold"><i class="bi bi-list-check me-2 text-success"></i>Asset Types</h6>
        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addTypeModal">
            <i class="bi bi-plus-lg me-1"></i>Add Type
        </button>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th style="width:50px">Order</th>
                        <th>Type</th>
                        <th>Slug</th>
                        <th>Category Code</th>
                        <th>Group</th>
                        <th>User Equipment</th>
                        <th>Devices</th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($types as $type)
                    <tr>
                        <td class="text-muted">{{ $type->sort_order }}</td>
                        <td>
                            <span class="badge {{ $type->badge_class }}">
                                <i class="bi {{ $type->icon }} me-1"></i>{{ $type->label }}
                            </span>
                        </td>
                        <td class="font-monospace text-muted">{{ $type->slug }}</td>
                        <td>
                            <code>{{ ($settings->itam_asset_prefix ?? 'SG') }}-{{ $type->category_code }}-***</code>
                        </td>
                        <td>
                            @switch($type->group)
                                @case('infrastructure')
                                    <span class="badge bg-dark bg-opacity-75">Infrastructure</span>
                                    @break
                                @case('user_equipment')
                                    <span class="badge bg-primary bg-opacity-75">User Equipment</span>
                                    @break
                                @default
                                    <span class="badge bg-secondary bg-opacity-50">Other</span>
                            @endswitch
                        </td>
                        <td>
                            @if($type->is_user_equipment)
                                <i class="bi bi-check-circle-fill text-success"></i>
                            @else
                                <i class="bi bi-x-circle text-muted"></i>
                            @endif
                        </td>
                        <td>
                            @php $cnt = $deviceCounts[$type->slug] ?? 0; @endphp
                            @if($cnt > 0)
                                <a href="{{ route('admin.devices.index', ['type' => $type->slug]) }}" class="text-decoration-none fw-semibold">
                                    {{ $cnt }}
                                </a>
                            @else
                                <span class="text-muted">0</span>
                            @endif
                        </td>
                        <td>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                        onclick="openEditModal({{ json_encode($type) }})">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if(($deviceCounts[$type->slug] ?? 0) === 0)
                                <form method="POST" action="{{ route('admin.settings.asset-types.destroy', $type) }}"
                                      onsubmit="return confirm('Delete type &quot;{{ $type->label }}&quot;?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- ── Add Type Modal ── --}}
<div class="modal fade" id="addTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.settings.asset-types.store') }}">
                @csrf
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-plus-lg me-1"></i>Add Asset Type</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Slug <span class="text-danger">*</span></label>
                            <input type="text" name="slug" class="form-control form-control-sm"
                                   pattern="[a-z0-9_]+" required placeholder="e.g. camera">
                            <div class="form-text">Lowercase, no spaces</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Label <span class="text-danger">*</span></label>
                            <input type="text" name="label" class="form-control form-control-sm"
                                   required placeholder="e.g. IP Camera">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Category Code <span class="text-danger">*</span></label>
                            <input type="text" name="category_code" class="form-control form-control-sm"
                                   pattern="[A-Za-z0-9]+" maxlength="5" required placeholder="e.g. CAM">
                            <div class="form-text">For asset code (SG-CAM-001)</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Sort Order <span class="text-danger">*</span></label>
                            <input type="number" name="sort_order" class="form-control form-control-sm"
                                   value="100" min="0" max="9999" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Icon <span class="text-danger">*</span></label>
                            <input type="text" name="icon" class="form-control form-control-sm"
                                   value="bi-cpu" required placeholder="bi-camera">
                            <div class="form-text"><a href="https://icons.getbootstrap.com/" target="_blank">Browse icons</a></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Badge Class <span class="text-danger">*</span></label>
                            <input type="text" name="badge_class" class="form-control form-control-sm"
                                   value="bg-secondary" required>
                            <div class="form-text">e.g. bg-primary, bg-success</div>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Group <span class="text-danger">*</span></label>
                            <select name="group" class="form-select form-select-sm" required>
                                <option value="infrastructure">Infrastructure</option>
                                <option value="user_equipment">User Equipment</option>
                                <option value="other" selected>Other</option>
                            </select>
                        </div>
                        <div class="col-6 d-flex align-items-end">
                            <div class="form-check">
                                <input type="hidden" name="is_user_equipment" value="0">
                                <input type="checkbox" name="is_user_equipment" value="1" class="form-check-input" id="addUE">
                                <label class="form-check-label small" for="addUE">Assignable to employees</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm">Add Type</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ── Edit Type Modal ── --}}
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editTypeForm">
                @csrf @method('PUT')
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit Asset Type</h6>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-2">
                        <span class="text-muted small">Slug:</span>
                        <strong id="editSlugDisplay" class="font-monospace"></strong>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Label <span class="text-danger">*</span></label>
                            <input type="text" name="label" id="editLabel" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Category Code <span class="text-danger">*</span></label>
                            <input type="text" name="category_code" id="editCatCode" class="form-control form-control-sm"
                                   pattern="[A-Za-z0-9]+" maxlength="5" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Icon <span class="text-danger">*</span></label>
                            <input type="text" name="icon" id="editIcon" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Badge Class <span class="text-danger">*</span></label>
                            <input type="text" name="badge_class" id="editBadge" class="form-control form-control-sm" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold small">Group <span class="text-danger">*</span></label>
                            <select name="group" id="editGroup" class="form-select form-select-sm" required>
                                <option value="infrastructure">Infrastructure</option>
                                <option value="user_equipment">User Equipment</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label fw-semibold small">Sort Order</label>
                            <input type="number" name="sort_order" id="editSort" class="form-control form-control-sm"
                                   min="0" max="9999" required>
                        </div>
                        <div class="col-3 d-flex align-items-end">
                            <div class="form-check">
                                <input type="hidden" name="is_user_equipment" value="0">
                                <input type="checkbox" name="is_user_equipment" value="1" class="form-check-input" id="editUE">
                                <label class="form-check-label small" for="editUE">Assignable</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

@push('scripts')
<script>
function openEditModal(type) {
    document.getElementById('editTypeForm').action = '{{ url("admin/settings/asset-types") }}/' + type.id;
    document.getElementById('editSlugDisplay').textContent = type.slug;
    document.getElementById('editLabel').value = type.label;
    document.getElementById('editCatCode').value = type.category_code;
    document.getElementById('editIcon').value = type.icon;
    document.getElementById('editBadge').value = type.badge_class;
    document.getElementById('editGroup').value = type.group;
    document.getElementById('editSort').value = type.sort_order;
    document.getElementById('editUE').checked = type.is_user_equipment;
    new bootstrap.Modal(document.getElementById('editTypeModal')).show();
}
</script>
@endpush

@endsection
