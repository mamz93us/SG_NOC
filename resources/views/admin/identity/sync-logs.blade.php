@extends('layouts.admin')
@section('content')

@php
    // Only treat 'started' entries from the last 15 minutes as genuinely in-progress.
    // Older ones are orphaned (interrupted sync) and should not block the Sync Now button.
    $hasPending = $logs->contains(
        fn($l) => $l->status === 'started'
               && ($l->started_at ?? $l->created_at)->gt(now()->subMinutes(15))
    );
@endphp

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-clock-history me-2 text-primary"></i>Identity Sync Logs</h4>
        <small class="text-muted">
            History of Entra ID synchronisation runs
            @if($hasPending)
            &mdash; <span class="text-warning fw-semibold"><i class="bi bi-arrow-repeat spin me-1"></i>Sync in progress&hellip;</span>
            @endif
        </small>
    </div>
    @can('manage-identity')
    <form method="POST" action="{{ route('admin.identity.sync') }}">
        @csrf
        <button type="submit" class="btn btn-sm btn-outline-primary" {{ $hasPending ? 'disabled' : '' }}>
            <i class="bi bi-arrow-repeat me-1"></i>Sync Now
        </button>
    </form>
    @endcan
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2"><i class="bi bi-check-circle me-1"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('info'))
<div class="alert alert-info alert-dismissible fade show py-2"><i class="bi bi-arrow-repeat me-1"></i>{{ session('info') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2"><i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        @if($logs->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-clock-history display-4 d-block mb-2"></i>
            No sync history yet.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Started</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-center">Users</th>
                        <th class="text-center">Licenses</th>
                        <th class="text-center">Groups</th>
                        <th>Duration</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($logs as $log)
                    <tr>
                        <td class="text-muted">{{ $log->started_at ? $log->started_at->format('d M Y H:i:s') : $log->created_at->format('d M Y H:i:s') }}</td>
                        <td><span class="badge bg-secondary">{{ ucfirst($log->type) }}</span></td>
                        <td><span class="badge {{ $log->statusBadgeClass() }}">{{ ucfirst($log->status) }}</span></td>
                        <td class="text-center">{{ $log->users_synced ?? '—' }}</td>
                        <td class="text-center">{{ $log->licenses_synced ?? '—' }}</td>
                        <td class="text-center">{{ $log->groups_synced ?? '—' }}</td>
                        <td class="text-muted">
                            @php $dur = $log->durationSeconds(); @endphp
                            {{ $dur !== null ? $dur . 's' : '—' }}
                        </td>
                        <td class="text-muted">
                            @if($log->error_message)
                            <span class="text-danger" title="{{ $log->error_message }}">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ Str::limit($log->error_message, 50) }}
                            </span>
                            @else
                            —
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-3">{{ $logs->links() }}</div>
        @endif
    </div>
</div>

{{-- Auto-refresh while a sync is in progress or was just dispatched --}}
@if($hasPending || session('info'))
@push('scripts')
{{-- 2 s on first load after dispatch (lets artisan create the log entry), 5 s thereafter --}}
<script>setTimeout(() => location.reload(), {{ $hasPending ? 5000 : 2000 }});</script>
@endpush
@endif

@push('styles')
<style>
@keyframes spin { to { transform: rotate(360deg); } }
.spin { display: inline-block; animation: spin 1s linear infinite; }
</style>
@endpush

@endsection
