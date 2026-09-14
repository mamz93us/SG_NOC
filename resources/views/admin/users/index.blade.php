@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-people-fill me-2"></i>Users</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus me-1"></i> Add User
    </button>
</div>

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <strong><i class="bi bi-exclamation-triangle-fill me-1"></i> Could not save:</strong>
        <ul class="mb-0 mt-1">
            @foreach($errors->all() as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif


<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-ul me-2"></i>All Users</span>
        <span class="badge bg-secondary">{{ $users->count() }} total</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Last Login</th>
                    <th>Last Login IP</th>
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                @php
                    // From the role row, so a custom role gets a colour too —
                    // this used to be a match on the six hardcoded slugs.
                    $roleBadge = \App\Models\Role::badgeFor($user->role);
                @endphp
                <tr>
                    <td class="text-muted">{{ $loop->iteration }}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar-circle-sm {{ $roleBadge }}">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                            <strong>{{ $user->name }}</strong>
                            @if($user->hasTwoFactorEnabled())
                                <span class="badge bg-success-subtle text-success border border-success-subtle"
                                      title="Two-factor authentication enabled">
                                    <i class="bi bi-shield-lock-fill"></i> 2FA
                                </span>
                            @endif
                            @if($user->id === auth()->id())
                                <span class="badge bg-light text-dark border">You</span>
                            @endif
                        </div>
                    </td>
                    <td>
                        {{ $user->email }}
                        @if($user->whatsapp_number)
                            <div class="text-success small font-monospace" title="WhatsApp number">
                                <i class="bi bi-whatsapp"></i> +{{ $user->whatsapp_number }}
                            </div>
                        @endif
                    </td>
                    <td>
                        <span class="badge {{ $roleBadge }}">
                            {{ \App\Models\User::roleLabel($user->role) }}
                        </span>
                        @php $ur = $user->roleModel(); @endphp
                        @if($ur && $ur->usesPortal())
                            <div class="text-muted" style="font-size:10px" title="This role has no NOC Admin surface">
                                <i class="bi bi-window"></i> portal only
                            </div>
                        @elseif(! $ur)
                            <div class="text-danger" style="font-size:10px" title="users.role does not match any role row">
                                <i class="bi bi-exclamation-triangle"></i> unknown role
                            </div>
                        @endif
                    </td>
                    <td class="text-muted small">
                        @if($user->last_login_at)
                            <span title="{{ $user->last_login_at->format('d M Y H:i') }}">
                                {{ $user->last_login_at->diffForHumans() }}
                            </span>
                        @else
                            <span class="text-muted fst-italic">Never</span>
                        @endif
                    </td>
                    <td class="text-muted small">
                        @if($user->last_login_ip)
                            <code class="small">{{ $user->last_login_ip }}</code>
                        @else
                            <span class="text-muted fst-italic">—</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $user->created_at?->format('d M Y') ?? '—' }}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1"
                            data-bs-toggle="modal"
                            data-bs-target="#editUserModal{{ $user->id }}"
                            title="Edit user">
                            <i class="bi bi-pencil"></i>
                        </button>
                        @can('manage-permissions')
                            @if($user->isSuperAdmin())
                                <button class="btn btn-sm btn-outline-secondary me-1" disabled
                                    title="A superuser role holds every permission; exceptions do not apply">
                                    <i class="bi bi-shield-lock"></i>
                                </button>
                            @else
                                @php $exceptions = $user->permissions()->count(); @endphp
                                <a href="{{ route('admin.users.permissions.edit', $user) }}"
                                    class="btn btn-sm {{ $exceptions ? 'btn-warning' : 'btn-outline-info' }} me-1"
                                    title="{{ $exceptions ? $exceptions.' permission exception(s) on top of the role' : 'Add a permission exception for this person' }}">
                                    <i class="bi bi-shield-lock"></i>@if($exceptions) {{ $exceptions }}@endif
                                </a>
                            @endif
                        @endcan
                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                            class="d-inline"
                            onsubmit="return confirm('Delete {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger"
                                title="Delete user">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                        @endif
                    </td>
                </tr>

                {{-- Edit Modal --}}
                <div class="modal fade" id="editUserModal{{ $user->id }}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="POST" action="{{ route('admin.users.update', $user) }}">
                                @csrf @method('PUT')
                                <div class="modal-header bg-primary text-white">
                                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Edit User</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                                        <input type="text" name="name" class="form-control"
                                            value="{{ $user->name }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                                        <input type="email" name="email" class="form-control"
                                            value="{{ $user->email }}" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">
                                            <i class="bi bi-whatsapp text-success me-1"></i>WhatsApp Number
                                        </label>
                                        <input type="text" name="whatsapp_number" class="form-control font-monospace"
                                            value="{{ $user->whatsapp_number }}" placeholder="201001234567">
                                        <div class="form-text">
                                            Used by notification rules with the WhatsApp channel on. Digits only;
                                            include the country code, or set a default in Settings.
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                                        <select name="role" class="form-select" required>
                                            @foreach($roles as $r)
                                                {{-- Only a superuser can hand out a superuser role; the
                                                     controller enforces it too, this just doesn't offer it. --}}
                                                @continue($r->is_super && ! auth()->user()->isSuperAdmin())
                                                <option value="{{ $r->slug }}" {{ $user->role === $r->slug ? 'selected' : '' }}>
                                                    {{ $r->name }}@if($r->description) — {{ \Illuminate\Support\Str::limit($r->description, 60) }}@endif
                                                </option>
                                            @endforeach
                                        </select>
                                        @php
                                            $ur = $user->roleModel();
                                            $urSurfaces = $ur
                                                ? collect($ur->surfaceList())
                                                    ->map(fn ($s) => \App\Models\Role::SURFACES[$s] ?? $s)
                                                    ->join(', ')
                                                : null;
                                        @endphp
                                        @if($ur)
                                            <div class="form-text" style="font-size:11px">
                                                Currently signs in to:
                                                <strong>{{ $urSurfaces ?: 'nothing' }}</strong>.
                                                Lands on {{ \App\Models\Role::SURFACES[$ur->landing] ?? $ur->landing }}.
                                            </div>
                                        @endif
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">New Password
                                            <small class="text-muted fw-normal">(leave blank to keep current)</small>
                                        </label>
                                        <input type="password" name="password" class="form-control"
                                            placeholder="Min 8 characters">
                                    </div>

                                    <hr class="my-3">

                                    <div>
                                        <label class="form-label fw-semibold d-block mb-2">
                                            <i class="bi bi-shield-lock me-1"></i>Two-Factor Authentication
                                        </label>
                                        @if($user->hasTwoFactorEnabled())
                                            <div class="d-flex align-items-center justify-content-between p-2 border rounded bg-success-subtle">
                                                <div class="small">
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle-fill me-1"></i>Enabled
                                                    </span>
                                                    <span class="text-muted ms-2">
                                                        Confirmed {{ $user->two_factor_confirmed_at?->diffForHumans() }}
                                                    </span>
                                                </div>
                                            </div>
                                        @elseif($user->two_factor_secret)
                                            <div class="p-2 border rounded bg-warning-subtle small">
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-hourglass-split me-1"></i>Pending
                                                </span>
                                                <span class="text-muted ms-2">Secret generated but never confirmed.</span>
                                            </div>
                                        @else
                                            <div class="p-2 border rounded bg-light small text-muted">
                                                <i class="bi bi-x-circle me-1"></i>Not set up
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="modal-footer d-flex justify-content-between">
                                    <div>
                                        @if($user->id !== auth()->id() && ($user->hasTwoFactorEnabled() || $user->two_factor_secret))
                                            <button type="button" class="btn btn-outline-warning btn-sm"
                                                onclick="event.preventDefault(); if(confirm('Reset 2FA for {{ $user->name }}? They will need to re-enroll on next login.')) { document.getElementById('reset2faForm{{ $user->id }}').submit(); }">
                                                <i class="bi bi-shield-slash me-1"></i>Reset 2FA
                                            </button>
                                        @endif
                                    </div>
                                    <div>
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-save me-1"></i>Save Changes
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                @if($user->id !== auth()->id() && ($user->hasTwoFactorEnabled() || $user->two_factor_secret))
                <form id="reset2faForm{{ $user->id }}" method="POST"
                      action="{{ route('admin.users.reset-2fa', $user) }}" class="d-none">
                    @csrf
                </form>
                @endif
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Add User Modal --}}
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add New User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <ul class="nav nav-tabs px-3 pt-3" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="addEntraTab" data-bs-toggle="tab" data-bs-target="#addEntraPane"
                            type="button" role="tab" aria-controls="addEntraPane" aria-selected="true">
                        <i class="bi bi-microsoft me-1"></i>From Entra
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="addLocalTab" data-bs-toggle="tab" data-bs-target="#addLocalPane"
                            type="button" role="tab" aria-controls="addLocalPane" aria-selected="false">
                        <i class="bi bi-key me-1"></i>Local account
                    </button>
                </li>
            </ul>

            <div class="tab-content">

            {{-- ── From Entra: pick the employee, pick the role ── --}}
            <div class="tab-pane fade show active" id="addEntraPane" role="tabpanel" aria-labelledby="addEntraTab">
            <form method="POST" action="{{ route('admin.users.store') }}" id="entraUserForm">
                @csrf
                <input type="hidden" name="source" value="entra">
                <input type="hidden" name="employee_id" id="entraEmployeeId" value="">
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Pick the person and their role. Name and sign-in address come from their Entra account,
                        and they sign in with Microsoft — there is no password to set or send.
                    </p>

                    {{-- The modal re-opens after a failed submit, covering the page's own
                         error list, so the reason is repeated where it can be read. --}}
                    @if($errors->any() && old('source') === 'entra')
                        <div class="alert alert-danger small py-2">
                            @foreach($errors->all() as $err)
                                <div><i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $err }}</div>
                            @endforeach
                        </div>
                    @endif

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="entraSearch">Employee <span class="text-danger">*</span></label>
                        <div class="position-relative">
                            <input type="text" id="entraSearch" class="form-control" autocomplete="off"
                                   placeholder="Search by name, email or Oracle number…">
                            <div id="entraResults" class="list-group position-absolute w-100 shadow"
                                 style="z-index:1060; max-height:300px; overflow-y:auto; display:none;"></div>
                        </div>
                        <div class="form-text" id="entraSearchHelp">Only employees linked to an Entra account are listed.</div>

                        <div id="entraChosen" class="border rounded p-2 d-none">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-circle-sm bg-secondary" id="entraChosenInitial"></div>
                                <div class="small flex-grow-1 text-truncate">
                                    <div class="fw-semibold" id="entraChosenName"></div>
                                    <div class="text-muted text-truncate" id="entraChosenMeta"></div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="entraClear">Change</button>
                            </div>
                            <div id="entraChosenNote" class="small text-primary-emphasis bg-primary-subtle rounded px-2 py-1 mt-2 d-none"></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="entraRole">Role <span class="text-danger">*</span></label>
                        <select name="role" id="entraRole" class="form-select" required>
                            <option value="" disabled {{ old('source') === 'entra' && old('role') ? '' : 'selected' }}>— Choose a role —</option>
                            @foreach($roles as $r)
                                @continue($r->is_super && ! auth()->user()->isSuperAdmin())
                                <option value="{{ $r->slug }}" data-hint="{{ $signInHints[$r->slug] ?? '' }}"
                                    {{ old('source') === 'entra' && old('role') === $r->slug ? 'selected' : '' }}>
                                    {{ $r->name }}@if($r->description) — {{ \Illuminate\Support\Str::limit($r->description, 70) }}@endif
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text" id="entraRoleHint"></div>
                    </div>

                    <div class="mb-1">
                        <label class="form-label fw-semibold" for="entraWhatsapp">
                            <i class="bi bi-whatsapp text-success me-1"></i>WhatsApp Number
                        </label>
                        <input type="text" name="whatsapp_number" id="entraWhatsapp" class="form-control font-monospace"
                            value="{{ old('source') === 'entra' ? old('whatsapp_number') : '' }}" placeholder="201001234567">
                        <div class="form-text">Optional. Needed only to page this user over WhatsApp.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="entraSubmit" disabled>
                        <i class="bi bi-person-check me-1"></i>Add User
                    </button>
                </div>
            </form>
            </div>

            {{-- ── Local account: for anything that is not in Entra ── --}}
            <div class="tab-pane fade" id="addLocalPane" role="tabpanel" aria-labelledby="addLocalTab">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="modal-body">
                    <div class="alert alert-light border small py-2 mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        For a service or break-glass account that does not exist in Entra. It signs in with a
                        password and 2FA. For a real person, use <strong>From Entra</strong>.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="Full name">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="user@company.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-whatsapp text-success me-1"></i>WhatsApp Number
                        </label>
                        <input type="text" name="whatsapp_number" class="form-control font-monospace"
                            placeholder="201001234567">
                        <div class="form-text">Optional. Needed to page this user over WhatsApp.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            @foreach($roles as $r)
                                @continue($r->is_super && ! auth()->user()->isSuperAdmin())
                                <option value="{{ $r->slug }}" {{ old('role', 'viewer') === $r->slug ? 'selected' : '' }}>
                                    {{ $r->name }}
                                </option>
                            @endforeach
                        </select>
                        <div class="form-text">
                            @foreach($roles as $r)
                                @continue($r->is_super && ! auth()->user()->isSuperAdmin())
                                <div>
                                    <strong>{{ $r->name }}</strong>
                                    @if($r->description) – {{ $r->description }} @endif
                                    <span class="text-muted">
                                        ({{ collect($r->surfaceList())->map(fn ($s) => \App\Models\Role::SURFACES[$s] ?? $s)->join(', ') ?: 'no surfaces' }})
                                    </span>
                                </div>
                            @endforeach
                            @can('manage-roles')
                                <a href="{{ route('admin.roles.index') }}" class="d-inline-block mt-1">
                                    <i class="bi bi-gear"></i> Manage roles
                                </a>
                            @endcan
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required
                            placeholder="Min 12 chars, mixed case, number, symbol">
                        <div class="form-text">
                            At least 12 characters, must include upper &amp; lower case,
                            a number, a symbol, and must not appear in known breach lists.
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-person-plus me-1"></i>Create User
                    </button>
                </div>
            </form>
            </div>

            </div>{{-- /.tab-content --}}
        </div>
    </div>
</div>

@push('scripts')
<style>
.avatar-circle-sm {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; color: #fff;
    flex-shrink: 0;
}
</style>
<script>
// "Add user ▸ From Entra": search the employee directory, choose one person,
// pick a role. The server re-checks everything the list shows — who is
// selectable, and whether they already have an account.
(function () {
    const form = document.getElementById('entraUserForm');
    if (!form) return;

    const searchUrl = @json(route('admin.users.entra-search'));
    const input     = document.getElementById('entraSearch');
    const help      = document.getElementById('entraSearchHelp');
    const results   = document.getElementById('entraResults');
    const idField   = document.getElementById('entraEmployeeId');
    const chosen    = document.getElementById('entraChosen');
    const nameEl    = document.getElementById('entraChosenName');
    const metaEl    = document.getElementById('entraChosenMeta');
    const initialEl = document.getElementById('entraChosenInitial');
    const noteEl    = document.getElementById('entraChosenNote');
    const clearBtn  = document.getElementById('entraClear');
    const submit    = document.getElementById('entraSubmit');
    const roleSel   = document.getElementById('entraRole');
    const roleHint  = document.getElementById('entraRoleHint');

    let timer = null;
    let controller = null;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function meta(emp) {
        return [emp.email, emp.job_title, emp.branch, emp.emp_no ? '#' + emp.emp_no : null]
            .filter(Boolean).join(' · ');
    }

    function hideResults() {
        results.style.display = 'none';
        results.innerHTML = '';
    }

    function choose(emp) {
        idField.value = emp.id;
        nameEl.textContent = emp.name;
        metaEl.textContent = meta(emp);
        initialEl.textContent = (emp.name || '?').trim().charAt(0).toUpperCase();

        if (emp.user) {
            noteEl.innerHTML = '<i class="bi bi-info-circle me-1"></i>Already has an account as <strong>' +
                esc(emp.user.role_label) + '</strong>. Adding them changes the role on that account — ' +
                'no second account is created.';
            noteEl.classList.remove('d-none');
        } else {
            noteEl.classList.add('d-none');
        }

        input.classList.add('d-none');
        help.classList.add('d-none');
        chosen.classList.remove('d-none');
        submit.disabled = false;
        hideResults();
    }

    function clearChoice() {
        idField.value = '';
        chosen.classList.add('d-none');
        noteEl.classList.add('d-none');
        input.classList.remove('d-none');
        help.classList.remove('d-none');
        input.value = '';
        submit.disabled = true;
        input.focus();
    }

    input.addEventListener('input', function () {
        const q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { hideResults(); return; }

        timer = setTimeout(async () => {
            if (controller) controller.abort();
            controller = new AbortController();
            try {
                const res = await fetch(searchUrl + '?q=' + encodeURIComponent(q), {
                    headers: { 'Accept': 'application/json' },
                    signal: controller.signal,
                });
                if (!res.ok) return hideResults();
                const list = await res.json();

                results.innerHTML = '';
                if (!list.length) {
                    results.innerHTML = '<div class="list-group-item text-muted small">' +
                        'No employee with an Entra account matches.</div>';
                }
                list.forEach(emp => {
                    // Someone who cannot sign in is listed with the reason rather
                    // than hidden, so "why can't I find X" answers itself.
                    const item = document.createElement(emp.unavailable ? 'div' : 'button');
                    if (!emp.unavailable) item.type = 'button';
                    item.className = 'list-group-item' + (emp.unavailable ? ' bg-light' : ' list-group-item-action');
                    item.innerHTML =
                        '<div class="d-flex justify-content-between align-items-center gap-2">' +
                            '<span class="fw-semibold' + (emp.unavailable ? ' text-muted' : '') + '">' + esc(emp.name) + '</span>' +
                            (emp.user ? '<span class="badge bg-light text-dark border">Has account · ' + esc(emp.user.role_label) + '</span>' : '') +
                        '</div>' +
                        '<div class="small text-muted">' + esc(meta(emp)) + '</div>' +
                        (emp.unavailable ? '<div class="small text-danger"><i class="bi bi-slash-circle me-1"></i>' + esc(emp.unavailable) + '</div>' : '');
                    if (!emp.unavailable) item.addEventListener('click', () => choose(emp));
                    results.appendChild(item);
                });
                results.style.display = 'block';
            } catch (e) {
                if (e.name !== 'AbortError') hideResults();
            }
        }, 250);
    });

    clearBtn.addEventListener('click', clearChoice);

    document.addEventListener('click', function (e) {
        if (!results.contains(e.target) && e.target !== input) hideResults();
    });

    function syncRoleHint() {
        const opt = roleSel.selectedOptions[0];
        roleHint.textContent = opt && opt.value ? (opt.dataset.hint || '') : '';
    }
    roleSel.addEventListener('change', syncRoleHint);
    syncRoleHint();

    // Coming back from a failed submit: re-open the modal with the same person.
    const previous = @json($oldPick);
    if (previous && !previous.unavailable) choose(previous);

    @if($errors->any() && old('source') === 'entra')
        bootstrap.Modal.getOrCreateInstance(document.getElementById('addUserModal')).show();
    @endif
})();
</script>
@endpush

@endsection
