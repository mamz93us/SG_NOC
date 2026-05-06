@extends('layouts.admin')

@section('title', 'Branch Log Collectors')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Branch Log Collectors</h4>
            <small class="text-muted">
                Per-branch VMs running rsyslog + MariaDB + the search API.
                See <code>deployment/branch-vm/README.md</code> for VM-side setup.
            </small>
        </div>
        <a href="{{ route('admin.branches.log-collectors.create') }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Add branch
        </a>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:80px;">Code</th>
                        <th>Name</th>
                        <th>Host : Port</th>
                        <th style="width:90px;">Enabled</th>
                        <th style="width:120px;">Status</th>
                        <th style="width:170px;">Last seen</th>
                        <th style="width:230px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($collectors as $c)
                    <tr id="collector-{{ $c->id }}">
                        <td><code>{{ $c->code }}</code></td>
                        <td>{{ $c->name }}</td>
                        <td class="font-monospace small">{{ $c->host }}:{{ $c->port }}</td>
                        <td>
                            @if($c->enabled)
                                <span class="badge bg-success">on</span>
                            @else
                                <span class="badge bg-secondary">off</span>
                            @endif
                        </td>
                        <td class="status-cell">
                            @include('admin.branches.log-collectors._status', ['c' => $c])
                        </td>
                        <td class="last-seen-cell small text-muted">
                            {{ $c->last_seen_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-primary test-btn"
                                    data-id="{{ $c->id }}"
                                    data-url="{{ route('admin.branches.log-collectors.test', $c) }}">
                                <i class="bi bi-plug"></i> Test
                            </button>
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('admin.branches.log-collectors.edit', $c) }}">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="{{ route('admin.branches.log-collectors.destroy', $c) }}"
                                  method="POST" class="d-inline"
                                  onsubmit="return confirm('Remove branch &quot;{{ $c->code }}&quot;? Logs on the VM are NOT deleted.');">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger" type="submit">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            No branches configured yet.
                            <a href="{{ route('admin.branches.log-collectors.create') }}">Add the first one</a>.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <p class="small text-muted mt-3 mb-0">
        Tip: when a branch VM is provisioned, <code>install.sh</code> prints
        an <code>API_TOKEN</code>. Click <em>Add branch</em> here, paste it
        into the token field, then <em>Test</em> to verify the tunnel.
    </p>
</div>

<script>
(function () {
    document.querySelectorAll('.test-btn').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const id  = btn.dataset.id;
            const url = btn.dataset.url;
            const statusCell  = document.querySelector('#collector-' + id + ' .status-cell');
            const lastSeenCell = document.querySelector('#collector-' + id + ' .last-seen-cell');
            const oldText = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btn.disabled = true;

            try {
                const r = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await r.json();

                let badge = '<span class="badge bg-secondary">unknown</span>';
                if (data.status === 'healthy')      badge = '<span class="badge bg-success">healthy</span>';
                else if (data.status === 'unreachable')  badge = '<span class="badge bg-danger">unreachable</span>';
                else if (data.status === 'unauthorized') badge = '<span class="badge bg-warning text-dark">unauthorized</span>';
                else                                     badge = '<span class="badge bg-danger">error</span>';
                statusCell.innerHTML = badge;

                if (data.last_seen_at) lastSeenCell.textContent = data.last_seen_at;
            } catch (err) {
                statusCell.innerHTML = '<span class="badge bg-danger">request failed</span>';
            } finally {
                btn.innerHTML = oldText;
                btn.disabled = false;
            }
        });
    });
})();
</script>
@endsection
