@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3-fill me-2 text-primary"></i>Workflow Templates</h4>
        <small class="text-muted">Configure approval chains for each workflow type</small>
    </div>
    @can('manage-workflow-templates')
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
        <i class="bi bi-plus-circle me-1"></i>Add Custom Type
    </button>
    @endcan
</div>


<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Type Slug</th>
                    <th>Display Name</th>
                    <th>Approval Chain</th>
                    <th>Trigger / Version</th>
                    <th class="text-center">System</th>
                    <th class="text-center">Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($templates as $tpl)
            <tr>
                <td><code class="small">{{ $tpl->type_slug }}</code></td>
                <td class="fw-semibold">
                    {{ $tpl->display_name }}
                    @if($tpl->definition)
                    <span class="badge bg-primary-subtle text-primary ms-1 small" title="Has visual definition">
                        <i class="bi bi-diagram-3"></i> Graph
                    </span>
                    @endif
                </td>
                <td>
                    @foreach($tpl->approval_chain ?? [] as $role)
                    <span class="badge bg-secondary me-1 text-capitalize">{{ str_replace('_', ' ', $role) }}</span>
                    @endforeach
                </td>
                <td>
                    @if($tpl->trigger_event)
                    <span class="badge bg-success"><i class="bi bi-lightning-fill me-1"></i>{{ $tpl->trigger_event }}</span>
                    @else
                    <span class="text-muted small">—</span>
                    @endif
                    <span class="badge bg-light text-dark border ms-1">v{{ $tpl->version ?? 1 }}</span>
                </td>
                <td class="text-center">
                    @if($tpl->is_system)
                    <i class="bi bi-lock-fill text-warning" title="System template — cannot be deleted"></i>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>
                <td class="text-center">
                    @if($tpl->is_active)
                    <span class="badge bg-success">Active</span>
                    @else
                    <span class="badge bg-secondary">Inactive</span>
                    @endif
                </td>
                <td class="text-end">
                    @can('manage-workflow-templates')
                    <a href="{{ route('admin.workflow-templates.builder', $tpl) }}"
                       class="btn btn-sm btn-outline-primary" title="Open Visual Builder">
                        <i class="bi bi-diagram-3"></i>
                    </a>
                    <button class="btn btn-outline-secondary btn-sm"
                            data-bs-toggle="modal"
                            data-bs-target="#editModal{{ $tpl->id }}"
                            title="Edit chain">
                        <i class="bi bi-pencil"></i>
                    </button>
                    @if(! $tpl->is_system)
                    <form method="POST" action="{{ route('admin.workflow-templates.destroy', $tpl->id) }}" class="d-inline"
                          onsubmit="return confirm('Delete this workflow template?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                    </form>
                    @endif
                    @endcan
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No workflow templates found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Edit Modals --}}
@can('manage-workflow-templates')
@foreach($templates as $tpl)
<div class="modal fade" id="editModal{{ $tpl->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.workflow-templates.update', $tpl->id) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit: {{ $tpl->display_name }}</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Display Name</label>
                        <input type="text" name="display_name" class="form-control" value="{{ $tpl->display_name }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" name="description" class="form-control" value="{{ $tpl->description }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Approval Chain</label>
                        <div class="d-flex flex-wrap gap-2">
                            @foreach(['hr' => 'HR', 'it_manager' => 'IT Manager', 'manager' => 'Manager', 'security' => 'Security', 'super_admin' => 'Super Admin'] as $roleVal => $roleLabel)
                            <div class="form-check me-2">
                                <input class="form-check-input" type="checkbox" name="approval_chain[]"
                                       id="chain_{{ $tpl->id }}_{{ $roleVal }}" value="{{ $roleVal }}"
                                       {{ in_array($roleVal, $tpl->approval_chain ?? []) ? 'checked' : '' }}>
                                <label class="form-check-label" for="chain_{{ $tpl->id }}_{{ $roleVal }}">{{ $roleLabel }}</label>
                            </div>
                            @endforeach
                        </div>
                        <small class="text-muted">Select all roles required in order of approval.</small>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               id="active_{{ $tpl->id }}" {{ $tpl->is_active ? 'checked' : '' }}>
                        <label class="form-check-label" for="active_{{ $tpl->id }}">Active</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- Add Custom Template Modal --}}
<div class="modal fade" id="addTemplateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.workflow-templates.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Custom Workflow Type</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type Slug <span class="text-danger">*</span></label>
                        <input type="text" name="type_slug" class="form-control" placeholder="e.g. vendor_approval" required
                               pattern="[a-z0-9_]+" title="Lowercase letters, numbers, and underscores only">
                        <small class="text-muted">Lowercase, underscores only. Must be unique.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Display Name <span class="text-danger">*</span></label>
                        <input type="text" name="display_name" class="form-control" placeholder="Vendor Approval" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <input type="text" name="description" class="form-control" placeholder="Optional description">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Approval Chain <span class="text-danger">*</span></label>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach(['hr' => 'HR', 'it_manager' => 'IT Manager', 'manager' => 'Manager', 'security' => 'Security', 'super_admin' => 'Super Admin'] as $roleVal => $roleLabel)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="approval_chain[]"
                                       id="new_chain_{{ $roleVal }}" value="{{ $roleVal }}">
                                <label class="form-check-label" for="new_chain_{{ $roleVal }}">{{ $roleLabel }}</label>
                            </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Create</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@endsection
