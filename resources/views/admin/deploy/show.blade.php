@extends('layouts.admin')

@section('title', $server->name)

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-1">
                {{ $server->name }}
                @unless($server->is_active)
                    <span class="badge bg-secondary align-middle">disabled</span>
                @endunless
            </h4>
            <div class="small text-muted font-monospace">{{ $server->target() }}</div>
            @if($server->description)
                <div class="small text-muted mt-1">{{ $server->description }}</div>
            @endif
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.deploy.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>All servers
            </a>
            @can('manage-deploy-servers')
            <a href="{{ route('admin.deploy.servers.edit', $server) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            @endcan
        </div>
    </div>

    @unless($server->hasCredentials())
        <div class="alert alert-warning py-2 small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            No SSH credentials are stored for this server yet, so nothing can connect.
            @can('manage-deploy-servers')
                <a href="{{ route('admin.deploy.servers.edit', $server) }}">Upload a key</a>.
            @endcan
        </div>
    @endunless

    @php
        $stuck = $runs->first(fn ($r) => $r->isRunning() && $r->mode === 'exec');
    @endphp
    @if($stuck)
        <div class="alert alert-info py-2 small d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-hourglass-split me-1"></i>
                Run #{{ $stuck->id }} ({{ $stuck->label() }}) is still marked running since
                {{ $stuck->started_at?->diffForHumans() }}.
                @if($stuck->isPastDeadline())
                    It is past its {{ $stuck->timeout_seconds }}s timeout, so it no longer blocks new runs —
                    close it to tidy the history.
                @else
                    New commands are blocked until it finishes.
                @endif
            </div>
            @can('run-deploy-commands')
            <form action="{{ route('admin.deploy.abort', [$server, $stuck]) }}" method="POST" class="ms-2"
                  onsubmit="return confirm('Force-close run #{{ $stuck->id }}? This does not stop anything already running on the server.');">
                @csrf
                <button class="btn btn-sm btn-outline-secondary text-nowrap">
                    <i class="bi bi-x-circle me-1"></i>Force close
                </button>
            </form>
            @endcan
        </div>
    @endif

    {{-- ── Actions ─────────────────────────────────────────────── --}}
    @can('run-deploy-commands')
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <strong class="small text-uppercase text-muted">Actions</strong>
            <span class="small text-muted">Output streams live and is saved to the run history.</span>
        </div>
        <div class="card-body d-flex flex-wrap gap-2">

            <form action="{{ route('admin.deploy.terminal', $server) }}" method="POST" target="_blank">
                @csrf
                <button type="submit" class="btn btn-dark">
                    <i class="bi bi-terminal-fill me-1"></i>SSH Terminal
                </button>
            </form>

            <form action="{{ route('admin.deploy.test', $server) }}" method="POST" target="_blank">
                @csrf
                <button type="submit" class="btn btn-outline-secondary">
                    <i class="bi bi-plug me-1"></i>Test connection
                </button>
            </form>

            @if($server->commands->where('is_active', true)->isNotEmpty())
                <div class="vr mx-2"></div>
            @endif

            @foreach($server->commands->where('is_active', true) as $command)
                <form action="{{ route('admin.deploy.run', [$server, $command]) }}" method="POST" target="_blank"
                      @if($command->confirm_required)
                          onsubmit="return confirm('Run {{ addslashes($command->name) }} on {{ addslashes($server->name) }}?');"
                      @endif>
                    @csrf
                    <button type="submit" class="btn {{ $command->buttonClass() }}"
                            title="{{ Str::limit($command->command, 120) }}">
                        <i class="bi {{ $command->icon() }} me-1"></i>{{ $command->name }}
                    </button>
                </form>
            @endforeach
        </div>
    </div>
    @endcan

    <div class="row g-3">
        {{-- ── Commands ─────────────────────────────────────────── --}}
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong class="small text-uppercase text-muted">Commands</strong>
                    @can('manage-deploy-servers')
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addCommandModal">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                    @endcan
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Command</th>
                                    <th class="text-center">Timeout</th>
                                    @can('manage-deploy-servers')<th></th>@endcan
                                </tr>
                            </thead>
                            <tbody>
                            @forelse($server->commands as $command)
                                <tr class="{{ $command->is_active ? '' : 'opacity-50' }}">
                                    <td>
                                        <i class="bi {{ $command->icon() }} me-1 text-muted"></i>
                                        {{ $command->name }}
                                        @unless($command->is_active)
                                            <span class="badge bg-secondary ms-1">off</span>
                                        @endunless
                                        @if($command->confirm_required)
                                            <i class="bi bi-shield-exclamation text-warning ms-1" title="Asks for confirmation"></i>
                                        @endif
                                    </td>
                                    <td>
                                        <code class="small d-block text-truncate" style="max-width:340px"
                                              title="{{ $command->command }}">{{ $command->command }}</code>
                                        @if($command->working_directory)
                                            <small class="text-muted font-monospace">in {{ $command->working_directory }}</small>
                                        @endif
                                    </td>
                                    <td class="text-center small text-muted">{{ $command->timeout_seconds }}s</td>
                                    @can('manage-deploy-servers')
                                    <td class="text-end text-nowrap">
                                        <button class="btn btn-sm btn-outline-secondary border-0"
                                                data-bs-toggle="modal" data-bs-target="#editCommandModal{{ $command->id }}">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form action="{{ route('admin.deploy.commands.destroy', [$server, $command]) }}"
                                              method="POST" class="d-inline"
                                              onsubmit="return confirm('Delete this command?');">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-sm btn-outline-danger border-0"><i class="bi bi-trash"></i></button>
                                        </form>
                                    </td>
                                    @endcan
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">
                                        No commands yet. Every server has its own deploy steps —
                                        @can('manage-deploy-servers')add the first one above.@else ask an admin to add them.@endcan
                                    </td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Recent runs ──────────────────────────────────────── --}}
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                    <strong class="small text-uppercase text-muted">Recent runs</strong>
                    <a href="{{ route('admin.deploy.runs.index', ['server' => $server->id]) }}"
                       class="small text-decoration-none">View all</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <tbody>
                            @forelse($runs as $run)
                                <tr>
                                    <td>
                                        <a href="{{ route('admin.deploy.runs.show', $run) }}" class="text-decoration-none">
                                            {{ $run->label() }}
                                        </a>
                                        <div class="small text-muted">
                                            {{ $run->user->name ?? 'unknown' }} · {{ $run->created_at->diffForHumans() }}
                                        </div>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <span class="badge {{ $run->statusBadgeClass() }}">{{ $run->status }}</span>
                                        @if($run->exit_code !== null)
                                            <code class="small ms-1">{{ $run->exit_code }}</code>
                                        @endif
                                        <div class="small text-muted">{{ $run->durationLabel() }}</div>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="text-center text-muted py-4">Nothing has run yet.</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

@can('manage-deploy-servers')
    {{-- Add --}}
    <div class="modal fade" id="addCommandModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" action="{{ route('admin.deploy.commands.store', $server) }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title">Add command</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.deploy._command_fields', ['command' => null, 'server' => $server])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add command</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Edit (one per row) --}}
    @foreach($server->commands as $command)
    <div class="modal fade" id="editCommandModal{{ $command->id }}" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form class="modal-content" action="{{ route('admin.deploy.commands.update', [$server, $command]) }}" method="POST">
                @csrf @method('PUT')
                <div class="modal-header">
                    <h5 class="modal-title">Edit command</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    @include('admin.deploy._command_fields', ['command' => $command, 'server' => $server])
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save changes</button>
                </div>
            </form>
        </div>
    </div>
    @endforeach
@endcan
@endsection
