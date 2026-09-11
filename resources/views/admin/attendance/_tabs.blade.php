@php
    $unmappedCount = \App\Models\Attendance\BiotimeEmployee::unmappedCount();
    // Background work the pages queued (attendance:work runs it every minute).
    $openTasks = \App\Models\Attendance\AttendanceTask::whereIn('status', ['pending', 'running'])->orderBy('id')->limit(5)->get();
    $recentTasks = \App\Models\Attendance\AttendanceTask::whereIn('status', ['done', 'failed'])
        ->where('finished_at', '>=', now()->subMinutes(15))
        ->latest('finished_at')
        ->limit(3)
        ->get();
@endphp

@if ($openTasks->isNotEmpty() || $recentTasks->isNotEmpty())
    <div class="card border-0 shadow-sm mb-3">
        <ul class="list-group list-group-flush small">
            @foreach ($openTasks as $task)
                <li class="list-group-item d-flex align-items-center gap-2">
                    @if ($task->status === 'running')
                        <span class="spinner-border spinner-border-sm text-primary"></span>
                        <span><strong>{{ $task->label }}</strong> — running since {{ $task->started_at?->format('H:i') }}</span>
                    @else
                        <i class="bi bi-hourglass-split text-muted"></i>
                        <span><strong>{{ $task->label }}</strong> — queued {{ $task->created_at?->diffForHumans() }}, starts within a minute</span>
                    @endif
                </li>
            @endforeach
            @foreach ($recentTasks as $task)
                <li class="list-group-item d-flex align-items-center gap-2 {{ $task->status === 'failed' ? 'text-danger' : '' }}">
                    <i class="bi {{ $task->status === 'failed' ? 'bi-x-circle-fill' : 'bi-check-circle-fill text-success' }}"></i>
                    <span><strong>{{ $task->label }}</strong> — {{ $task->status === 'failed' ? 'failed' : 'done' }} {{ $task->finished_at?->diffForHumans() }}: {{ $task->result }}</span>
                </li>
            @endforeach
        </ul>
    </div>
@endif
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link {{ request()->routeIs('admin.attendance.days.*') ? 'active' : '' }}"
           href="{{ route('admin.attendance.days.index') }}">
            <i class="bi bi-calendar-check me-1"></i>Check-in / Check-out
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
