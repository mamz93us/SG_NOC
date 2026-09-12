@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock me-2 text-primary"></i>Role Permissions</h4>
        <small class="text-muted">Every role, every permission, one grid</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <input type="search" id="permFilter" class="form-control form-control-sm"
               placeholder="Filter permissions…" style="width:220px">
        @can('manage-roles')
        <a href="{{ route('admin.roles.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-people me-1"></i>Manage Roles
        </a>
        @endcan
    </div>
</div>

{{-- Legend --}}
<div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-3">
    <i class="bi bi-info-circle-fill mt-1"></i>
    <div class="small">
        A <strong>superuser</strong> role holds every permission implicitly, so its column is shown ticked and
        read-only — its rows are never consulted. Changes take effect on the next request.
        Per-person exceptions live on each user, not here.
        @can('manage-users')
            <a href="{{ route('admin.users.index') }}">Open Users</a>
        @endcan
    </div>
</div>

@if(!empty($unregistered))
    <div class="alert alert-warning d-flex align-items-start gap-2 py-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div class="small">
            <strong>{{ count($unregistered) }} permission{{ count($unregistered) === 1 ? '' : 's' }} granted in the
            database {{ count($unregistered) === 1 ? 'is' : 'are' }} missing from the registry</strong> and so
            {{ count($unregistered) === 1 ? 'has' : 'have' }} no row below. Saving this page leaves
            {{ count($unregistered) === 1 ? 'it' : 'them' }} untouched — it only rewrites permissions it can show.
            Add {{ count($unregistered) === 1 ? 'it' : 'them' }} to <code>RolePermission::allPermissions()</code>
            to make {{ count($unregistered) === 1 ? 'it' : 'them' }} editable:
            <div class="mt-1">
                @foreach($unregistered as $slug)<code class="me-2">{{ $slug }}</code>@endforeach
            </div>
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.permissions.update') }}">
    @csrf @method('PUT')

    {{-- Which roles this page actually rendered. An unchecked checkbox posts
         nothing, so without this a role with every box cleared would look
         identical to a role that wasn't on the form — and the controller would
         leave it alone, making "revoke everything from Viewer" impossible.
         Roles absent from this list keep what they have, which is what protects
         a role someone else created between this page load and the save. --}}
    @foreach($roles as $role)
        @unless($role->is_super)
            <input type="hidden" name="roles_present[]" value="{{ $role->slug }}">
        @endunless
    @endforeach

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover mb-0 align-middle">
                    <thead class="table-dark" style="position:sticky; top:0; z-index:2">
                        <tr>
                            <th style="min-width:280px" class="ps-3">Permission</th>
                            @foreach($roles as $role)
                            <th class="text-center" style="min-width:120px">
                                <span class="badge {{ $role->badgeClass() }} px-2 py-2">{{ $role->name }}</span>
                                @if($role->is_super)
                                    <div class="mt-1" style="font-size:10px">
                                        <i class="bi bi-key-fill"></i> all
                                    </div>
                                @else
                                    <div class="mt-1">
                                        <button type="button" class="btn btn-link btn-sm p-0 text-white-50 role-toggle"
                                                data-role="{{ $role->slug }}" style="font-size:10px">
                                            toggle all
                                        </button>
                                    </div>
                                @endif
                            </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($permissions as $category => $perms)
                        <tr class="table-light perm-category">
                            <td colspan="{{ $roles->count() + 1 }}" class="ps-3 py-2">
                                <span class="fw-bold text-uppercase small text-secondary">
                                    <i class="bi bi-folder2 me-1"></i>{{ $category }}
                                </span>
                            </td>
                        </tr>
                        @foreach($perms as $slug => $label)
                        <tr class="perm-row" data-search="{{ strtolower($label.' '.$slug) }}">
                            <td class="ps-4">
                                <span class="small">{{ $label }}</span>
                                <br><code class="text-muted" style="font-size:11px">{{ $slug }}</code>
                            </td>
                            @foreach($roles as $role)
                            <td class="text-center">
                                @if($role->is_super)
                                    {{-- Implicit: hasPermission() short-circuits on is_super, so a row here
                                         would be decoration. Nothing is posted for this role. --}}
                                    <input type="checkbox" class="form-check-input fs-5" checked disabled
                                           title="{{ $role->name }} holds every permission implicitly">
                                @else
                                    <input type="checkbox"
                                           class="form-check-input fs-5 perm-check role-{{ $role->slug }}"
                                           name="permissions[{{ $role->slug }}][{{ $slug }}]"
                                           value="1"
                                           {{ ($matrix[$role->slug][$slug] ?? false) ? 'checked' : '' }}>
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
                <i class="bi bi-lock-fill me-1"></i>Superuser columns are read-only.
            </small>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>Save Permissions
            </button>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    document.querySelectorAll('.role-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            // Only the rows the filter is currently showing, so toggling after a
            // filter means "all the attendance ones", not all 130.
            var boxes = Array.from(document.querySelectorAll('.role-' + btn.dataset.role))
                .filter(function (cb) {
                    var row = cb.closest('.perm-row');
                    return row && row.style.display !== 'none';
                });
            var allOn = boxes.every(cb => cb.checked);
            boxes.forEach(cb => cb.checked = !allOn);
        });
    });

    var filter = document.getElementById('permFilter');
    filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase();
        document.querySelectorAll('.perm-row').forEach(function (row) {
            row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
        });
        document.querySelectorAll('.perm-category').forEach(function (header) {
            var next = header.nextElementSibling, anyVisible = false;
            while (next && next.classList.contains('perm-row')) {
                if (next.style.display !== 'none') { anyVisible = true; break; }
                next = next.nextElementSibling;
            }
            header.style.display = anyVisible ? '' : 'none';
        });
    });
})();
</script>
@endpush
@endsection
