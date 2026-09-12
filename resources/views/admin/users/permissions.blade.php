@extends('layouts.admin')

@section('content')
@php
    $roleModel = $user->roleModel();
    $grantCount = count(array_filter($overrides, fn ($e) => $e === 'grant'));
    $denyCount  = count(array_filter($overrides, fn ($e) => $e === 'deny'));
    $totalPerms = collect($permissions)->flatten(1)->count();
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-gear me-2 text-primary"></i>Permission Exceptions
        </h4>
        <small class="text-muted">
            For <strong>{{ $user->name }}</strong> &lt;{{ $user->email }}&gt;
            &nbsp;<span class="badge {{ \App\Models\Role::badgeFor($user->role) }}">{{ \App\Models\User::roleLabel($user->role) }}</span>
        </small>
    </div>
    <a href="{{ route('admin.users.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Users
    </a>
</div>

<div class="alert {{ $overrides ? 'alert-warning' : 'alert-info' }} d-flex align-items-start gap-2 py-2 mb-3">
    <i class="bi {{ $overrides ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill' }} mt-1"></i>
    <div class="small">
        @if($overrides)
            <strong>{{ $user->name }} has {{ count($overrides) }} exception{{ count($overrides) === 1 ? '' : 's' }}
            to the {{ \App\Models\User::roleLabel($user->role) }} role</strong>
            — {{ $grantCount }} extra, {{ $denyCount }} revoked.
            Everything else follows the role, so a change to the role still reaches them.
        @else
            <strong>{{ $user->name }} follows the {{ \App\Models\User::roleLabel($user->role) }} role exactly.</strong>
            Set a permission to <em>Grant</em> or <em>Deny</em> below to make an exception for this one person.
        @endif
        <div class="mt-1">
            Effective access is <strong>role + grants − denies</strong>; a deny always wins.
            They hold <strong>{{ count($effective) }}</strong> of {{ $totalPerms }} permissions right now.
        </div>
    </div>
</div>

@if($roleModel && $roleModel->usesPortal())
    <div class="alert alert-secondary d-flex align-items-start gap-2 py-2 mb-3">
        <i class="bi bi-window mt-1"></i>
        <div class="small">
            The <strong>{{ $roleModel->name }}</strong> role does not include the
            <strong>NOC Admin</strong> surface, so this person never sees the admin area regardless of what is
            granted here. Granting an admin permission to a portal-only role has no visible effect — change the
            role's surfaces instead.
            @can('manage-roles')
                <a href="{{ route('admin.roles.edit', $roleModel) }}">Edit the {{ $roleModel->name }} role</a>
            @endcan
        </div>
    </div>
@endif

<form method="POST" action="{{ route('admin.users.permissions.update', $user) }}">
    @csrf @method('PUT')

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div class="small text-muted">
                <i class="bi bi-info-circle me-1"></i>
                <strong>Inherit</strong> follows the role. Use the other two only for a genuine exception.
            </div>
            <div class="d-flex align-items-center gap-2">
                <input type="search" id="permFilter" class="form-control form-control-sm"
                       placeholder="Filter permissions…" style="width:200px">
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="onlyExceptions">
                    <label class="form-check-label small" for="onlyExceptions">Only exceptions</label>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="allInheritBtn">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>All inherit
                </button>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:65vh; overflow-y:auto">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="table-dark" style="position:sticky; top:0; z-index:2">
                        <tr>
                            <th class="ps-3" style="width:48%">Permission</th>
                            <th class="text-center" style="width:14%">Role</th>
                            <th class="text-center" style="width:12%">Inherit</th>
                            <th class="text-center" style="width:13%">Grant</th>
                            <th class="text-center" style="width:13%">Deny</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($permissions as $category => $perms)
                        <tr class="table-light perm-category">
                            <td colspan="5" class="ps-3 py-2">
                                <span class="fw-bold text-uppercase small text-secondary">
                                    <i class="bi bi-folder2 me-1"></i>{{ $category }}
                                </span>
                            </td>
                        </tr>
                        @foreach($perms as $slug => $label)
                        @php
                            $roleHas = in_array($slug, $roleGrants, true);
                            $effect  = $overrides[$slug] ?? 'inherit';
                            $isException = $effect !== 'inherit';
                            $hasIt   = in_array($slug, $effective, true);
                        @endphp
                        <tr class="perm-row {{ $isException ? 'table-warning' : '' }}"
                            data-search="{{ strtolower($label.' '.$slug) }}"
                            data-exception="{{ $isException ? '1' : '0' }}">
                            <td class="ps-4">
                                <span class="small">{{ $label }}</span>
                                @if($hasIt)
                                    <i class="bi bi-check-circle-fill text-success ms-1"
                                       title="Currently allowed"></i>
                                @endif
                                <br><code class="text-muted" style="font-size:11px">{{ $slug }}</code>
                            </td>
                            <td class="text-center">
                                @if($roleHas)
                                    <span class="badge bg-success-subtle text-success border border-success-subtle"
                                          title="The role grants this">granted</span>
                                @else
                                    <span class="text-muted" title="The role does not grant this">—</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <input type="radio" class="form-check-input effect-radio"
                                       name="effect[{{ $slug }}]" value="inherit"
                                       data-slug="{{ $slug }}"
                                       {{ $effect === 'inherit' ? 'checked' : '' }}>
                            </td>
                            <td class="text-center">
                                {{-- Granting something the role already gives is a
                                     no-op; the controller drops such a row so a
                                     later role change still reaches this user. --}}
                                <input type="radio" class="form-check-input effect-radio"
                                       name="effect[{{ $slug }}]" value="grant"
                                       data-slug="{{ $slug }}"
                                       {{ $effect === 'grant' ? 'checked' : '' }}
                                       {{ $roleHas ? 'disabled title=Already granted by the role' : '' }}>
                            </td>
                            <td class="text-center">
                                <input type="radio" class="form-check-input effect-radio"
                                       name="effect[{{ $slug }}]" value="deny"
                                       data-slug="{{ $slug }}"
                                       {{ $effect === 'deny' ? 'checked' : '' }}
                                       {{ $roleHas ? '' : 'disabled title=The role does not grant this anyway' }}>
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
                @if($overrides)
                    <button type="button" class="btn btn-outline-danger btn-sm"
                            onclick="if (confirm('Clear every exception for {{ $user->name }} and follow the {{ \App\Models\User::roleLabel($user->role) }} role exactly?')) document.getElementById('resetForm').submit();">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Clear all exceptions
                    </button>
                @endif
                <small class="text-muted ms-2">
                    <span id="exceptionCount">{{ count($overrides) }}</span> exception(s)
                </small>
            </div>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>Save Exceptions
            </button>
        </div>
    </div>
</form>

@if($overrides)
<form id="resetForm" method="POST" action="{{ route('admin.users.permissions.reset', $user) }}" class="d-none">
    @csrf @method('DELETE')
</form>
@endif

@push('scripts')
<script>
(function () {
    var rows = document.querySelectorAll('.perm-row');
    var countEl = document.getElementById('exceptionCount');

    function recount() {
        var n = 0;
        document.querySelectorAll('.effect-radio:checked').forEach(function (r) {
            if (r.value !== 'inherit') n++;
        });
        countEl.textContent = n;
    }

    document.querySelectorAll('.effect-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var row = radio.closest('.perm-row');
            var isException = radio.value !== 'inherit';
            row.classList.toggle('table-warning', isException);
            row.dataset.exception = isException ? '1' : '0';
            recount();
        });
    });

    document.getElementById('allInheritBtn').addEventListener('click', function () {
        visibleRows().forEach(function (row) {
            var inherit = row.querySelector('.effect-radio[value="inherit"]');
            if (inherit) { inherit.checked = true; }
            row.classList.remove('table-warning');
            row.dataset.exception = '0';
        });
        recount();
    });

    function visibleRows() {
        return Array.from(rows).filter(r => r.style.display !== 'none');
    }

    var filter = document.getElementById('permFilter');
    var onlyExceptions = document.getElementById('onlyExceptions');

    function applyFilters() {
        var q = filter.value.trim().toLowerCase();
        var only = onlyExceptions.checked;

        rows.forEach(function (row) {
            var matchesText = !q || row.dataset.search.includes(q);
            var matchesOnly = !only || row.dataset.exception === '1';
            row.style.display = (matchesText && matchesOnly) ? '' : 'none';
        });

        document.querySelectorAll('.perm-category').forEach(function (header) {
            var next = header.nextElementSibling, anyVisible = false;
            while (next && next.classList.contains('perm-row')) {
                if (next.style.display !== 'none') { anyVisible = true; break; }
                next = next.nextElementSibling;
            }
            header.style.display = anyVisible ? '' : 'none';
        });
    }

    filter.addEventListener('input', applyFilters);
    onlyExceptions.addEventListener('change', applyFilters);
    recount();
})();
</script>
@endpush
@endsection
