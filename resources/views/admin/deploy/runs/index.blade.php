@extends('layouts.admin')

@section('title', 'Deploy Run History')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Run History</h4>
            <small class="text-muted">Every terminal session and command press, with its exit code and transcript.</small>
        </div>
        <a href="{{ route('admin.deploy.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Servers
        </a>
    </div>

    <form method="GET" class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-end">
            <div>
                <label class="form-label small mb-1">Server</label>
                <select name="server" class="form-select form-select-sm">
                    <option value="">All servers</option>
                    @foreach($servers as $s)
                        <option value="{{ $s->id }}" @selected(request('server') == $s->id)>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach(['running', 'success', 'failed', 'timeout', 'aborted'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ $status }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-sm btn-primary">Filter</button>
            <a href="{{ route('admin.deploy.runs.index') }}" class="btn btn-sm btn-outline-secondary">Reset</a>
        </div>
    </form>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:70px;">#</th>
                            <th>Server</th>
                            <th>What ran</th>
                            <th>Who</th>
                            <th>Status</th>
                            <th>Duration</th>
                            <th>When</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td>
                                <a href="{{ route('admin.deploy.runs.show', $run) }}" class="text-decoration-none">
                                    {{ $run->id }}
                                </a>
                            </td>
                            <td>{{ $run->server->name ?? '—' }}</td>
                            <td>
                                {{ $run->label() }}
                                @if($run->mode === 'shell')
                                    <span class="badge bg-secondary ms-1">shell</span>
                                @endif
                            </td>
                            <td class="small">{{ $run->user->name ?? 'unknown' }}</td>
                            <td>
                                <span class="badge {{ $run->statusBadgeClass() }}">{{ $run->status }}</span>
                                @if($run->exit_code !== null)
                                    <code class="small ms-1">exit {{ $run->exit_code }}</code>
                                @endif
                            </td>
                            <td class="small text-muted">{{ $run->durationLabel() }}</td>
                            <td class="small text-muted" title="{{ $run->created_at }}">
                                {{ $run->created_at->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No runs recorded.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">{{ $runs->links() }}</div>
</div>
@endsection
