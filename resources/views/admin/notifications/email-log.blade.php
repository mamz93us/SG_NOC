@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-envelope-check-fill me-2 text-primary"></i>Email Send Log</h4>
        <small class="text-muted">Audit trail of all outgoing notification emails</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.notification-rules.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-funnel me-1"></i>Routing Rules
        </a>
        @can('view-email-logs')
        <form method="POST" action="{{ route('admin.email-log.clear') }}" class="d-inline"
              onsubmit="return confirm('Clear all {{ $logs->total() }} log entries? This cannot be undone.')">
            @csrf @method('DELETE')
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-trash me-1"></i>Clear All
            </button>
        </form>
        @endcan
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Filters --}}
<form method="GET" class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search email or subject…" value="{{ request('search') }}">
        </div>
        <div class="col-md-3">
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Types</option>
                @foreach($types as $t)
                <option value="{{ $t }}" {{ request('type') === $t ? 'selected' : '' }}>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Status</option>
                <option value="sent" {{ request('status') === 'sent' ? 'selected' : '' }}>Sent</option>
                <option value="failed" {{ request('status') === 'failed' ? 'selected' : '' }}>Failed</option>
            </select>
        </div>
        <div class="col-md-auto">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Search</button>
            <a href="{{ route('admin.email-log.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
    </div>
</form>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Type</th>
                    <th class="text-center">Status</th>
                    <th>Sent At</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
            @forelse($logs as $log)
            <tr>
                <td>
                    <div class="fw-semibold">{{ $log->to_name ?? $log->to_email }}</div>
                    <div class="text-muted small">{{ $log->to_email }}</div>
                </td>
                <td class="text-truncate" style="max-width:220px">{{ $log->subject }}</td>
                <td><span class="badge bg-light text-dark border">{{ $log->notification_type }}</span></td>
                <td class="text-center">
                    @if($log->status === 'sent')
                    <span class="badge bg-success"><i class="bi bi-check me-1"></i>Sent</span>
                    @else
                    <span class="badge bg-danger"><i class="bi bi-x me-1"></i>Failed</span>
                    @endif
                </td>
                <td class="text-muted">{{ $log->sent_at ? \Carbon\Carbon::parse($log->sent_at)->format('d M Y H:i') : '—' }}</td>
                <td>
                    @if($log->error_message)
                    <button class="btn btn-link btn-sm p-0 text-danger"
                            data-bs-toggle="tooltip" title="{{ $log->error_message }}">
                        <i class="bi bi-exclamation-circle"></i> Show
                    </button>
                    @else
                    <span class="text-muted">—</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No email logs found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($logs->hasPages())
    <div class="card-footer bg-transparent">
        {{ $logs->withQueryString()->links() }}
    </div>
    @endif
</div>

@push('scripts')
<script>
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>
@endpush
@endsection
