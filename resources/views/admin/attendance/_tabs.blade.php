@php
    $unmappedCount = \App\Models\Attendance\BiotimeEmployee::unmappedCount();
@endphp
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.days.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.days.index') }}">
            <i class="bi bi-calendar-check me-1"></i>Check-in / Check-out
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.monthly.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.monthly.index') }}">
            <i class="bi bi-calendar3 me-1"></i>Monthly sheet
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.employees.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.employees.index') }}">
            <i class="bi bi-person-lines-fill me-1"></i>Employee Mapping
            @if ($unmappedCount > 0)
                <span class="badge bg-danger ms-1">{{ $unmappedCount }}</span>
            @endif
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.shifts.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.shifts.index') }}">
            <i class="bi bi-clock-history me-1"></i>Shifts
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.holidays.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.holidays.index') }}">
            <i class="bi bi-calendar-heart me-1"></i>Holidays
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.areas.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.areas.index') }}">
            <i class="bi bi-geo-alt me-1"></i>Areas &amp; Terminals
        </a>
    </li>
    @can('manage-attendance')
        <li class="nav-item">
            <a class="nav-link {{ request()->routeIs('admin.attendance.sources.*') ? 'active' : '' }}"
               href="{{ route('admin.attendance.sources.index') }}">
                <i class="bi bi-database-gear me-1"></i>BioTime Sources
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
