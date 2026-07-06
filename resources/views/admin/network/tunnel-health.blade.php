@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 mb-1">Branch Tunnel Health</h2>
        <p class="text-muted small mb-0">
            Live ICMP reachability to each branch firewall — confirms the Azure VPN tunnel is actually carrying traffic.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="th-updated" class="text-muted small"></span>
        <button id="th-ping-now" class="btn btn-primary">
            <i class="bi bi-arrow-repeat me-1"></i> Ping now
        </button>
    </div>
</div>

<div class="d-flex gap-2 mb-3">
    <span class="badge bg-success" id="th-count-up">0 up</span>
    <span class="badge bg-danger" id="th-count-down">0 down</span>
    <span class="badge bg-secondary" id="th-count-total">0 total</span>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Branch</th>
                    <th>Tunnel</th>
                    <th>Firewall IP</th>
                    <th>Status</th>
                    <th>Latency</th>
                    <th>Last checked</th>
                </tr>
            </thead>
            <tbody id="th-body">
                <tr><td colspan="6" class="text-center text-muted py-4">Loading…</td></tr>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    The firewall IP is each tunnel's explicit ping target, or the first host of its remote subnet (the branch Sophos at <code>10.x.0.1</code>).
    Set an explicit target per branch under <a href="{{ route('admin.network.vpn.index') }}">VPN Hub</a> → edit tunnel.
    Auto-refreshes every 20&nbsp;seconds; the background job also re-pings every minute.
</p>

<script>
(function () {
    const DATA_URL = @json(route('admin.network.tunnel-health.data'));
    const PING_URL = @json(route('admin.network.tunnel-health.ping'));
    const CSRF     = @json(csrf_token());
    let rows       = @json($rows);

    const esc = s => String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    function badge(status) {
        if (status === 'up')   return '<span class="badge bg-success">Up</span>';
        if (status === 'down') return '<span class="badge bg-danger">Down</span>';
        return '<span class="badge bg-secondary">Unknown</span>';
    }

    function render() {
        const body = document.getElementById('th-body');
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">No VPN tunnels configured. Add them in the VPN Hub — the firewall IP comes from each tunnel\'s remote subnet or explicit ping target.</td></tr>';
        } else {
            body.innerHTML = rows.map(r => `<tr>
                <td class="fw-semibold">${esc(r.branch)}</td>
                <td class="text-muted">${esc(r.name)}</td>
                <td><code>${esc(r.target)}</code></td>
                <td>${badge(r.status)}</td>
                <td>${r.status === 'up' && r.latency_ms != null ? esc(r.latency_ms) + ' ms' : '<span class="text-muted">—</span>'}</td>
                <td class="text-muted small">${r.checked ? esc(r.checked) : 'never'}</td>
            </tr>`).join('');
        }
        document.getElementById('th-count-up').textContent    = rows.filter(r => r.status === 'up').length + ' up';
        document.getElementById('th-count-down').textContent  = rows.filter(r => r.status === 'down').length + ' down';
        document.getElementById('th-count-total').textContent = rows.length + ' total';
    }

    function stamp() {
        document.getElementById('th-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    async function refresh() {
        try {
            const res = await fetch(DATA_URL, { headers: { 'Accept': 'application/json' } });
            const j = await res.json();
            rows = j.rows;
            render();
            stamp();
        } catch (e) { /* transient — keep last snapshot */ }
    }

    const btn = document.getElementById('th-ping-now');
    btn.addEventListener('click', async () => {
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Pinging…';
        try {
            const res = await fetch(PING_URL, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } });
            const j = await res.json();
            rows = j.rows;
            render();
            stamp();
        } catch (e) { /* ignore, next auto-refresh recovers */ }
        finally {
            btn.disabled = false;
            btn.innerHTML = orig;
        }
    });

    render();
    stamp();
    setInterval(refresh, 20000);
})();
</script>
@endsection
