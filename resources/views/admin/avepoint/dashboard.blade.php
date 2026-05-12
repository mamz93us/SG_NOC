@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-down-fill text-info me-2"></i>AvePoint</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#avepointRequestModal">
        <i class="bi bi-play-fill me-1"></i>Request Backup
    </button>
</div>

@include('admin.avepoint._nav')

@if(! $configured)
    <div class="alert alert-warning py-2 mb-3">
        <i class="bi bi-exclamation-triangle me-1"></i>
        AvePoint is not configured. Set tenant URL, client id, and secret on the
        <a href="{{ route('admin.settings.index') }}#avepoint">Settings page</a> first.
    </div>
@elseif(! $hasEndpoints)
    <div class="alert alert-info py-2 mb-3">
        <i class="bi bi-info-circle me-1"></i>
        AvePoint export endpoints are not configured — backup requests will fall back to the manual-upload IT task.
        Live monitoring (jobs / subscription) still works.
    </div>
@endif

@php
    $bytesUsed = $localCounts['bytes_used'];
    $human = function ($b) {
        if (! $b) return '0 B';
        $u = ['B','KB','MB','GB','TB']; $i = 0; $s = (float) $b;
        while ($s >= 1024 && $i < count($u) - 1) { $s /= 1024; $i++; }
        return sprintf('%.1f %s', $s, $u[$i]);
    };
@endphp

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Total NOC Backups</div>
                <div class="display-6 fw-bold">{{ number_format($localCounts['total']) }}</div>
                <div class="small text-muted">{{ $localCounts['this_week'] }} this week</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">In Flight</div>
                <div class="display-6 fw-bold text-info">{{ $localCounts['in_flight'] }}</div>
                <div class="small text-muted">{{ $localCounts['manual'] }} awaiting manual upload</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Failed (all-time)</div>
                <div class="display-6 fw-bold text-danger">{{ $localCounts['failed'] }}</div>
                <div class="small text-muted">{{ $localCounts['completed'] }} completed</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Azure Blob Storage Used</div>
                <div class="display-6 fw-bold">{{ $human($bytesUsed) }}</div>
                @if($subscription && isset($subscription['protectedSize']))
                    <div class="small text-muted">AvePoint: {{ $subscription['protectedSize'] }} GB protected</div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-md-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-cpu me-1"></i>Recent AvePoint Jobs
                <a href="{{ route('admin.avepoint.jobs') }}" class="float-end small">View all →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Job ID</th>
                            <th>State</th>
                            <th>Start</th>
                            <th class="text-end">Items</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($recentJobs as $j)
                        <tr>
                            <td><code class="small">{{ $j['id'] ?? '—' }}</code></td>
                            <td>
                                @php
                                    $state = strtolower((string)($j['state'] ?? ''));
                                    $badge = str_contains($state,'fail')   ? 'bg-danger'
                                          : (str_contains($state,'progress')|| str_contains($state,'running') ? 'bg-info text-dark'
                                          : (str_contains($state,'finish')  ? 'bg-success'
                                          : 'bg-secondary'));
                                @endphp
                                <span class="badge {{ $badge }}">{{ $j['state'] ?? '—' }}</span>
                            </td>
                            <td class="small text-muted">{{ $j['startTime'] ?? '—' }}</td>
                            <td class="text-end small">
                                {{ ($j['backupDetails']['successfulCount'] ?? $j['backupDetails']['successfulNumber'] ?? '—') }}
                                / {{ ($j['backupDetails']['totalCount'] ?? $j['backupDetails']['totalNumber'] ?? '—') }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">
                            {{ $configured ? 'No recent jobs returned by AvePoint.' : 'AvePoint not configured.' }}
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-archive me-1"></i>Recent NOC Backups
                <a href="{{ route('admin.avepoint.backups') }}" class="float-end small">View all →</a>
            </div>
            <div class="list-group list-group-flush small">
                @forelse($recentBackups as $b)
                    <a href="{{ route('admin.avepoint.backup.show', $b) }}" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between">
                            <div>
                                <strong>{{ $b->subject_name ?? $b->subject_upn }}</strong>
                                <span class="badge bg-light text-dark border ms-1">{{ $b->type }}</span>
                            </div>
                            <span class="badge {{ $b->statusBadgeClass() }}">{{ str_replace('_',' ',$b->status) }}</span>
                        </div>
                        <div class="text-muted">
                            {{ $b->created_at->diffForHumans() }}
                            @if($b->humanSize() !== '—') · {{ $b->humanSize() }} @endif
                        </div>
                    </a>
                @empty
                    <div class="list-group-item text-center text-muted py-3">No backups yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@include('admin.avepoint._request_modal')

@endsection
