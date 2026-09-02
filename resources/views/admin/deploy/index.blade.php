@extends('layouts.admin')

@section('title', 'Deployment Servers')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Deployment Servers</h4>
            <small class="text-muted">
                Registered SSH targets. Open a terminal, or press a server's own
                deploy / log buttons and watch the output stream.
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.deploy.runs.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-clock-history me-1"></i>Run history
            </a>
            @can('manage-deploy-servers')
            <a href="{{ route('admin.deploy.servers.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add server
            </a>
            @endcan
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Target</th>
                            <th>Auth</th>
                            <th>Branch</th>
                            <th class="text-center">Commands</th>
                            <th>Last run</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($servers as $server)
                        @php $last = $server->lastRun(); @endphp
                        <tr class="{{ $server->is_active ? '' : 'opacity-50' }}">
                            <td>
                                <a href="{{ route('admin.deploy.servers.show', $server) }}" class="fw-semibold text-decoration-none">
                                    {{ $server->name }}
                                </a>
                                @unless($server->is_active)
                                    <span class="badge bg-secondary ms-1">disabled</span>
                                @endunless
                                @if($server->description)
                                    <div class="small text-muted">{{ Str::limit($server->description, 70) }}</div>
                                @endif
                            </td>
                            <td class="font-monospace small">{{ $server->target() }}</td>
                            <td>
                                @if($server->usesKey())
                                    <span class="badge bg-info bg-opacity-25 text-info border border-info">key</span>
                                @else
                                    <span class="badge bg-secondary">password</span>
                                @endif
                                @unless($server->hasCredentials())
                                    <span class="badge bg-warning text-dark ms-1" title="Nothing stored to authenticate with">missing</span>
                                @endunless
                            </td>
                            <td class="small">{{ $server->branch->name ?? '—' }}</td>
                            <td class="text-center">{{ $server->active_commands_count }}</td>
                            <td class="small">
                                @if($last)
                                    <a href="{{ route('admin.deploy.runs.show', $last) }}" class="text-decoration-none">
                                        <span class="badge {{ $last->statusBadgeClass() }}">{{ $last->status }}</span>
                                        <span class="text-muted ms-1">{{ $last->created_at->diffForHumans() }}</span>
                                    </a>
                                @else
                                    <span class="text-muted">never</span>
                                @endif
                            </td>
                            <td class="text-end">
                                <a href="{{ route('admin.deploy.servers.show', $server) }}" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-box-arrow-in-right"></i> Open
                                </a>
                                @can('manage-deploy-servers')
                                <a href="{{ route('admin.deploy.servers.edit', $server) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                No deployment servers yet.
                                @can('manage-deploy-servers')
                                    <a href="{{ route('admin.deploy.servers.create') }}">Add the first one</a>.
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
