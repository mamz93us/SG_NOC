@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-people-fill me-2"></i>Users</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus me-1"></i> Add User
    </button>
</div>


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
                    <th>Created</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($users as $user)
                @php
                    $roleColor = match($user->role) {
                        'super_admin' => 'danger',
                        'admin'       => 'primary',
                        'viewer'      => 'secondary',
                        default       => 'secondary',
                    };
                @endphp
                <tr>
                    <td class="text-muted">{{ $loop->iteration }}</td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="avatar-circle-sm bg-{{ $roleColor }}">
                                {{ strtoupper(substr($user->name, 0, 1)) }}
                            </div>
                            <strong>{{ $user->name }}</strong>
                            @if($user->id === auth()->id())
                                <span class="badge bg-light text-dark border">You</span>
                            @endif
                        </div>
                    </td>
                    <td>{{ $user->email }}</td>
                    <td>
                        <span class="badge bg-{{ $roleColor }}">
                            {{ \App\Models\User::roleLabel($user->role) }}
                        </span>
                    </td>
                    <td class="text-muted small">{{ $user->created_at?->format('d M Y') ?? '—' }}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-outline-primary me-1"
                            data-bs-toggle="modal"
                            data-bs-target="#editUserModal{{ $user->id }}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        @if($user->id !== auth()->id())
                        <form method="POST" action="{{ route('admin.users.destroy', $user) }}"
                            class="d-inline"
                            onsubmit="return confirm('Delete {{ $user->name }}?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">
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
                                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                                        <select name="role" class="form-select" required>
                                            <option value="super_admin" {{ $user->role === 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                                            <option value="admin"       {{ $user->role === 'admin'       ? 'selected' : '' }}>Admin</option>
                                            <option value="viewer"      {{ $user->role === 'viewer'      ? 'selected' : '' }}>Viewer</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-semibold">New Password
                                            <small class="text-muted fw-normal">(leave blank to keep current)</small>
                                        </label>
                                        <input type="password" name="password" class="form-control"
                                            placeholder="Min 8 characters">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i>Save Changes
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
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
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            <option value="viewer" selected>Viewer (read-only)</option>
                            <option value="admin">Admin</option>
                            <option value="super_admin">Super Admin</option>
                        </select>
                        <div class="form-text">
                            <strong>Viewer</strong> – read-only &nbsp;|&nbsp;
                            <strong>Admin</strong> – full access &nbsp;|&nbsp;
                            <strong>Super Admin</strong> – + user management
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required placeholder="Min 8 characters">
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
