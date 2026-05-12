@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-cloud-arrow-down-fill text-info me-2"></i>AvePoint · Users</h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#avepointRequestModal">
        <i class="bi bi-play-fill me-1"></i>Request Backup
    </button>
</div>

@include('admin.avepoint._nav')

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
                    <th>Last Mailbox Backup</th>
                    <th>Last OneDrive Backup</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $u)
                    @php
                        $upn = strtolower($u->user_principal_name);
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
