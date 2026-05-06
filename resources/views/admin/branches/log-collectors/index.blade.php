@extends('layouts.admin')

@section('title', 'Branch Log Collectors')

@php
    $diskBar = function (?int $pct) {
        if ($pct === null) return '<span class="text-muted small">—</span>';
        $cls = $pct >= 90 ? 'bg-danger'
             : ($pct >= 75 ? 'bg-warning' : 'bg-success');
        return sprintf(
            '<div class="progress" style="height:14px;min-width:80px;" title="%d%% used">
                <div class="progress-bar %s" role="progressbar" style="width:%d%%;">%d%%</div>
            </div>',
            $pct, $cls, $pct, $pct
        );
    };
    $fmtNum  = fn ($n) => $n === null ? '—' : number_format((int) $n);
    $fmtSize = fn ($g) => $g === null ? '—' : number_format((float) $g, 1) . ' GB';
@endphp

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
        <div class="d-flex gap-2">
            <button id="refreshAllBtn" class="btn btn-sm btn-outline-info"
                    data-url="{{ route('admin.branches.log-collectors.refresh-all') }}"
                    title="Probe every enabled branch in parallel">
                <i class="bi bi-arrow-clockwise me-1"></i>Refresh all stats
            </button>
            <a href="{{ route('admin.branches.log-collectors.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add branch
            </a>
        </div>
    </div>

    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px;">Code</th>
                        <th>Name</th>
                        <th style="width:160px;">Host</th>
                        <th style="width:60px;">Enabled</th>
                        <th style="width:100px;">Status</th>
                        <th style="width:120px;">Disk</th>
                        <th style="width:90px;">DB size</th>
                        <th style="width:90px;text-align:right;">Rows</th>
                        <th style="width:80px;text-align:right;" title="Messages received in the last 5 minutes">5-min</th>
                        <th style="width:140px;">Last seen</th>
                        <th style="width:200px;">Actions</th>
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
                        <td class="disk-cell">{!! $diskBar($c->last_disk_used_pct) !!}</td>
                        <td class="dbsize-cell font-monospace small">{{ $fmtSize($c->last_db_size_gb) }}</td>
                        <td class="dbrows-cell font-monospace small text-end">{{ $fmtNum($c->last_db_rows) }}</td>
                        <td class="rows5-cell font-monospace small text-end">{{ $fmtNum($c->last_rows_5min) }}</td>
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
                        <td colspan="11" class="text-center text-muted py-5">
                            No branches configured yet.
                            <a href="{{ route('admin.branches.log-collectors.create') }}">Add the first one</a>.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <p class="small text-muted mt-3 mb-0">
        Tip: <strong>Disk %</strong> turns yellow at 75% and red at 90%. The
        <strong>5-min</strong> column shows how many syslog rows landed in the last 5 minutes
        — a sustained 0 here usually means devices stopped sending or rsyslog stopped writing.
    </p>
</div>

<script>
(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function diskBarHtml(pct) {
        if (pct == null) return '<span class="text-muted small">—</span>';
        const cls = pct >= 90 ? 'bg-danger' : (pct >= 75 ? 'bg-warning' : 'bg-success');
        return `<div class="progress" style="height:14px;min-width:80px;" title="${pct}% used">
                    <div class="progress-bar ${cls}" role="progressbar" style="width:${pct}%;">${pct}%</div>
                </div>`;
    }
    function fmtNum(n)  { return n == null ? '—' : Number(n).toLocaleString(); }
    function fmtSize(g) { return g == null ? '—' : Number(g).toFixed(1) + ' GB'; }

    function applyStats(rowId, payload) {
        const row = document.querySelector('#collector-' + rowId);
        if (!row) return;

        // status badge
        let badge = '<span class="badge bg-secondary">unknown</span>';
        const s = payload.status;
        if (s === 'healthy')           badge = '<span class="badge bg-success">healthy</span>';
        else if (s === 'unreachable')  badge = '<span class="badge bg-danger">unreachable</span>';
        else if (s === 'unauthorized') badge = '<span class="badge bg-warning text-dark">unauthorized</span>';
        else if (s === 'error')        badge = '<span class="badge bg-danger">error</span>';
        row.querySelector('.status-cell').innerHTML = badge;

        if (payload.last_seen_at) {
            row.querySelector('.last-seen-cell').textContent = payload.last_seen_at;
        }

        const stats = payload.stats || {};
        if (stats.disk)      row.querySelector('.disk-cell').innerHTML   = diskBarHtml(stats.disk.used_pct);
        if (stats.db) {
            row.querySelector('.dbsize-cell').textContent  = fmtSize(stats.db.size_gb);
            row.querySelector('.dbrows-cell').textContent  = fmtNum(stats.db.rows);
        }
        if (stats.ingestion) row.querySelector('.rows5-cell').textContent = fmtNum(stats.ingestion.rows_last_5min);
    }

    // Single-row Test
    document.querySelectorAll('.test-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const id   = btn.dataset.id;
            const url  = btn.dataset.url;
            const old  = btn.innerHTML;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            btn.disabled = true;
            try {
                const r = await fetch(url, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                const data = await r.json();
                applyStats(id, data);
            } catch (e) {
                document.querySelector('#collector-' + id + ' .status-cell').innerHTML =
                    '<span class="badge bg-danger">request failed</span>';
            } finally {
                btn.innerHTML = old;
                btn.disabled = false;
            }
        });
    });

    // Refresh-all (server fans out)
    const refreshAll = document.getElementById('refreshAllBtn');
    if (refreshAll) {
        refreshAll.addEventListener('click', async () => {
            const url  = refreshAll.dataset.url;
            const old  = refreshAll.innerHTML;
            refreshAll.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Refreshing…';
            refreshAll.disabled = true;
            try {
                await fetch(url, {
                    method:  'POST',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                });
                // Server has updated the DB; easiest UI refresh is a reload.
                window.location.reload();
            } finally {
                refreshAll.innerHTML = old;
                refreshAll.disabled  = false;
            }
        });
    }
})();
</script>
@endsection
