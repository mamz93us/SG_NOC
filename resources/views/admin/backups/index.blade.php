@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-shield-lock-fill me-2"></i>Device Backups</h1>
    @can('manage-backups')
    <a href="{{ route('admin.backups.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>New Backup Account</a>
    @endcan
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-5">
        <input name="q" value="{{ request('q') }}" class="form-control" placeholder="Search username or label…">
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select">
            <option value="">All statuses</option>
            <option value="active" @selected(request('status')==='active')>Active</option>
            <option value="overdue" @selected(request('status')==='overdue')>Overdue</option>
            <option value="disabled" @selected(request('status')==='disabled')>Disabled</option>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-outline-secondary w-100">Filter</button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Account</th><th>Device / Label</th><th>Protocols</th><th>Frequency</th>
                    <th>Last Received</th><th class="text-end">Last Size</th>
                    <th>Last Archived</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($accounts as $a)
                <tr class="{{ $a->is_active ? '' : 'opacity-50' }}">
                    <td><code>{{ $a->sftpgo_username }}</code></td>
                    <td>{{ $a->deviceLabel() }}</td>
                    <td>@foreach($a->allowedProtocols() as $p)<span class="badge bg-light text-dark border me-1">{{ $p }}</span>@endforeach</td>
                    <td>{{ ucfirst($a->expected_frequency) }}</td>
                    <td>{{ $a->last_received_at?->diffForHumans() ?? '—' }}</td>
                    <td class="text-end font-monospace">
                        {{ $a->latestBackup?->humanSize() ?? '—' }}
                    </td>
                    <td>{{ $a->last_archived_at?->diffForHumans() ?? '—' }}</td>
                    <td>
                        <span class="badge {{ $a->statusBadgeClass() }}">{{ ucfirst($a->last_status ?? 'pending') }}</span>
                        @unless($a->is_active)<span class="badge bg-secondary ms-1">disabled</span>@endunless
                    </td>
                    <td class="text-end"><a href="{{ route('admin.backups.show', $a) }}" class="btn btn-sm btn-outline-primary">View</a></td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No backup accounts yet.</td></tr>
            @endforelse
            </tbody>
            {{-- Totals span every account matching the current filter, not just
                 this page -- the label says so, because a footer that quietly
                 summed one page would understate storage as the estate grows. --}}
            @if($accounts->isNotEmpty())
            <tfoot class="table-light border-top">
                <tr class="fw-semibold">
                    <td colspan="5" class="text-end">
                        Total across {{ $totals['accounts'] }} {{ Str::plural('account', $totals['accounts']) }}
                    </td>
                    <td class="text-end font-monospace">
                        {{ \App\Models\SftpBackup::formatBytes($totals['last_size']) }}
                    </td>
                    <td colspan="3" class="small text-muted fw-normal">
                        <i class="bi bi-cloud-arrow-up me-1"></i>
                        {{ \App\Models\SftpBackup::formatBytes($totals['stored']) }} stored in Azure
                        across {{ number_format($totals['backups']) }}
                        {{ Str::plural('backup', $totals['backups']) }}
                    </td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

<div class="mt-3">{{ $accounts->links() }}</div>

@endsection
