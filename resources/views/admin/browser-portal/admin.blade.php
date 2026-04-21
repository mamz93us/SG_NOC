@extends('layouts.admin')

@section('title', 'Remote Browser — All Sessions')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h3 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Remote Browser — All Sessions</h3>
        <a href="{{ route('admin.browser-portal.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>My dashboard
        </a>
    </div>

    @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>User</th>
                            <th>Session</th>
                            <th>Status</th>
                            <th>IP</th>
                            <th>UDP ports</th>
                            <th>Started</th>
                            <th>Last activity</th>
                            <th>CPU</th>
                            <th>Mem</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse ($sessions as $s)
                        <tr>
                            <td>
                                <div>{{ $s->user?->name ?? '—' }}</div>
                                <small class="text-muted">{{ $s->user?->email ?? '' }}</small>
                            </td>
                            <td><code>{{ $s->session_id }}</code></td>
                            <td>
                                <span class="badge bg-{{ $s->status === 'running' ? 'success' : ($s->status === 'starting' ? 'warning' : 'secondary') }}">
                                    {{ $s->status }}
                                </span>
                            </td>
                            <td><code>{{ $s->internal_ip ?? '—' }}</code></td>
                            <td><small>{{ $s->webrtc_port_start }}–{{ $s->webrtc_port_end }}</small></td>
                            <td><small>{{ $s->created_at->diffForHumans() }}</small></td>
                            <td><small>{{ $s->last_active_at?->diffForHumans() ?? '—' }}</small></td>
                            <td><small>{{ $stats[$s->container_name]['cpu'] ?? '—' }}</small></td>
                            <td><small>{{ $stats[$s->container_name]['mem'] ?? '—' }}</small></td>
                            <td class="text-end">
                                <form method="POST" action="{{ route('admin.browser-portal.admin.destroy', $s->session_id) }}" class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" type="submit"
                                            onclick="return confirm('Force-stop this session? The user will lose unsaved state.')">
                                        <i class="bi bi-stop-circle"></i> Stop
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No active sessions.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-muted small">
            Stats are refreshed every 10 seconds from <code>docker stats</code>.
        </div>
    </div>
</div>
@endsection
