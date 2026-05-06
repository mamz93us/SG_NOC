@extends('layouts.admin')

@section('title', 'Remote Browser — Activity Log')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-journal-text me-2"></i>Remote Browser — Activity Log</h3>
        <div class="btn-group">
            <a href="{{ route('admin.browser-portal.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul me-1"></i>Active sessions
            </a>
            <a href="{{ route('admin.browser-portal.settings') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-gear me-1"></i>Settings
            </a>
        </div>
    </div>

    <form class="card shadow-sm mb-3" method="GET">
        <div class="card-body row g-2">
            <div class="col-md-3">
                <label class="form-label small mb-1">Event type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">— any —</option>
                    @php $labels = \App\Models\BrowserSessionEvent::eventTypeLabels(); @endphp
                    @foreach ($types as $t)
                        <option value="{{ $t }}" @selected(request('type') === $t)>{{ $labels[$t] ?? $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">User ID</label>
                <input type="number" name="user_id" class="form-control form-control-sm" value="{{ request('user_id') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Session ID</label>
                <input type="text" name="session_id" class="form-control form-control-sm" value="{{ request('session_id') }}" maxlength="12">
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button class="btn btn-sm btn-primary" type="submit">Filter</button>
                <a href="{{ route('admin.browser-portal.events') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>When</th>
                            <th>User</th>
                            <th>Event</th>
                            <th>Session</th>
                            <th>Message</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($events as $e)
                        <tr>
                            <td><small>{{ $e->created_at?->format('Y-m-d H:i:s') }}</small></td>
                            <td>
                                <div>{{ $e->user?->name ?? '—' }}</div>
                                <small class="text-muted">{{ $e->user?->email }}</small>
                            </td>
                            <td>
                                @php
                                    $badge = match ($e->event_type) {
                                        'launch_succeeded' => 'success',
                                        'launch_failed', 'container_crashed', 'permission_denied' => 'danger',
                                        'launch_requested', 'heartbeat' => 'info',
                                        'force_stopped', 'idle_stopped' => 'warning',
                                        'shared', 'settings_changed' => 'primary',
                                        default => 'secondary',
                                    };
                                @endphp
                                <span class="badge bg-{{ $badge }}">{{ $labels[$e->event_type] ?? $e->event_type }}</span>
                            </td>
                            <td>
                                @if ($e->session_id)
                                    <code>{{ $e->session_id }}</code>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td><small>{{ $e->message ?? '' }}</small></td>
                            <td><small class="text-muted">{{ $e->ip_address ?? '—' }}</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-muted py-4">No events match the filter.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @if ($events->hasPages())
            <div class="card-footer">{{ $events->links() }}</div>
        @endif
    </div>
</div>
@endsection
