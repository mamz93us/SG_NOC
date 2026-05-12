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
@else
    <div class="alert alert-info py-2 mb-3 small">
        <i class="bi bi-info-circle me-1"></i>
        Per the AvePoint Graph API docs, Cloud Backup for M365 is read-only — this page monitors jobs,
        subscription, frequency, retention, and unusual activity. Triggering a NOC backup creates a
        manual-upload IT task; IT runs the export from AvePoint's web UI and uploads via NOC.
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
                        <tr><td colspan="4" class="text-muted py-3 px-3">
                            @if($recentJobsError)
                                <div class="text-danger small mb-1"><i class="bi bi-exclamation-triangle me-1"></i>{{ $recentJobsError }}</div>
                                @if($recentJobsUrl)
                                    <div class="small font-monospace" style="word-break:break-all;color:#888;">{{ $recentJobsUrl }}</div>
                                @endif
                            @else
                                <div class="text-center">No backup jobs in the last 30 days{{ $settings->avepoint_location ? ' (filtered to '.$settings->avepoint_location.')' : '' }}.</div>
                            @endif
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

@if($configured)
<div class="row g-3 mt-1">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-clock-history me-1"></i>Backup Frequency
            </div>
            <div class="card-body">
                @if($frequency)
                    <table class="table table-sm mb-0">
                        <tbody>
                        @foreach($frequency as $svc)
                            <tr>
                                <td>{{ $svc['serviceType'] ?? $svc['service'] ?? 'Service' }}</td>
                                <td class="text-end small text-muted">
                                    {{ $svc['backupFrequency'] ?? $svc['frequency'] ?? '—' }}
                                    @if(! empty($svc['backupStartTime']) && is_array($svc['backupStartTime']))
                                        <br><span class="font-monospace">{{ implode(', ', array_slice($svc['backupStartTime'], 0, 4)) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @else
                    <p class="text-muted small mb-0">No data — requires <code>microsoft365backup.settings.read.all</code> scope.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-shield-check me-1"></i>Retention Policy
            </div>
            <div class="card-body">
                @if($retention)
                    <pre class="small mb-0" style="max-height:240px;overflow:auto;">{{ json_encode($retention, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre>
                @else
                    <p class="text-muted small mb-0">No data — requires <code>microsoft365backup.settings.read.all</code> scope.</p>
                @endif
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-exclamation-triangle me-1"></i>Unusual Activity
            </div>
            <div class="card-body">
                @if($unusual && is_array($unusual) && count($unusual))
                    <ul class="small mb-0 ps-3">
                        @foreach(array_slice($unusual, 0, 8) as $u)
                            <li>{{ $u['description'] ?? json_encode($u) }}</li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-muted small mb-0">
                        No unusual activity reported{{ $configured ? '' : ' (AvePoint not configured)' }}.
                        Requires <code>microsoft365backup.unusualActivity.read.all</code> scope.
                    </p>
                @endif
            </div>
        </div>
    </div>
</div>
@endif

@include('admin.avepoint._request_modal')

@endsection
