@extends('layouts.admin')

@section('content')
@php
    $roleColor = match($user->role) {
        'super_admin'  => 'danger',
        'admin'        => 'primary',
        'hr'           => 'info',
        'viewer'       => 'secondary',
        'browser_user' => 'warning',
        'marketing'    => 'success',
        default        => 'secondary',
    };
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-shield-lock me-2 text-primary"></i>Custom Permissions
        </h4>
        <small class="text-muted">
            For <strong>{{ $user->name }}</strong> &lt;{{ $user->email }}&gt;
            &nbsp;<span class="badge bg-{{ $roleColor }}">{{ \App\Models\User::roleLabel($user->role) }}</span>
        </small>
    </div>
    <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Users
    </a>
</div>

@if($customMode)
    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div class="small">
            <strong>This user is on custom permissions.</strong>
            Only the boxes ticked below are granted — the {{ \App\Models\User::roleLabel($user->role) }}
            role is <em>ignored</em> for permission checks.
            Use <strong>Reset to role defaults</strong> below to clear the custom list.
        </div>
    </div>
@else
    <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3">
        <i class="bi bi-info-circle-fill mt-1"></i>
        <div class="small">
            <strong>This user currently uses the role default ({{ \App\Models\User::roleLabel($user->role) }}).</strong>
            Tick any box and save to switch to a custom permission list — only the boxes you tick will be granted.
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.users.permissions.update', $user) }}">
    @csrf @method('PUT')

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Tick a permission to grant it to this user.
                </small>
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="checkAllBtn">
                        <i class="bi bi-check2-square me-1"></i>Check all
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="uncheckAllBtn">
                        <i class="bi bi-square me-1"></i>Uncheck all
                    </button>
                </div>
            </div>

            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th class="ps-3" style="width:65%">Permission</th>
                            <th class="text-center" style="width:20%">Role Default</th>
                            <th class="text-center" style="width:15%">Grant</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($permissions as $category => $perms)
                        <tr class="table-light">
                            <td colspan="3" class="ps-3 py-2">
                                <span class="fw-bold text-uppercase small text-secondary">
                                    <i class="bi bi-folder2 me-1"></i>{{ $category }}
                                </span>
                            </td>
                        </tr>
                        @foreach($perms as $slug => $label)
                        @php
                            $roleHas   = in_array($slug, $roleGrants);
                            $isChecked = in_array($slug, $customSlugs);
                        @endphp
                        <tr>
                            <td class="ps-4">
                                <span class="small">{{ $label }}</span>
                                <br><code class="text-muted" style="font-size:11px">{{ $slug }}</code>
                            </td>
                            <td class="text-center">
                                @if($roleHas)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle"
                                          title="Role grants this by default">
                                        <i class="bi bi-check-circle-fill"></i> Granted
                                    </span>
                                @else
                                    <span class="text-muted" title="Role does not grant this">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <input type="checkbox"
                                       class="form-check-input fs-5 perm-check"
                                       name="permissions[]"
                                       value="{{ $slug }}"
                                       id="perm-{{ $slug }}"
                                       {{ $isChecked ? 'checked' : '' }}>
                            </td>
                        </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <div>
                @if($customMode)
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="event.preventDefault(); if (confirm('Clear all custom permissions for {{ $user->name }} and revert to the {{ \App\Models\User::roleLabel($user->role) }} role default?')) { document.getElementById('resetForm').submit(); }">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to role defaults
                    </button>
                @endif
            </div>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>Save Custom Permissions
            </button>
        </div>
    </div>
</form>

@if($customMode)
<form id="resetForm" method="POST" action="{{ route('admin.users.permissions.reset', $user) }}" class="d-none">
    @csrf @method('DELETE')
</form>
@endif

@push('scripts')
<script>
    document.getElementById('checkAllBtn')?.addEventListener('click', () => {
        document.querySelectorAll('.perm-check').forEach(cb => cb.checked = true);
    });
    document.getElementById('uncheckAllBtn')?.addEventListener('click', () => {
        document.querySelectorAll('.perm-check').forEach(cb => cb.checked = false);
    });
</script>
@endpush
@endsection
