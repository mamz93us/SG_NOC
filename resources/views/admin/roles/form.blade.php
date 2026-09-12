@extends('layouts.admin')

@section('content')
@php
    $isNew = ! $role->exists;
    $action = $isNew ? route('admin.roles.store') : route('admin.roles.update', $role);
    $selectedSurfaces = old('surfaces', $role->surfaceList() ?: ['noc_admin']);
    $selectedLanding = old('landing', $role->landing ?: 'noc_admin');
    $grantedSet = array_flip(old('permissions') ? array_keys(array_filter((array) old('permissions'))) : $granted);
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold">
            <i class="bi bi-person-badge me-2 text-primary"></i>{{ $isNew ? 'New Role' : 'Edit Role' }}
        </h4>
        <small class="text-muted">
            @if($isNew)
                Name it, choose where it can sign in, then tick what it can do
            @else
                <span class="badge {{ $role->badgeClass() }}">{{ $role->name }}</span>
                <code class="ms-1">{{ $role->slug }}</code>
            @endif
        </small>
    </div>
    <a href="{{ route('admin.roles.index') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Roles
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger py-2">
        <ul class="mb-0 small">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

@if($role->is_super)
    <div class="alert alert-danger d-flex align-items-start gap-2 py-2">
        <i class="bi bi-key-fill mt-1"></i>
        <div class="small">
            <strong>This is a superuser role.</strong> It holds every permission implicitly — including any added
            in future — so the permission list below is shown for reference only and is not saved.
            Name, description and surfaces are still editable.
        </div>
    </div>
@endif

<form method="POST" action="{{ $action }}">
    @csrf
    @unless($isNew) @method('PUT') @endunless

    {{-- ── Identity ────────────────────────────────────────────── --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-tag me-1"></i>Identity
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold">Name</label>
                    <input type="text" name="name" class="form-control"
                           value="{{ old('name', $role->name) }}" required maxlength="100"
                           placeholder="e.g. Warehouse Supervisor">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">
                        Slug
                        @unless($isNew)
                            <i class="bi bi-lock-fill text-muted ms-1" title="Set once, at creation"></i>
                        @endunless
                    </label>
                    @if($isNew)
                        <input type="text" name="slug" class="form-control font-monospace"
                               value="{{ old('slug') }}" required maxlength="50"
                               pattern="[a-z][a-z0-9_]*"
                               placeholder="warehouse_supervisor">
                        <div class="form-text" style="font-size:11px">
                            Lowercase, digits and underscores. <strong>Chosen once and permanent</strong> — it is what
                            each user record stores. The name above is what people see and can be changed any time.
                        </div>
                    @else
                        <input type="text" class="form-control bg-light font-monospace"
                               value="{{ $role->slug }}" disabled>
                        <div class="form-text" style="font-size:11px">
                            Fixed. Every user on this role stores this string, and the notification router, the
                            approval chain and the SSO default read it — so it cannot be changed.
                            Rename via <strong>Name</strong> instead.
                        </div>
                    @endif
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Sort order</label>
                    <input type="number" name="sort_order" class="form-control"
                           value="{{ old('sort_order', $role->sort_order ?? 100) }}" min="0" max="9999">
                    <div class="form-text" style="font-size:11px">Lower sorts first in lists.</div>
                </div>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Description</label>
                    <input type="text" name="description" class="form-control"
                           value="{{ old('description', $role->description) }}" maxlength="255"
                           placeholder="What this role is for — shown on the roles list">
                </div>
            </div>
        </div>
    </div>

    {{-- ── Surfaces ────────────────────────────────────────────── --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-window-stack me-1"></i>Where this role can sign in
        </div>
        <div class="card-body">
            <p class="small text-muted mb-3">
                Tick every app surface this role may reach, then pick which one they land on after sign-in.
                A role without <strong>NOC Admin</strong> never sees the admin chrome — it is a portal-only role.
            </p>

            <div class="row g-2">
                @foreach(\App\Models\Role::SURFACES as $key => $label)
                    @php $checked = in_array($key, (array) $selectedSurfaces, true); @endphp
                    <div class="col-md-6">
                        <div class="border rounded p-2 h-100 {{ $checked ? 'border-primary bg-primary-subtle bg-opacity-10' : '' }}"
                             data-surface-card="{{ $key }}">
                            <div class="form-check mb-1">
                                <input class="form-check-input surface-check" type="checkbox"
                                       name="surfaces[]" value="{{ $key }}"
                                       id="surface-{{ $key }}" {{ $checked ? 'checked' : '' }}>
                                <label class="form-check-label fw-semibold small" for="surface-{{ $key }}">
                                    {{ $label }}
                                </label>
                            </div>
                            <div class="small text-muted" style="font-size:11px">
                                {{ \App\Models\Role::SURFACE_HINTS[$key] ?? '' }}
                            </div>
                            <div class="form-check mt-2">
                                <input class="form-check-input landing-radio" type="radio"
                                       name="landing" value="{{ $key }}"
                                       id="landing-{{ $key }}"
                                       {{ $selectedLanding === $key ? 'checked' : '' }}
                                       {{ $checked ? '' : 'disabled' }}>
                                <label class="form-check-label small text-muted" for="landing-{{ $key }}">
                                    Land here after sign-in
                                </label>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ── Permissions ─────────────────────────────────────────── --}}
    <div class="card shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold"><i class="bi bi-shield-check me-1"></i>Permissions</span>
            <div class="d-flex align-items-center gap-2">
                @if($isNew && $copyFrom->isNotEmpty())
                    <label class="small text-muted mb-0" for="copy_from">Start from</label>
                    <select name="copy_from" id="copy_from" class="form-select form-select-sm" style="width:auto">
                        <option value="">Nothing (empty)</option>
                        @foreach($copyFrom as $other)
                            <option value="{{ $other->slug }}" {{ old('copy_from') === $other->slug ? 'selected' : '' }}>
                                {{ $other->name }}
                            </option>
                        @endforeach
                    </select>
                @endif
                <input type="search" id="permFilter" class="form-control form-control-sm"
                       placeholder="Filter permissions…" style="width:200px">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="checkAllBtn">All</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="uncheckAllBtn">None</button>
            </div>
        </div>

        @if($isNew)
            <div class="card-body border-bottom py-2">
                <div class="small text-muted">
                    <i class="bi bi-info-circle me-1"></i>
                    Leave every box unticked and pick a role in <strong>Start from</strong> to copy its permissions.
                    Tick anything and the copy is ignored — what you tick is what you get.
                </div>
            </div>
        @endif

        <div class="card-body p-0">
            <div class="table-responsive" style="max-height:60vh; overflow-y:auto">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <tbody>
                        @foreach($permissions as $category => $perms)
                            <tr class="table-light perm-category">
                                <td class="ps-3 py-2" colspan="2">
                                    <span class="fw-bold text-uppercase small text-secondary">
                                        <i class="bi bi-folder2 me-1"></i>{{ $category }}
                                    </span>
                                    <button type="button" class="btn btn-link btn-sm p-0 ms-2 category-toggle"
                                            data-category="{{ $loop->index }}" style="font-size:11px">
                                        toggle group
                                    </button>
                                </td>
                            </tr>
                            @foreach($perms as $slug => $label)
                                <tr class="perm-row" data-search="{{ strtolower($label.' '.$slug) }}">
                                    <td style="width:44px" class="text-center">
                                        <input type="checkbox"
                                               class="form-check-input perm-check cat-{{ $loop->parent->index }}"
                                               name="permissions[{{ $slug }}]" value="1"
                                               id="perm-{{ $slug }}"
                                               {{ isset($grantedSet[$slug]) ? 'checked' : '' }}
                                               {{ $role->is_super ? 'checked disabled' : '' }}>
                                    </td>
                                    <td>
                                        <label class="mb-0 small" for="perm-{{ $slug }}">{{ $label }}</label>
                                        <br><code class="text-muted" style="font-size:11px">{{ $slug }}</code>
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
                <span id="permCount">0</span> of {{ collect($permissions)->flatten(1)->count() }} selected
            </small>
            <button type="submit" class="btn btn-primary px-4">
                <i class="bi bi-save me-1"></i>{{ $isNew ? 'Create Role' : 'Save Role' }}
            </button>
        </div>
    </div>
</form>

@push('scripts')
<script>
(function () {
    // A surface that isn't granted can't be the landing page — sign-in would
    // send the user somewhere the host isolation then 404s them on.
    function syncLanding() {
        document.querySelectorAll('.surface-check').forEach(function (cb) {
            var radio = document.getElementById('landing-' + cb.value);
            var card  = document.querySelector('[data-surface-card="' + cb.value + '"]');
            if (radio) {
                radio.disabled = !cb.checked;
                if (!cb.checked && radio.checked) radio.checked = false;
            }
            if (card) card.classList.toggle('border-primary', cb.checked);
        });

        // Always leave exactly one landing choice made, so the form can't be
        // submitted with none and bounce back on a validation error.
        var anyChecked = Array.from(document.querySelectorAll('.landing-radio')).some(r => r.checked);
        if (!anyChecked) {
            var first = document.querySelector('.landing-radio:not([disabled])');
            if (first) first.checked = true;
        }
    }

    document.querySelectorAll('.surface-check').forEach(function (cb) {
        cb.addEventListener('change', syncLanding);
    });
    syncLanding();

    // ── Permission helpers ──
    var checks = document.querySelectorAll('.perm-check:not([disabled])');
    var countEl = document.getElementById('permCount');

    function recount() {
        countEl.textContent = document.querySelectorAll('.perm-check:checked').length;
    }

    document.getElementById('checkAllBtn').addEventListener('click', function () {
        // Only rows the filter is currently showing, so "All" after a filter
        // means "all attendance permissions", not "all 130".
        visibleChecks().forEach(cb => cb.checked = true);
        recount();
    });
    document.getElementById('uncheckAllBtn').addEventListener('click', function () {
        visibleChecks().forEach(cb => cb.checked = false);
        recount();
    });

    function visibleChecks() {
        return Array.from(checks).filter(function (cb) {
            var row = cb.closest('.perm-row');
            return row && row.style.display !== 'none';
        });
    }

    document.querySelectorAll('.category-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var group = document.querySelectorAll('.cat-' + btn.dataset.category);
            var allOn = Array.from(group).every(cb => cb.checked);
            group.forEach(cb => { if (!cb.disabled) cb.checked = !allOn; });
            recount();
        });
    });

    checks.forEach(cb => cb.addEventListener('change', recount));

    var filter = document.getElementById('permFilter');
    filter.addEventListener('input', function () {
        var q = filter.value.trim().toLowerCase();
        document.querySelectorAll('.perm-row').forEach(function (row) {
            row.style.display = (!q || row.dataset.search.includes(q)) ? '' : 'none';
        });
        // Hide a category header whose rows are all filtered out.
        document.querySelectorAll('.perm-category').forEach(function (header) {
            var next = header.nextElementSibling, anyVisible = false;
            while (next && next.classList.contains('perm-row')) {
                if (next.style.display !== 'none') { anyVisible = true; break; }
                next = next.nextElementSibling;
            }
            header.style.display = anyVisible ? '' : 'none';
        });
    });

    recount();
})();
</script>
@endpush
@endsection
