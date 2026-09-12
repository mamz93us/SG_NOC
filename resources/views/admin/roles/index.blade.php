@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-people-fill me-2 text-primary"></i>Roles</h4>
        <small class="text-muted">Who can sign in where, and what they can do once they are in</small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-permissions')
        <a href="{{ route('admin.permissions.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-grid-3x3-gap me-1"></i>Permission Matrix
        </a>
        @endcan
        <a href="{{ route('admin.roles.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Role
        </a>
    </div>
</div>

@if(!empty($unregistered))
    {{-- Grants in the database for slugs no screen can show. Surfacing this is
         the only way anyone finds out a subsystem shipped a route gate without
         registering the permission. --}}
    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div class="small">
            <strong>{{ count($unregistered) }} permission{{ count($unregistered) === 1 ? '' : 's' }} granted in the database
            {{ count($unregistered) === 1 ? 'is' : 'are' }} not in the registry</strong>, so
            {{ count($unregistered) === 1 ? 'it does' : 'they do' }} not appear on any screen and cannot be edited here.
            A route is gating {{ count($unregistered) === 1 ? 'it' : 'them' }} but nothing added
            {{ count($unregistered) === 1 ? 'it' : 'them' }} to <code>RolePermission::allPermissions()</code>.
            <div class="mt-1">
                @foreach($unregistered as $slug)
                    <code class="me-2">{{ $slug }}</code>
                @endforeach
            </div>
        </div>
    </div>
@endif

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-dark">
                <tr>
                    <th class="ps-3">Role</th>
                    <th>Signs in to</th>
                    <th class="text-center">Lands on</th>
                    <th class="text-center">Permissions</th>
                    <th class="text-center">Users</th>
                    <th class="text-end pe-3">&nbsp;</th>
                </tr>
            </thead>
            <tbody>
                @forelse($roles as $role)
                <tr>
                    <td class="ps-3">
                        <span class="badge {{ $role->badgeClass() }} px-2 py-1">{{ $role->name }}</span>
                        @if($role->is_super)
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1"
                                  title="Holds every permission implicitly, including ones added in future">
                                <i class="bi bi-key-fill"></i> Superuser
                            </span>
                        @endif
                        @if($role->is_system)
                            <span class="badge bg-light text-secondary border ms-1"
                                  title="Built in: the slug is referenced by code, so it cannot be renamed or deleted">
                                <i class="bi bi-lock-fill"></i> Built-in
                            </span>
                        @endif
                        <div><code class="text-muted" style="font-size:11px">{{ $role->slug }}</code></div>
                        @if($role->description)
                            <div class="small text-muted">{{ $role->description }}</div>
                        @endif
                    </td>
                    <td>
                        @forelse($role->surfaceList() as $surface)
                            <span class="badge bg-light text-dark border me-1 mb-1"
                                  title="{{ \App\Models\Role::SURFACE_HINTS[$surface] ?? '' }}">
                                {{ \App\Models\Role::SURFACES[$surface] ?? $surface }}
                            </span>
                        @empty
                            <span class="text-danger small">
                                <i class="bi bi-exclamation-circle"></i> no surfaces — cannot sign in
                            </span>
                        @endforelse
                    </td>
                    <td class="text-center small">
                        {{ \App\Models\Role::SURFACES[$role->landing] ?? $role->landing }}
                    </td>
                    <td class="text-center">
                        @if($role->is_super)
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle">all {{ $totalPermissions }}</span>
                        @else
                            @php $count = $permissionCounts[$role->slug] ?? 0; @endphp
                            <span class="badge {{ $count ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-light text-secondary border' }}">
                                {{ $count }} / {{ $totalPermissions }}
                            </span>
                        @endif
                    </td>
                    <td class="text-center">
                        @php $users = $userCounts[$role->slug] ?? 0; @endphp
                        @if($users)
                            <a href="{{ route('admin.users.index') }}" class="text-decoration-none">{{ $users }}</a>
                        @else
                            <span class="text-muted">0</span>
                        @endif
                    </td>
                    <td class="text-end pe-3">
                        <a href="{{ route('admin.roles.edit', $role) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        @unless($role->is_system)
                            <button type="button" class="btn btn-sm btn-outline-danger"
                                    onclick="if (confirm('Delete the role “{{ $role->name }}”? Its permission rows go with it.')) document.getElementById('del-{{ $role->id }}').submit();">
                                <i class="bi bi-trash"></i>
                            </button>
                            <form id="del-{{ $role->id }}" method="POST"
                                  action="{{ route('admin.roles.destroy', $role) }}" class="d-none">
                                @csrf @method('DELETE')
                            </form>
                        @endunless
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No roles yet. <a href="{{ route('admin.roles.create') }}">Create one</a>.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Signs in to</strong> is what the role may reach at all; <strong>Lands on</strong> is where sign-in
        sends them. A role without <em>NOC Admin</em> never sees the admin chrome.
        Built-in roles can be renamed and re-permissioned but not deleted — their slugs are referenced by code.
    </div>
</div>
@endsection
