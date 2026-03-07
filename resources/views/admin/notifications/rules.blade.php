@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-funnel-fill me-2 text-primary"></i>Notification Routing Rules</h4>
        <small class="text-muted">Configure who receives which notifications</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.email-log.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-envelope-check me-1"></i>Email Log
        </a>
        @can('manage-notification-rules')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addRuleModal">
            <i class="bi bi-plus-circle me-1"></i>Add Rule
        </button>
        @endcan
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Event Type</th>
                    <th>Recipient</th>
                    <th class="text-center">Email</th>
                    <th class="text-center">In-App</th>
                    <th class="text-center">Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rules as $rule)
            <tr>
                <td><span class="badge bg-info text-dark">{{ $eventTypes[$rule->event_type] ?? $rule->event_type }}</span></td>
                <td>
                    @if($rule->recipient_type === 'role')
                    <i class="bi bi-people-fill me-1 text-muted"></i>
                    <span class="text-capitalize">{{ str_replace('_', ' ', $rule->recipient_role) }}</span>
                    @else
                    <i class="bi bi-person-fill me-1 text-muted"></i>
                    {{ $rule->recipientUser?->name ?? 'Unknown User' }}
                    @endif
                </td>
                <td class="text-center">
                    @if($rule->send_email)
                    <i class="bi bi-check-circle-fill text-success"></i>
                    @else
                    <i class="bi bi-x-circle text-muted"></i>
                    @endif
                </td>
                <td class="text-center">
                    @if($rule->send_in_app)
                    <i class="bi bi-check-circle-fill text-success"></i>
                    @else
                    <i class="bi bi-x-circle text-muted"></i>
                    @endif
                </td>
                <td class="text-center">
                    @if($rule->is_active)
                    <span class="badge bg-success">Active</span>
                    @else
                    <span class="badge bg-secondary">Off</span>
                    @endif
                </td>
                <td class="text-end">
                    @can('manage-notification-rules')
                    <button class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#editRule{{ $rule->id }}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" action="{{ route('admin.notification-rules.destroy', $rule->id) }}" class="d-inline"
                          onsubmit="return confirm('Delete this rule?')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash"></i></button>
                    </form>
                    @endcan
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No notification rules configured yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('manage-notification-rules')
{{-- Edit modals --}}
@foreach($rules as $rule)
<div class="modal fade" id="editRule{{ $rule->id }}" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.notification-rules.update', $rule->id) }}">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Event Type</label>
                        <select name="event_type" class="form-select">
                            @foreach($eventTypes as $val => $label)
                            <option value="{{ $val }}" {{ $rule->event_type === $val ? 'selected' : '' }}>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Recipient Type</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="recipient_type" value="role"
                                       id="rtype_role_{{ $rule->id }}" {{ $rule->recipient_type === 'role' ? 'checked' : '' }}
                                       onchange="toggleRecipient({{ $rule->id }}, 'role')">
                                <label class="form-check-label" for="rtype_role_{{ $rule->id }}">Role</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="recipient_type" value="user"
                                       id="rtype_user_{{ $rule->id }}" {{ $rule->recipient_type === 'user' ? 'checked' : '' }}
                                       onchange="toggleRecipient({{ $rule->id }}, 'user')">
                                <label class="form-check-label" for="rtype_user_{{ $rule->id }}">Specific User</label>
                            </div>
                        </div>
                    </div>
                    <div id="role_field_{{ $rule->id }}" class="{{ $rule->recipient_type === 'user' ? 'd-none' : '' }} mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="recipient_role" class="form-select">
                            <option value="super_admin" {{ $rule->recipient_role === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                            <option value="admin" {{ $rule->recipient_role === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="viewer" {{ $rule->recipient_role === 'viewer' ? 'selected' : '' }}>Viewer</option>
                        </select>
                    </div>
                    <div id="user_field_{{ $rule->id }}" class="{{ $rule->recipient_type === 'role' ? 'd-none' : '' }} mb-3">
                        <label class="form-label fw-semibold">User</label>
                        <select name="recipient_user_id" class="form-select">
                            <option value="">— Select User —</option>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}" {{ $rule->recipient_user_id == $u->id ? 'selected' : '' }}>
                                {{ $u->name }} ({{ $u->email }})
                            </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_email" value="1" {{ $rule->send_email ? 'checked' : '' }}>
                                <label class="form-check-label">Send Email</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_in_app" value="1" {{ $rule->send_in_app ? 'checked' : '' }}>
                                <label class="form-check-label">In-App</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $rule->is_active ? 'checked' : '' }}>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endforeach

{{-- Add Rule Modal --}}
<div class="modal fade" id="addRuleModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.notification-rules.store') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Notification Rule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Event Type <span class="text-danger">*</span></label>
                        <select name="event_type" class="form-select" required>
                            @foreach($eventTypes as $val => $label)
                            <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Recipient Type</label>
                        <div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="recipient_type" value="role"
                                       id="new_rtype_role" checked onchange="toggleRecipient('new', 'role')">
                                <label class="form-check-label" for="new_rtype_role">Role</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="radio" name="recipient_type" value="user"
                                       id="new_rtype_user" onchange="toggleRecipient('new', 'user')">
                                <label class="form-check-label" for="new_rtype_user">Specific User</label>
                            </div>
                        </div>
                    </div>
                    <div id="role_field_new" class="mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="recipient_role" class="form-select">
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="viewer">Viewer</option>
                        </select>
                    </div>
                    <div id="user_field_new" class="d-none mb-3">
                        <label class="form-label fw-semibold">User</label>
                        <select name="recipient_user_id" class="form-select">
                            <option value="">— Select User —</option>
                            @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_email" value="1" checked>
                                <label class="form-check-label">Send Email</label>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_in_app" value="1" checked>
                                <label class="form-check-label">In-App</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus me-1"></i>Add Rule</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endcan

@push('scripts')
<script>
function toggleRecipient(id, type) {
    document.getElementById('role_field_' + id).classList.toggle('d-none', type === 'user');
    document.getElementById('user_field_' + id).classList.toggle('d-none', type === 'role');
}
</script>
@endpush
@endsection
