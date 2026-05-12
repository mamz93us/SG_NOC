{{-- AvePoint module sub-nav (tabs shown on every page in the module). --}}
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.avepoint.dashboard') ? 'active' : '' }}"
           href="{{ route('admin.avepoint.dashboard') }}">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.avepoint.users') ? 'active' : '' }}"
           href="{{ route('admin.avepoint.users') }}">
            <i class="bi bi-people me-1"></i>Users
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.avepoint.jobs') ? 'active' : '' }}"
           href="{{ route('admin.avepoint.jobs') }}">
            <i class="bi bi-cpu me-1"></i>Live Jobs
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.avepoint.backups') ? 'active' : '' }}"
           href="{{ route('admin.avepoint.backups') }}">
            <i class="bi bi-archive me-1"></i>NOC Backups
        </a>
    </li>
</ul>
