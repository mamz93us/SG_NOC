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
        @can('view-whatsapp-logs')
        <a href="{{ route('admin.whatsapp-log.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-whatsapp me-1"></i>WhatsApp Log
        </a>
        @endcan
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
@if($errors->any())
<div class="alert alert-danger alert-dismissible fade show">
    <i class="bi bi-exclamation-circle me-2"></i>
    <ul class="mb-0 ps-3">
        @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if(!$whatsappReady && $rules->contains('send_whatsapp', true))
<div class="alert alert-warning">
    <i class="bi bi-whatsapp me-2"></i>
    Rules below have WhatsApp switched on, but nothing is being sent: {{ $whatsappIssue }}
    Fix it in <a href="{{ route('admin.settings.index') }}#whatsapp" class="alert-link">Settings &rarr; WhatsApp</a>.
</div>
@endif

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Event Type</th>
                    <th>Recipients</th>
                    <th class="text-center">Email</th>
                    <th class="text-center">In-App</th>
                    <th class="text-center">WhatsApp</th>
                    <th class="text-center" title="Minutes between repeat sends. 0 = send once.">Repeat</th>
                    <th class="text-center">Active</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($rules as $rule)
            @php
                // Pre-pivot rules still carry their recipient in the legacy
                // column; show that rather than an empty cell.
                $ruleUsers = $rule->recipients->isNotEmpty()
                    ? $rule->recipients
                    : collect(array_filter([$rule->recipientUser]));
                $extraNumbers = $rule->whatsappNumberList();
            @endphp
            <tr>
                <td><span class="badge bg-info text-dark">{{ $eventTypes[$rule->event_type] ?? $rule->event_type }}</span></td>
                <td>
                    @if($rule->recipient_type === 'role')
                    <i class="bi bi-people-fill me-1 text-muted"></i>
                    <span class="text-capitalize">{{ str_replace('_', ' ', $rule->recipient_role) }}</span>
                    @elseif($ruleUsers->isEmpty())
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                        <i class="bi bi-exclamation-triangle me-1"></i>No recipient
                    </span>
                    @else
                    <i class="bi bi-person-fill me-1 text-muted"></i>
                    @foreach($ruleUsers->take(3) as $u)
                    <span class="badge bg-light text-dark border">{{ $u->name }}</span>
                    @endforeach
                    @if($ruleUsers->count() > 3)
                    <span class="badge bg-secondary" title="{{ $ruleUsers->pluck('name')->implode(', ') }}">
                        +{{ $ruleUsers->count() - 3 }} more
                    </span>
                    @endif
                    @endif

                    @if($extraNumbers)
                    <span class="badge bg-success-subtle text-success border border-success-subtle"
                          title="{{ implode(', ', $extraNumbers) }}">
                        <i class="bi bi-whatsapp me-1"></i>{{ count($extraNumbers) }} extra number{{ count($extraNumbers) === 1 ? '' : 's' }}
                    </span>
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
                    @if($rule->send_whatsapp)
                    <i class="bi bi-whatsapp text-success" title="{{ $whatsappReady ? 'WhatsApp enabled' : 'Enabled on the rule, but WhatsApp is not configured' }}"></i>
                    @if(!$whatsappReady)
                    <i class="bi bi-exclamation-triangle-fill text-warning ms-1" title="WhatsApp is not configured — these sends are skipped"></i>
                    @endif
                    @else
                    <i class="bi bi-x-circle text-muted"></i>
                    @endif
                </td>
                <td class="text-center">
                    @if($rule->cooldown_minutes > 0)
                    <span class="badge bg-light text-dark" title="Re-send no more than once every {{ $rule->cooldown_minutes }} min">
                        {{ $rule->cooldown_minutes }} min
                    </span>
                    @else
                    <span class="badge bg-light text-muted" title="Send once — no auto-repeat">once</span>
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
            <tr><td colspan="8" class="text-center text-muted py-4">No notification rules configured yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@can('manage-notification-rules')
{{-- Edit modals --}}
@foreach($rules as $rule)
@php
    $selectedIds = $rule->recipients->isNotEmpty()
        ? $rule->recipients->pluck('id')->all()
        : array_filter([$rule->recipient_user_id]);
@endphp
<div class="modal fade" id="editRule{{ $rule->id }}" tabindex="-1">
    <div class="modal-dialog modal-lg">
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
                            @foreach($eventGroups as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach($items as $val => $label)
                                <option value="{{ $val }}" {{ $rule->event_type === $val ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </optgroup>
                            @endforeach
                            {{-- Preserve unknown / legacy event_type values so editing a rule doesn't silently change its target. --}}
                            @if(!array_key_exists($rule->event_type, $eventTypes))
                            <optgroup label="Unknown / legacy">
                                <option value="{{ $rule->event_type }}" selected>{{ $rule->event_type }} (unregistered)</option>
                            </optgroup>
                            @endif
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
                                <label class="form-check-label" for="rtype_user_{{ $rule->id }}">Specific Users</label>
                            </div>
                        </div>
                    </div>
                    <div id="role_field_{{ $rule->id }}" class="{{ $rule->recipient_type === 'user' ? 'd-none' : '' }} mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="recipient_role" class="form-select">
                            <option value="super_admin" {{ $rule->recipient_role === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                            <option value="admin" {{ $rule->recipient_role === 'admin' ? 'selected' : '' }}>Admin</option>
                            <option value="hr" {{ $rule->recipient_role === 'hr' ? 'selected' : '' }}>HR</option>
                            <option value="viewer" {{ $rule->recipient_role === 'viewer' ? 'selected' : '' }}>Viewer</option>
                        </select>
                    </div>
                    <div id="user_field_{{ $rule->id }}" class="{{ $rule->recipient_type === 'role' ? 'd-none' : '' }} mb-3">
                        @include('admin.notifications.partials.recipient-picker', [
                            'uid'         => $rule->id,
                            'users'       => $users,
                            'selectedIds' => $selectedIds,
                        ])
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_email" value="1" {{ $rule->send_email ? 'checked' : '' }}>
                                <label class="form-check-label">Send Email</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_in_app" value="1" {{ $rule->send_in_app ? 'checked' : '' }}>
                                <label class="form-check-label">In-App</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_whatsapp" value="1" {{ $rule->send_whatsapp ? 'checked' : '' }}>
                                <label class="form-check-label">WhatsApp</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $rule->is_active ? 'checked' : '' }}>
                                <label class="form-check-label">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Extra WhatsApp numbers</label>
                        <textarea name="whatsapp_numbers" class="form-control font-monospace" rows="2"
                                  placeholder="201001234567, 201007654321">{{ $rule->whatsapp_numbers }}</textarea>
                        <div class="form-text">
                            On-call or group numbers that are not NOC users. Comma or newline separated, digits only.
                            Users selected above are messaged on the number saved on their profile.
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Repeat interval (minutes)</label>
                        <input type="number" name="cooldown_minutes" class="form-control"
                               min="0" max="43200" step="1"
                               value="{{ $rule->cooldown_minutes ?? 0 }}">
                        <div class="form-text">0 = send once, no auto-repeat. Manual Resend always works.</div>
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
    <div class="modal-dialog modal-lg">
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
                            @foreach($eventGroups as $group => $items)
                            <optgroup label="{{ $group }}">
                                @foreach($items as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                                @endforeach
                            </optgroup>
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
                                <label class="form-check-label" for="new_rtype_user">Specific Users</label>
                            </div>
                        </div>
                    </div>
                    <div id="role_field_new" class="mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="recipient_role" class="form-select">
                            <option value="super_admin">Super Admin</option>
                            <option value="admin">Admin</option>
                            <option value="hr">HR</option>
                            <option value="viewer">Viewer</option>
                        </select>
                    </div>
                    <div id="user_field_new" class="d-none mb-3">
                        @include('admin.notifications.partials.recipient-picker', [
                            'uid'         => 'new',
                            'users'       => $users,
                            'selectedIds' => [],
                        ])
                    </div>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_email" value="1" checked>
                                <label class="form-check-label">Send Email</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_in_app" value="1" checked>
                                <label class="form-check-label">In-App</label>
                            </div>
                        </div>
                        <div class="col-6 col-md-4">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="send_whatsapp" value="1">
                                <label class="form-check-label">WhatsApp</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Extra WhatsApp numbers</label>
                        <textarea name="whatsapp_numbers" class="form-control font-monospace" rows="2"
                                  placeholder="201001234567, 201007654321"></textarea>
                        <div class="form-text">
                            On-call or group numbers that are not NOC users. Comma or newline separated, digits only.
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label fw-semibold">Repeat interval (minutes)</label>
                        <input type="number" name="cooldown_minutes" class="form-control"
                               min="0" max="43200" step="1" value="0">
                        <div class="form-text">0 = send once, no auto-repeat. Manual Resend always works.</div>
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

// Recipient picker: filter box, select-all/none, live count.
function filterRecipients(id) {
    const term = document.getElementById('recipient_search_' + id).value.toLowerCase();
    document.querySelectorAll('#recipient_list_' + id + ' .recipient-option').forEach(function (row) {
        row.classList.toggle('d-none', term !== '' && !row.dataset.search.includes(term));
    });
}

function setAllRecipients(id, checked) {
    document.querySelectorAll('#recipient_list_' + id + ' .recipient-option:not(.d-none) input[type=checkbox]')
        .forEach(function (cb) { cb.checked = checked; });
    countRecipients(id);
}

function countRecipients(id) {
    const n = document.querySelectorAll('#recipient_list_' + id + ' input[type=checkbox]:checked').length;
    const badge = document.getElementById('recipient_count_' + id);
    if (badge) {
        badge.textContent = n + ' selected';
        badge.className = 'badge ' + (n ? 'bg-primary' : 'bg-secondary');
    }
}

document.addEventListener('change', function (e) {
    if (e.target.matches('.recipient-option input[type=checkbox]')) {
        countRecipients(e.target.closest('[data-picker-id]').dataset.pickerId);
    }
});
</script>
@endpush
@endsection
