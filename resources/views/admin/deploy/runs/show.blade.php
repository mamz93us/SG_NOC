@extends('layouts.admin')

@section('title', 'Run #' . $run->id)

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h4 class="mb-1">
                Run #{{ $run->id }} — {{ $run->label() }}
                <span class="badge {{ $run->statusBadgeClass() }} align-middle">{{ $run->status }}</span>
            </h4>
            <div class="small text-muted">
                {{ $run->server->name ?? 'deleted server' }}
                @if($run->server) <span class="font-monospace">({{ $run->server->target() }})</span> @endif
                · {{ $run->user->name ?? 'unknown' }}
                · {{ $run->created_at->format('Y-m-d H:i') }}
                · {{ $run->durationLabel() }}
                @if($run->exit_code !== null) · <code>exit {{ $run->exit_code }}</code> @endif
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.deploy.runs.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>History
            </a>
            @if($run->server)
            <a href="{{ route('admin.deploy.servers.show', $run->server) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-hdd-network me-1"></i>Server
            </a>
            @endif
        </div>
    </div>

    @if($run->isRunning())
        <div class="alert alert-info py-2 small">
            <i class="bi bi-hourglass-split me-1"></i>
            This run is still in flight. The transcript is written when it finishes — reload to check.
        </div>
    @endif

    @if($run->command)
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-transparent">
                <strong class="small text-uppercase text-muted">Command</strong>
            </div>
            <div class="card-body py-2">
                <pre class="mb-0 small"><code>{{ $run->command->command }}</code></pre>
            </div>
        </div>
    @endif

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <strong class="small text-uppercase text-muted">Output</strong>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary" id="btn-copy">
                    <i class="bi bi-clipboard me-1"></i>Copy
                </button>
                <button class="btn btn-sm btn-outline-secondary" id="btn-download">
                    <i class="bi bi-download me-1"></i>Download
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <pre id="run-output" class="mb-0 p-3 small"
                 style="max-height:70vh; overflow:auto; background:#0d0d0d; color:#d0d0d0;">{{ $run->output ?: ($run->mode === 'shell' ? '(interactive session — keystrokes are not recorded)' : '(no output captured)') }}</pre>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    const pre  = document.getElementById('run-output');
    const name = 'deploy-run-{{ $run->id }}.log';

    document.getElementById('btn-copy').addEventListener('click', (e) => {
        navigator.clipboard.writeText(pre.textContent).then(() => {
            const btn = e.currentTarget;
            btn.innerHTML = '<i class="bi bi-clipboard-check me-1"></i>Copied';
            setTimeout(() => { btn.innerHTML = '<i class="bi bi-clipboard me-1"></i>Copy'; }, 2000);
        });
    });

    document.getElementById('btn-download').addEventListener('click', () => {
        const url = URL.createObjectURL(new Blob([pre.textContent], { type: 'text/plain' }));
        const a   = Object.assign(document.createElement('a'), { href: url, download: name });
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    });
})();
</script>
@endpush
