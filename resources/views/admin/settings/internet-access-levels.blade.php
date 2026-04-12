@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0 fw-bold">
        <i class="bi bi-shield-lock-fill me-2 text-primary"></i>Internet Access Levels
    </h4>
    @can('manage-settings')
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addLevelModal">
        <i class="bi bi-plus-lg me-1"></i>Add Level
    </button>
    @endcan
</div>

<div class="alert alert-info small py-2 mb-3">
    <i class="bi bi-info-circle me-1"></i>
    Each level appears as a choice in the <strong>Manager Onboarding Form</strong>.
    When a level is selected, the new user is automatically added to the mapped Azure AD security group during provisioning.
    Mark one level as <strong>Default</strong> to pre-select it on the form.
</div>

@if($azureError)
<div class="alert alert-warning small py-2 mb-3">
    <i class="bi bi-exclamation-triangle me-1"></i>
    Could not reach Azure AD to list groups: <em>{{ $azureError }}</em>.
    You can still enter the group Object ID manually.
</div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($levels->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-shield-lock display-4 d-block mb-2"></i>
            No access levels defined yet. Add one to get started.
        </div>
        @else
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:40px" class="text-center">Sort</th>
                    <th>Label</th>
                    <th>Description</th>
                    <th>Azure AD Group</th>
                    <th class="text-center" style="width:90px">Default</th>
                    @can('manage-settings')
                    <th class="text-end" style="width:110px">Actions</th>
                    @endcan
                </tr>
            </thead>
            <tbody>
                @foreach($levels as $level)
                <tr>
                    <td class="text-center text-muted small">{{ $level->sort_order }}</td>
                    <td class="fw-semibold">{{ $level->label }}</td>
                    <td class="text-muted small">{{ $level->description ?: '—' }}</td>
                    <td>
                        @if($level->azure_group_id)
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                                <i class="bi bi-microsoft me-1"></i>{{ $level->azure_group_name ?: $level->azure_group_id }}
                            </span>
                            <div class="text-muted" style="font-size:0.7rem">{{ $level->azure_group_id }}</div>
                        @else
                            <span class="text-muted fst-italic">No group mapped</span>
                        @endif
                    </td>
                    <td class="text-center">
                        @if($level->is_default)
                            <span class="badge bg-success"><i class="bi bi-check-lg me-1"></i>Default</span>
                        @else
                            <span class="text-muted small">—</span>
                        @endif
                    </td>
                    @can('manage-settings')
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-secondary"
                            onclick="openEditModal(
                                {{ $level->id }},
                                '{{ addslashes($level->label) }}',
                                '{{ addslashes($level->description ?? '') }}',
                                '{{ addslashes($level->azure_group_id ?? '') }}',
                                '{{ addslashes($level->azure_group_name ?? '') }}',
                                {{ $level->is_default ? 'true' : 'false' }},
                                {{ $level->sort_order }}
                            )">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST"
                              action="{{ route('admin.settings.internet-access-levels.destroy', $level) }}"
                              class="d-inline"
                              onsubmit="return confirm('Delete access level «{{ addslashes($level->label) }}»?')">
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

{{-- ── Add Modal ─────────────────────────────────────────────────── --}}
<div class="modal fade" id="addLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('admin.settings.internet-access-levels.store') }}" class="modal-content">
            @csrf
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Internet Access Level</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Label <span class="text-danger">*</span></label>
                    <input type="text" name="label" class="form-control form-control-sm"
                           required maxlength="100" placeholder="e.g. Full Internet, Social Media Blocked, Browse Only">
                </div>

                <div class="mb-3">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" class="form-control form-control-sm" maxlength="255"
                           placeholder="Short description shown to manager">
                </div>

                <hr class="my-2">
                <p class="small fw-semibold mb-2"><i class="bi bi-microsoft me-1 text-primary"></i>Azure AD Security Group</p>

                @if(!empty($azureGroups))
                <div class="mb-2">
                    <label class="form-label small">Select from Azure AD</label>
                    <select class="form-select form-select-sm" id="addGroupSelect"
                            onchange="syncGroupFields('add', this)">
                        <option value="">— Choose a group (optional) —</option>
                        @foreach($azureGroups as $g)
                        <option value="{{ $g['id'] }}" data-name="{{ $g['displayName'] }}">{{ $g['displayName'] }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-muted small mb-2">Or enter manually:</p>
                @endif

                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small">Group Object ID</label>
                        <input type="text" name="azure_group_id" id="addGroupId"
                               class="form-control form-control-sm font-monospace"
                               maxlength="100" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>
                    <div class="col-5">
                        <label class="form-label small">Group Name (display)</label>
                        <input type="text" name="azure_group_name" id="addGroupName"
                               class="form-control form-control-sm"
                               maxlength="255" placeholder="Auto-filled">
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small">Sort Order</label>
                        <input type="number" name="sort_order" class="form-control form-control-sm" value="0" min="0">
                    </div>
                    <div class="col-6 d-flex align-items-end">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="is_default" value="1" id="addIsDefault">
                            <label class="form-check-label small" for="addIsDefault">Set as default</label>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Add Level</button>
            </div>
        </form>
    </div>
</div>

{{-- ── Edit Modal ─────────────────────────────────────────────────── --}}
<div class="modal fade" id="editLevelModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" id="editLevelForm" class="modal-content">
            @csrf @method('PUT')
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold"><i class="bi bi-pencil me-1"></i>Edit Internet Access Level</h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">

                <div class="mb-3">
                    <label class="form-label fw-semibold small">Label <span class="text-danger">*</span></label>
                    <input type="text" name="label" id="editLabel" class="form-control form-control-sm"
                           required maxlength="100">
                </div>

                <div class="mb-3">
                    <label class="form-label small">Description</label>
                    <input type="text" name="description" id="editDescription"
                           class="form-control form-control-sm" maxlength="255">
                </div>

                <hr class="my-2">
                <p class="small fw-semibold mb-2"><i class="bi bi-microsoft me-1 text-primary"></i>Azure AD Security Group</p>

                @if(!empty($azureGroups))
                <div class="mb-2">
                    <label class="form-label small">Select from Azure AD</label>
                    <select class="form-select form-select-sm" id="editGroupSelect"
                            onchange="syncGroupFields('edit', this)">
                        <option value="">— Choose a group (optional) —</option>
                        @foreach($azureGroups as $g)
                        <option value="{{ $g['id'] }}" data-name="{{ $g['displayName'] }}">{{ $g['displayName'] }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-muted small mb-2">Or edit manually:</p>
                @endif

                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label small">Group Object ID</label>
                        <input type="text" name="azure_group_id" id="editGroupId"
                               class="form-control form-control-sm font-monospace"
                               maxlength="100" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx">
                    </div>
                    <div class="col-5">
                        <label class="form-label small">Group Name (display)</label>
                        <input type="text" name="azure_group_name" id="editGroupName"
                               class="form-control form-control-sm" maxlength="255">
                    </div>
                </div>

                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small">Sort Order</label>
                        <input type="number" name="sort_order" id="editSortOrder"
                               class="form-control form-control-sm" min="0">
                    </div>
                    <div class="col-6 d-flex align-items-end">
                        <div class="form-check mb-1">
                            <input class="form-check-input" type="checkbox" name="is_default"
                                   value="1" id="editIsDefault">
                            <label class="form-check-label small" for="editIsDefault">Set as default</label>
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

@endcan

@push('scripts')
<script>
function syncGroupFields(prefix, select) {
    const opt = select.selectedOptions[0];
    document.getElementById(prefix + 'GroupId').value   = opt.value || '';
    document.getElementById(prefix + 'GroupName').value = opt.dataset.name || '';
}

function openEditModal(id, label, description, groupId, groupName, isDefault, sortOrder) {
    const base = '{{ url("admin/settings/internet-access-levels") }}';
    document.getElementById('editLevelForm').action = base + '/' + id;
    document.getElementById('editLabel').value       = label;
    document.getElementById('editDescription').value = description;
    document.getElementById('editGroupId').value     = groupId;
    document.getElementById('editGroupName').value   = groupName;
    document.getElementById('editSortOrder').value   = sortOrder;
    document.getElementById('editIsDefault').checked = isDefault;

    // Sync the dropdown if it exists
    const sel = document.getElementById('editGroupSelect');
    if (sel) {
        sel.value = groupId;
    }

    new bootstrap.Modal(document.getElementById('editLevelModal')).show();
}
</script>
@endpush

@endsection
