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

{{-- Legend --}}
<div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div class="small">
        <strong>Default</strong> uses the role baseline.
        <strong>Grant</strong> adds the permission on top of the role.
        <strong>Deny</strong> revokes it for this user even if the role grants it.
        <em>Deny beats grant beats role default.</em>
    </div>
</div>

<form method="POST" action="{{ route('admin.users.permissions.update', $user) }}">
    @csrf @method('PUT')

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:55%" class="ps-3">Permission</th>
                            <th class="text-center" style="width:15%">Role Baseline</th>
                            <th class="text-center" style="width:30%">Override</th>
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
                            $roleHas = in_array($slug, $roleGrants);
                            $state   = $overrides[$slug] ?? 'default';
                        @endphp
                        <tr>
                            <td class="ps-4">
                                <span class="small">{{ $label }}</span>
                                <br><code class="text-muted" style="font-size:11px">{{ $slug }}</code>
                            </td>
                            <td class="text-center">
                                @if($roleHas)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle"
                                          title="Role grants this permission">
                                        <i class="bi bi-check-circle-fill"></i> Granted
                                    </span>
                                @else
                                    <span class="text-muted" title="Role does not grant this permission">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="btn-group btn-group-sm" role="group" aria-label="Override for {{ $slug }}">
                                    <input type="radio" class="btn-check"
                                           name="overrides[{{ $slug }}]"
                                           id="ov-{{ $slug }}-default"
                                           value="default"
                                           {{ $state === 'default' ? 'checked' : '' }}>
                                    <label class="btn btn-outline-secondary" for="ov-{{ $slug }}-default">Default</label>

                                    <input type="radio" class="btn-check"
                                           name="overrides[{{ $slug }}]"
                                           id="ov-{{ $slug }}-grant"
                                           value="grant"
                                           {{ $state === 'grant' ? 'checked' : '' }}>
                                    <label class="btn btn-outline-success" for="ov-{{ $slug }}-grant">Grant</label>

                                    <input type="radio" class="btn-check"
                                           name="overrides[{{ $slug }}]"
                                           id="ov-{{ $slug }}-deny"
                                           value="deny"
                                           {{ $state === 'deny' ? 'checked' : '' }}>
                                    <label class="btn btn-outline-danger" for="ov-{{ $slug }}-deny">Deny</label>
                                </div>
                            </td>
                        </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <i class="bi bi-info-circle me-1"></i>Changes take effect immediately for the user's next request.
            </small>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>Save Custom Permissions
            </button>
        </div>
    </div>
</form>
@endsection
