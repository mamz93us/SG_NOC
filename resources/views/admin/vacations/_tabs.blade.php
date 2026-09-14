<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.vacations.balances.*') ? 'active' : '' }}"
           href="{{ route('admin.vacations.balances.index') }}">
            <i class="bi bi-wallet2 me-1"></i>Balances
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.vacations.absences.*') ? 'active' : '' }}"
           href="{{ route('admin.vacations.absences.index') }}">
            <i class="bi bi-calendar-range me-1"></i>Leave records
        </a>
    </li>
    @can('manage-vacations')
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.vacations.imports.*') ? 'active' : '' }}"
               href="{{ route('admin.vacations.imports.index') }}">
                <i class="bi bi-file-earmark-arrow-up me-1"></i>Import from Oracle
            </a>
        </li>
    @endcan
</ul>

@if (session('success'))
    <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        <pre class="mb-0 mt-1 small" style="white-space:pre-wrap">{{ session('success') }}</pre>
    </div>
@endif
@if (session('error'))
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-octagon-fill me-1"></i>
        <pre class="mb-0 mt-1 small" style="white-space:pre-wrap">{{ session('error') }}</pre>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger small">
        @foreach ($errors->all() as $message)
            <div>{{ $message }}</div>
        @endforeach
    </div>
@endif
