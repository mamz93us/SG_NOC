@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-journal-code me-2 text-primary"></i>Syslog</h4>
        <small class="text-muted">Centralized log receiver — rsyslog → MySQL</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.syslog.sophos') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-shield-fill-check me-1"></i>Sophos viewer
        </a>
        <a href="{{ route('admin.syslog.ucm') }}" class="btn btn-outline-info btn-sm">
            <i class="bi bi-telephone-fill me-1"></i>UCM viewer
        </a>
        @can('manage-syslog')
        <a href="{{ route('admin.syslog.rules.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-bell me-1"></i>Alert Rules
        </a>
        @endcan
        <button type="button" class="btn btn-outline-secondary btn-sm" id="syslog-tail-toggle">
            <i class="bi bi-broadcast me-1"></i>Live Tail: <span id="tail-state">off</span>
        </button>
        @can('manage-syslog')
        <form method="POST" action="{{ route('admin.syslog.clear') }}" class="d-inline"
              onsubmit="var v = prompt('This will DELETE every row in syslog_messages.\n\nType CLEAR to confirm.'); if (v === 'CLEAR') { this.confirm.value = v; return true; } return false;">
            @csrf
            <input type="hidden" name="confirm" value="">
            <button type="submit" class="btn btn-outline-danger btn-sm" title="Wipe all syslog rows">
                <i class="bi bi-trash3 me-1"></i>Clear all
            </button>
        </form>
        @endcan
    </div>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-triangle me-2"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Stats row --}}
<div class="row g-2 mb-3">
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Total</div><div class="fs-5 fw-bold">{{ number_format($stats['total']) }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Critical (≤crit)</div><div class="fs-5 fw-bold text-danger">{{ number_format($stats['critical']) }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Errors</div><div class="fs-5 fw-bold text-danger">{{ number_format($stats['errors']) }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Warnings</div><div class="fs-5 fw-bold text-warning">{{ number_format($stats['warnings']) }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small">Unique hosts</div><div class="fs-5 fw-bold">{{ number_format($stats['unique_hosts']) }}</div></div></div></div>
    <div class="col-6 col-md-2"><div class="card border-0 shadow-sm"><div class="card-body py-2"><div class="text-muted small d-flex justify-content-between"><span>Parser backlog</span>@if($stats['parser_pending'] > 0)<i class="bi bi-hourglass-split text-warning" title="Rows tagged but not yet parsed"></i>@endif</div><div class="fs-5 fw-bold {{ $stats['parser_pending'] > 0 ? 'text-warning' : 'text-success' }}">{{ number_format($stats['parser_pending']) }}</div></div></div></div>
</div>

{{-- Filters --}}
<form method="GET" class="card shadow-sm border-0 mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Search message</label>
                <input type="search" name="search" value="{{ $filters['search'] }}"
                       class="form-control form-control-sm" placeholder="error, denied, …">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Host</label>
                <input type="text" name="host" value="{{ $filters['host'] }}"
                       class="form-control form-control-sm" placeholder="JED-FW">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Source type</label>
                <select name="source_type" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach($sourceTypes as $st)
                    <option value="{{ $st }}" {{ $filters['source_type'] === $st ? 'selected' : '' }}>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Severity ≤</label>
                <select name="severity" class="form-select form-select-sm">
                    <option value="">Any</option>
                    @foreach($severityNames as $val => $label)
                    <option value="{{ $val }}" {{ (string) $filters['severity'] === (string) $val ? 'selected' : '' }}>{{ $val }} — {{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">Program</label>
                <input type="text" name="program" value="{{ $filters['program'] }}"
                       class="form-control form-control-sm" placeholder="sshd">
            </div>
            <div class="col-md-1">
                <label class="form-label small mb-1">Since</label>
                <select name="since" class="form-select form-select-sm">
                    @foreach(['15m'=>'15m','1h'=>'1h','24h'=>'24h','7d'=>'7d','30d'=>'30d','all'=>'All'] as $v=>$l)
                    <option value="{{ $v }}" {{ $filters['since'] === $v ? 'selected' : '' }}>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1 d-grid">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> Filter</button>
            </div>
        </div>
    </div>
</form>

{{-- Live tail panel (initially hidden) --}}
<div id="tail-panel" class="card shadow-sm border-0 mb-3 d-none">
    <div class="card-header py-2 d-flex justify-content-between align-items-center bg-dark text-light">
        <span><i class="bi bi-broadcast me-2"></i>Live tail — newest first</span>
        <small class="text-muted-light">Polling every 3s</small>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm table-dark table-hover mb-0 small">
            <thead><tr><th class="ps-3" style="width:170px">When</th><th style="width:90px">Sev</th><th style="width:110px">Source</th><th style="width:160px">Host</th><th style="width:110px">Program</th><th>Message</th></tr></thead>
            <tbody id="tail-rows"></tbody>
        </table>
    </div>
</div>

{{-- Main results table --}}
<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        @if($messages->isEmpty())
        <div class="text-center py-5 text-muted">
            <i class="bi bi-inbox display-4 d-block mb-2"></i>
            No syslog messages match these filters.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width:170px">When</th>
                        <th style="width:90px">Severity</th>
                        <th style="width:110px">Source</th>
                        <th style="width:160px">Host</th>
                        <th style="width:140px">IP</th>
                        <th style="width:110px">Program</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($messages as $m)
                    <tr style="cursor:pointer" onclick="window.location='{{ route('admin.syslog.show', $m->id) }}'">
                        <td class="ps-3 text-nowrap text-muted">
                            <span title="{{ $m->received_at->toDateTimeString() }}">{{ $m->received_at->format('M d H:i:s') }}</span>
                        </td>
                        <td><span class="badge {{ $m->severityBadgeClass() }}">{{ $m->severityLabel() }}</span></td>
                        <td>
                            @if($m->source_type)
                            <span class="badge {{ $m->sourceTypeBadgeClass() }}">{{ $m->source_type }}</span>
                            @else
                            <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="fw-semibold text-truncate" style="max-width:160px" title="{{ $m->host }}">{{ $m->host }}</td>
                        <td class="text-muted font-monospace small">{{ $m->source_ip }}</td>
                        <td class="text-muted">{{ $m->program ?: '—' }}</td>
                        <td class="text-truncate" style="max-width:520px">{{ $m->message }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="px-3 py-2 border-top">{{ $messages->links() }}</div>
        @endif
    </div>
</div>

@push('scripts')
<script>
(function() {
    const btn      = document.getElementById('syslog-tail-toggle');
    const stateLbl = document.getElementById('tail-state');
    const panel    = document.getElementById('tail-panel');
    const tbody    = document.getElementById('tail-rows');
    const tailUrl  = @json(route('admin.syslog.tail'));
    const showUrl  = @json(route('admin.syslog.show', ['id' => 0])).replace(/0$/, '');

    let lastId = 0;
    let timer  = null;
    let running = false;

    function escapeHtml(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderRows(rows) {
        if (!rows.length) return;
        // newest first — prepend
        const html = rows.map(r => `
            <tr onclick="window.location='${showUrl}${r.id}'" style="cursor:pointer">
                <td class="ps-3 text-nowrap" title="${escapeHtml(r.received_at)}">${escapeHtml((r.received_at||'').substring(11,19))}</td>
                <td><span class="badge ${escapeHtml(r.severity_class)}">${escapeHtml(r.severity_label)}</span></td>
                <td>${r.source_type ? `<span class="badge ${escapeHtml(r.source_class)}">${escapeHtml(r.source_type)}</span>` : '<span class="text-muted">—</span>'}</td>
                <td class="fw-semibold text-truncate" style="max-width:160px" title="${escapeHtml(r.host)}">${escapeHtml(r.host)}</td>
                <td class="text-muted">${escapeHtml(r.program||'—')}</td>
                <td class="text-truncate" style="max-width:600px">${escapeHtml(r.message)}</td>
            </tr>
        `).join('');
        tbody.insertAdjacentHTML('afterbegin', html);
        // Trim to 200 rows max to keep the DOM snappy.
        while (tbody.children.length > 200) tbody.removeChild(tbody.lastChild);
    }

    async function poll() {
        try {
            const url = lastId ? `${tailUrl}?since_id=${lastId}` : tailUrl;
            const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();
            // Reverse so the chronologically oldest of the batch ends up
            // lowest (we prepend, so insert in reverse to keep newest at top).
            const rows = (data.rows || []).slice().sort((a,b) => a.id - b.id);
            renderRows(rows);
            if (data.last_id) lastId = data.last_id;
        } catch (e) { /* swallow polling errors */ }
    }

    btn.addEventListener('click', () => {
        running = !running;
        if (running) {
            panel.classList.remove('d-none');
            stateLbl.textContent = 'on';
            btn.classList.replace('btn-outline-secondary', 'btn-success');
            poll();
            timer = setInterval(poll, 3000);
        } else {
            stateLbl.textContent = 'off';
            btn.classList.replace('btn-success', 'btn-outline-secondary');
            clearInterval(timer);
            timer = null;
        }
    });
})();
</script>
@endpush
@endsection
