@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Role Permissions</h4>
        <small class="text-muted">Control what each role can see and do</small>
    </div>
</div>

{{-- Legend --}}
<div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div class="small">
        <strong>super_admin</strong> always retains <em>Manage Users</em> and <em>Manage Permissions</em> regardless of the checkboxes below.
        Changes take effect immediately for all new requests.
    </div>
</div>

<form method="POST" action="{{ route('admin.permissions.update') }}">
    @csrf @method('PUT')

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 align-middle">
                    <thead class="table-dark">
                        <tr>
                            <th style="width:40%" class="ps-3">Permission</th>
                            @foreach($roles as $role)
                            <th class="text-center" style="width:20%">
                                @php
                                    $labels = ['super_admin' => 'Super Admin', 'admin' => 'Admin', 'viewer' => 'Viewer', 'browser_user' => 'Browser User'];
                                    $colors = ['super_admin' => 'bg-danger', 'admin' => 'bg-primary', 'viewer' => 'bg-secondary', 'browser_user' => 'bg-warning text-dark'];
                                @endphp
                                <span class="badge {{ $colors[$role] ?? 'bg-secondary' }} px-3 py-2 fs-6">
                                    {{ $labels[$role] ?? $role }}
                                </span>
                            </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($permissions as $category => $perms)
                        {{-- Category header row --}}
                        <tr class="table-light">
                            <td colspan="{{ count($roles) + 1 }}" class="ps-3 py-2">
                                <span class="fw-bold text-uppercase small text-secondary">
                                    <i class="bi bi-folder2 me-1"></i>{{ $category }}
                                </span>
                            </td>
                        </tr>
                        @foreach($perms as $slug => $label)
                        @php
                            $isForcedForSuperAdmin = in_array($slug, ['manage-users', 'manage-permissions']);
                        @endphp
                        <tr>
                            <td class="ps-4">
                                <span class="small">{{ $label }}</span>
                                <br><code class="text-muted" style="font-size:11px">{{ $slug }}</code>
                            </td>
                            @foreach($roles as $role)
                            <td class="text-center">
                                @php
                                    $locked  = ($role === 'super_admin' && $isForcedForSuperAdmin);
                                    $checked = $matrix[$role][$slug] ?? false;
                                @endphp
                                @if($locked)
                                    {{-- Always on for super_admin --}}
                                    <input type="checkbox" class="form-check-input fs-5"
                                           checked disabled
                                           title="Always granted to Super Admin">
                                    <input type="hidden" name="permissions[{{ $role }}][{{ $slug }}]" value="1">
                                @else
                                    <input type="checkbox"
                                           class="form-check-input fs-5"
                                           name="permissions[{{ $role }}][{{ $slug }}]"
                                           value="1"
                                           {{ $checked ? 'checked' : '' }}>
                                @endif
                            </td>
                            @endforeach
                        </tr>
                        @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <i class="bi bi-lock-fill me-1"></i>Locked checkboxes cannot be removed.
            </small>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>Save Permissions
            </button>
        </div>
    </div>
</form>
@endsection
