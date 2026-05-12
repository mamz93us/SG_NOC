@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-down-fill text-info me-2"></i>AvePoint · Users</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#avepointRequestModal">
        <i class="bi bi-play-fill me-1"></i>Request Backup
    </button>
</div>

@include('admin.avepoint._nav')

{{-- Tenant-wide backup status (AvePoint's own latest backup jobs — applies to ALL users) --}}
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="small text-muted"><i class="bi bi-envelope me-1"></i>AvePoint · Last Tenant Mailbox Backup</div>
                        @if($tenantBackups['mailbox'])
                            <div>
                                <span class="badge bg-success">{{ $tenantBackups['mailbox']['state'] ?? 'Finished' }}</span>
                                <strong class="ms-1">{{ $tenantBackups['mailbox']['finishTime'] ?? '—' }}</strong>
                            </div>
                            <div class="small text-muted">
                                {{ $tenantBackups['mailbox']['backupDetails']['successfulCount'] ?? $tenantBackups['mailbox']['backupDetails']['successfulNumber'] ?? '?' }}
                                / {{ $tenantBackups['mailbox']['backupDetails']['totalCount'] ?? $tenantBackups['mailbox']['backupDetails']['totalNumber'] ?? '?' }} objects ·
                                Job <code>{{ $tenantBackups['mailbox']['id'] ?? '—' }}</code>
                            </div>
                        @else
                            <span class="text-muted small">No finished mailbox backup in the last 30 days.</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body py-2 px-3">
                <div class="d-flex align-items-center justify-content-between">
                    <div>
                        <div class="small text-muted"><i class="bi bi-cloud me-1"></i>AvePoint · Last Tenant OneDrive Backup</div>
                        @if($tenantBackups['onedrive'])
                            <div>
                                <span class="badge bg-success">{{ $tenantBackups['onedrive']['state'] ?? 'Finished' }}</span>
                                <strong class="ms-1">{{ $tenantBackups['onedrive']['finishTime'] ?? '—' }}</strong>
                            </div>
                            <div class="small text-muted">
                                {{ $tenantBackups['onedrive']['backupDetails']['successfulCount'] ?? $tenantBackups['onedrive']['backupDetails']['successfulNumber'] ?? '?' }}
                                / {{ $tenantBackups['onedrive']['backupDetails']['totalCount'] ?? $tenantBackups['onedrive']['backupDetails']['totalNumber'] ?? '?' }} objects ·
                                Job <code>{{ $tenantBackups['onedrive']['id'] ?? '—' }}</code>
                            </div>
                        @else
                            <span class="text-muted small">No finished OneDrive backup in the last 30 days.</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@if($tenantBackups['error'])
    <div class="alert alert-warning py-2 small mb-3">
        <i class="bi bi-exclamation-triangle me-1"></i>Tenant backup lookup error: {{ $tenantBackups['error'] }}
    </div>
@endif

<div class="alert alert-light border py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>AvePoint's API doesn't expose per-user backup status</strong> — only tenant-wide aggregated job info (above).
    The "Last NOC Backup" columns below show backups <em>this NOC instance</em> has pulled into Azure Blob via
    the Request flow. They will stay "never" until you click <strong>Request</strong> on a user.
</div>

<form method="GET" class="mb-3 d-flex gap-2">
    <input type="text" name="q" value="{{ $q }}" class="form-control" placeholder="Search by name, UPN, email, department…">
    <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
    @if($q)<a href="{{ route('admin.avepoint.users') }}" class="btn btn-link">Clear</a>@endif
</form>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>User</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Last NOC Backup <small class="text-muted fw-normal">(Mailbox)</small></th>
                    <th>Last NOC Backup <small class="text-muted fw-normal">(OneDrive)</small></th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $u)
                    @php
                        $upn = strtolower($u->user_principal_name ?? '');
                        $mb  = $lastBackups[$upn]['mailbox']  ?? null;
                        $od  = $lastBackups[$upn]['onedrive'] ?? null;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $u->display_name }}</strong><br>
                            <small class="text-muted font-monospace">{{ $u->user_principal_name }}</small>
                        </td>
                        <td>{{ $u->department ?: '—' }}</td>
                        <td><span class="badge {{ $u->statusBadgeClass() }}">{{ $u->statusLabel() }}</span></td>
                        <td>
                            @if($mb)
                                <span class="badge {{ $mb->statusBadgeClass() }}">{{ str_replace('_',' ',$mb->status) }}</span>
                                <div class="small text-muted">{{ $mb->created_at->diffForHumans() }} · {{ $mb->humanSize() }}</div>
                                @if($mb->isDownloadable())
                                    <a href="{{ url('/avepoint/download/' . $mb->download_token) }}" class="small">Download</a>
                                @elseif($mb->status === 'completed')
                                    <span class="small text-muted">link expired</span>
                                @endif
                            @else
                                <span class="text-muted small">never</span>
                            @endif
                        </td>
                        <td>
                            @if($od)
                                <span class="badge {{ $od->statusBadgeClass() }}">{{ str_replace('_',' ',$od->status) }}</span>
                                <div class="small text-muted">{{ $od->created_at->diffForHumans() }} · {{ $od->humanSize() }}</div>
                                @if($od->isDownloadable())
                                    <a href="{{ url('/avepoint/download/' . $od->download_token) }}" class="small">Download</a>
                                @elseif($od->status === 'completed')
                                    <span class="small text-muted">link expired</span>
                                @endif
                            @else
                                <span class="text-muted small">never</span>
                            @endif
                        </td>
                        <td class="text-end">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#avepointRequestModal"
                                    data-upn="{{ $u->user_principal_name }}"
                                    data-name="{{ $u->display_name }}">
                                <i class="bi bi-play-fill"></i> Request
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">
                        No users found{{ $q ? ' matching "'.$q.'"' : '' }}.
                    </td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3">{{ $users->links() }}</div>

@include('admin.avepoint._request_modal')

@endsection
