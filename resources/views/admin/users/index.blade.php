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
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('admin.users.store') }}">
                @csrf
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-2"></i>Add New User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
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
@endpush

@endsection
