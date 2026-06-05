@extends('layouts.admin')

@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-shield-lock-fill me-2"></i>{{ $account->deviceLabel() }}</h1>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.backups.index') }}" class="btn btn-outline-secondary">Back</a>
        @can('manage-backups')<a href="{{ route('admin.backups.edit', $account) }}" class="btn btn-outline-primary">Edit</a>@endcan
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif
@if(session('new_password'))
<div class="alert alert-warning d-flex align-items-center">
    <div><strong>New password (shown once — copy it now):</strong> <code id="new-pw">{{ session('new_password') }}</code></div>
    <button class="btn btn-sm btn-outline-dark ms-3" onclick="navigator.clipboard.writeText(document.getElementById('new-pw').textContent)">Copy</button>
</div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header"><strong>Connection</strong></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><th style="width:140px">Host</th><td><code>{{ $host }}</code></td></tr>
                    <tr><th>Username</th><td><code>{{ $account->sftpgo_username }}</code></td></tr>
                    <tr><th>Password</th><td>
                        @can('manage-backups')
                            <span id="pw-mask">••••••••</span>
                            <button class="btn btn-sm btn-outline-secondary ms-2" id="reveal-btn" data-url="{{ route('admin.backups.reveal', $account) }}">Reveal</button>
                            <form method="POST" action="{{ route('admin.backups.rotate', $account) }}" class="d-inline ms-1"
                                  onsubmit="return confirm('Rotate the password? The device must be reconfigured with the new one.')">
                                @csrf<button class="btn btn-sm btn-outline-warning">Rotate</button>
                            </form>
                        @else
                            <span class="text-muted">hidden</span>
                        @endcan
                    </td></tr>
                    <tr><th>Protocols</th><td>
                        @foreach($account->allowedProtocols() as $p)
                            <span class="badge bg-light text-dark border me-1">{{ $p }}{{ $p === 'SFTP' ? ' :2022' : ' :2121' }}</span>
                        @endforeach
                    </td></tr>
                    <tr><th>Upload to</th><td>the account's home directory — just <code>put</code> the file (no subfolder needed).</td></tr>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><strong>Recent backups</strong></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>File</th><th>Size</th><th>Status</th><th>Archived</th></tr></thead>
                    <tbody>
                    @forelse($recent as $b)
                        <tr>
                            <td class="font-monospace small">{{ $b->filename }}</td>
                            <td>{{ $b->humanSize() }}</td>
                            <td><span class="badge bg-secondary">{{ $b->status }}</span></td>
                            <td>{{ $b->uploaded_at?->diffForHumans() ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-muted py-3">No files archived yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header"><strong>Status</strong></div>
            <div class="card-body">
                <p class="mb-2">Overall:
                    <span class="badge {{ $account->statusBadgeClass() }}">{{ ucfirst($account->last_status ?? 'pending') }}</span>
                    @unless($account->is_active)<span class="badge bg-secondary">disabled</span>@endunless
                </p>
                <p class="mb-1"><strong>Frequency:</strong> {{ ucfirst($account->expected_frequency) }} (grace {{ $account->grace_minutes }}m)</p>
                <p class="mb-1"><strong>Last received:</strong> {{ $account->last_received_at?->format('Y-m-d H:i') ?? '—' }}</p>
                <p class="mb-1"><strong>Last archived:</strong> {{ $account->last_archived_at?->format('Y-m-d H:i') ?? '—' }}</p>
                @if($account->isOverdue())
                    <p class="text-danger mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>Backup overdue</p>
                @endif
            </div>
        </div>

        @can('manage-backups')
        <div class="card border-danger">
            <div class="card-header text-danger"><strong>Danger zone</strong></div>
            <div class="card-body d-flex flex-column gap-2">
                @if($account->is_active)
                <form method="POST" action="{{ route('admin.backups.destroy', $account) }}"
                      onsubmit="return confirm('Disable this account? Uploads are refused but history is kept.')">
                    @csrf @method('DELETE')<button class="btn btn-outline-warning w-100">Disable (keep history)</button>
                </form>
                @endif
                <form method="POST" action="{{ route('admin.backups.purge', $account) }}"
                      onsubmit="return confirm('PERMANENTLY delete the SFTPGo user and this account? Archived Azure blobs are kept.')">
                    @csrf @method('DELETE')<button class="btn btn-outline-danger w-100">Delete permanently</button>
                </form>
            </div>
        </div>
        @endcan
    </div>
</div>

@push('scripts')
<script>
(function () {
    const btn = document.getElementById('reveal-btn');
    if (! btn) return;
    let revealed = false;
    btn.addEventListener('click', function () {
        if (revealed) { location.reload(); return; }
        btn.disabled = true;
        fetch(btn.dataset.url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        })
        .then(r => r.json())
        .then(j => { document.getElementById('pw-mask').textContent = j.password || '(none)'; btn.textContent = 'Hide'; revealed = true; })
        .catch(() => { document.getElementById('pw-mask').textContent = '(error)'; })
        .finally(() => { btn.disabled = false; });
    });
})();
</script>
@endpush

@endsection
