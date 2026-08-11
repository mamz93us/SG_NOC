@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 mb-1">Branch Tunnel Watchdog</h2>
        <p class="text-muted small mb-0" style="max-width: 62ch;">
            Probes every branch tunnel on the Azure VPN gateway once a minute — the firewall
            <em>and</em> each subnet the tunnel should carry. A tunnel whose firewall answers while
            one of its subnets is dark shows as <strong>Degraded</strong>, not up.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="tw-updated" class="text-muted small"></span>
        <a href="{{ route('admin.network.tunnel-health.history') }}" class="btn btn-outline-secondary">
            <i class="bi bi-calendar-week me-1"></i> 7 day history
        </a>
        <button id="tw-check-now" class="btn btn-primary">
            <i class="bi bi-arrow-repeat me-1"></i> Check now
        </button>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif
@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">{{ $errors->first() }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

{{-- Roll-up counters --}}
<div class="row g-2 mb-3" id="tw-summary">
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Up</div>
                <div class="h3 mb-0 text-success" data-sum="up">—</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Degraded</div>
                <div class="h3 mb-0 text-warning" data-sum="degraded">—</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Down</div>
                <div class="h3 mb-0 text-danger" data-sum="down">—</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-body py-3">
                <div class="text-muted small text-uppercase" style="letter-spacing:.04em;">Tunnels</div>
                <div class="h3 mb-0" data-sum="total">—</div>
            </div>
        </div>
    </div>
</div>

{{-- Tunnel cards --}}
<div id="tw-tunnels">
    @forelse($tunnels as $t)
        <div class="card shadow-sm mb-3" data-tunnel="{{ $t->id }}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge {{ $t->stateBadgeClass() }}" data-cell="state">{{ $t->stateLabel() }}</span>
                        <span class="fw-semibold">{{ $t->name }}</span>
                        @if($t->branch)
                            <span class="text-muted small">{{ $t->branch->name }}</span>
                        @endif
                        <code class="small text-muted">{{ $t->firewall_ip }}</code>
                        @unless($t->is_active)
                            <span class="badge bg-secondary">Paused</span>
                        @endunless
                    </div>
                    <div class="d-flex align-items-center gap-3 small text-muted">
                        <span data-cell="probes">—</span>
                        <span data-cell="latency">—</span>
                        <span data-cell="uptime">—</span>
                        @can('manage-network-settings')
                        <div class="dropdown">
                            <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="dropdown">
                                <i class="bi bi-three-dots"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <button class="dropdown-item tw-edit-tunnel"
                                            data-id="{{ $t->id }}" data-name="{{ $t->name }}"
                                            data-ip="{{ $t->firewall_ip }}" data-branch="{{ $t->branch_id }}"
                                            data-active="{{ $t->is_active ? 1 : 0 }}"
                                            data-bs-toggle="modal" data-bs-target="#tw-tunnel-modal">
                                        <i class="bi bi-pencil me-2"></i>Edit tunnel
                                    </button>
                                </li>
                                <li>
                                    <button class="dropdown-item tw-add-probe" data-id="{{ $t->id }}" data-name="{{ $t->name }}"
                                            data-bs-toggle="modal" data-bs-target="#tw-probe-modal">
                                        <i class="bi bi-plus-lg me-2"></i>Add probe
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form action="{{ route('admin.network.tunnel-health.destroy', $t) }}" method="POST"
                                          onsubmit="return confirm('Remove {{ $t->name }} and all its probes?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Remove tunnel</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                        @endcan
                    </div>
                </div>

                {{-- Strip chart: one tick per watchdog cycle, oldest left --}}
                <div class="d-flex align-items-center gap-2 mb-1">
                    <div class="tw-strip flex-grow-1" data-cell="strip"></div>
                    <span class="text-muted small text-nowrap" data-cell="since"></span>
                </div>

                {{-- Probes --}}
                <table class="table table-sm align-middle mb-0 mt-2" data-cell="probes-table">
                    <tbody>
                        @forelse($t->probes as $p)
                            <tr data-probe="{{ $p->id }}">
                                <td style="width:1%" data-cell="probe-status">
                                    <span class="badge {{ $p->statusBadgeClass() }}">{{ ucfirst($p->status ?? 'unknown') }}</span>
                                </td>
                                <td>
                                    {{ $p->label }}
                                    <span class="text-muted small d-block" data-cell="probe-note">{{ $p->result_note }}</span>
                                </td>
                                <td><code class="small">{{ $p->targetLabel() }}</code></td>
                                <td class="text-muted small text-uppercase">{{ $p->check_type }}</td>
                                <td class="text-muted small" data-cell="probe-latency">—</td>
                                <td class="text-muted small" data-cell="probe-checked">—</td>
                                @can('manage-network-settings')
                                <td class="text-end text-nowrap" style="width:1%">
                                    <button type="button" class="btn btn-sm btn-link text-secondary p-0 me-2 tw-edit-probe"
                                            data-id="{{ $p->id }}" data-label="{{ $p->label }}" data-target="{{ $p->target }}"
                                            data-type="{{ $p->check_type }}" data-port="{{ $p->port }}"
                                            data-active="{{ $p->is_active ? 1 : 0 }}"
                                            data-bs-toggle="modal" data-bs-target="#tw-probe-modal">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form action="{{ route('admin.network.tunnel-health.probes.destroy', $p) }}" method="POST" class="d-inline"
                                          onsubmit="return confirm('Remove probe {{ $p->label }}?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                                @endcan
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-muted small py-2">
                                    <i class="bi bi-exclamation-triangle text-warning me-1"></i>
                                    Gateway ping only — this tunnel can look healthy while a subnet behind it is unreachable.
                                    @can('manage-network-settings')
                                        Add a probe for each subnet it carries.
                                    @endcan
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="card shadow-sm">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-shield-slash fs-1 d-block mb-2 opacity-50"></i>
                No tunnels yet.
                @can('manage-network-settings')
                    Add one below with its branch firewall IP, then add a probe per subnet it carries.
                @endcan
            </div>
        </div>
    @endforelse
</div>

{{-- Add tunnel --}}
@can('manage-network-settings')
<div class="card shadow-sm mt-3">
    <div class="card-header bg-transparent"><strong class="small text-uppercase text-muted">Add tunnel</strong></div>
    <div class="card-body">
        <form action="{{ route('admin.network.tunnel-health.store') }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-sm-4">
                <label class="form-label small mb-1">Name</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. JED" required>
            </div>
            <div class="col-sm-3">
                <label class="form-label small mb-1">Firewall IP <span class="text-muted">(gateway)</span></label>
                <input type="text" name="firewall_ip" class="form-control" placeholder="e.g. 10.1.0.1" required>
            </div>
            <div class="col-sm-3">
                <label class="form-label small mb-1">Branch <span class="text-muted">(optional)</span></label>
                <select name="branch_id" class="form-select">
                    <option value="">—</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
        </form>
    </div>
</div>
@endcan

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Board refreshes every 15&nbsp;seconds; the watchdog re-probes every minute and opens a NOC event
    (and notifies) on transition to <strong>Down</strong> or <strong>Degraded</strong>.
</p>

{{-- ─── Modals ─────────────────────────────────────────────────── --}}
@can('manage-network-settings')
<div class="modal fade" id="tw-tunnel-modal" tabindex="-1">
    <div class="modal-dialog">
        <form id="tw-tunnel-form" method="POST">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit tunnel</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small mb-1">Name</label>
                        <input type="text" name="name" id="tw-t-name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Firewall IP (gateway)</label>
                        <input type="text" name="firewall_ip" id="tw-t-ip" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Branch</label>
                        <select name="branch_id" id="tw-t-branch" class="form-select">
                            <option value="">—</option>
                            @foreach($branches as $b)
                                <option value="{{ $b->id }}">{{ $b->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="tw-t-active">
                        <label class="form-check-label small" for="tw-t-active">Actively watched</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="tw-probe-modal" tabindex="-1">
    <div class="modal-dialog">
        <form id="tw-probe-form" method="POST">
            @csrf
            <input type="hidden" name="_method" id="tw-p-method" value="POST">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="tw-p-title">Add probe</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted small">
                        A probe is something you expect to reach through this tunnel. Give it one per
                        carried subnet — the gateway alone can't prove a subnet is routed — and a
                        SIP/RTP pair per UCM, since a policy can pass ICMP and TCP while dropping voice.
                    </p>
                    <div class="mb-3">
                        <label class="form-label small mb-1">Label</label>
                        <input type="text" name="label" id="tw-p-label" class="form-control" placeholder="e.g. Voice VLAN core" required>
                    </div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small mb-1">Target IP</label>
                            <input type="text" name="target" id="tw-p-target" class="form-control" placeholder="e.g. 10.1.8.10" required>
                        </div>
                        <div class="col-5">
                            <label class="form-label small mb-1">Check</label>
                            <select name="check_type" id="tw-p-type" class="form-select">
                                @foreach($checkTypes as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="mb-3 mt-2 d-none" id="tw-p-port-wrap">
                        <label class="form-label small mb-1">Port</label>
                        <input type="number" name="port" id="tw-p-port" class="form-control" min="1" max="65535" placeholder="e.g. 8089">
                        <div class="form-text small" id="tw-p-port-help">TCP connect only — nothing is sent on the socket.</div>
                    </div>
                    <div class="form-check mt-3">
                        <input type="hidden" name="is_active" value="0">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="tw-p-active" checked>
                        <label class="form-check-label small" for="tw-p-active">Actively probed</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endcan

<style>
.tw-strip { display:flex; gap:2px; align-items:flex-end; height:22px; }
.tw-strip span { flex:1 1 0; min-width:2px; height:100%; border-radius:2px; background:var(--bs-secondary-bg, #dee2e6); }
.tw-strip span.up { background:var(--bs-success); }
.tw-strip span.degraded { background:var(--bs-warning); }
.tw-strip span.down { background:var(--bs-danger); }
.tw-strip span.gap { background:transparent; }
</style>

<script>
(function () {
    const DATA_URL  = @json(route('admin.network.tunnel-health.data'));
    const CHECK_URL = @json(route('admin.network.tunnel-health.check'));
    const BASE      = @json(url('admin/network/tunnel-health'));
    const CSRF      = @json(csrf_token());
    const STRIP_LEN = 60;

    const stateClass = { up: 'bg-success', degraded: 'bg-warning text-dark', down: 'bg-danger' };

    function badge(status, label) {
        const cls = stateClass[status] || 'bg-secondary';
        const text = label || (status.charAt(0).toUpperCase() + status.slice(1));
        return `<span class="badge ${cls}">${text}</span>`;
    }

    function renderStrip(el, states) {
        // Right-align history so the newest tick always sits at the right edge.
        const pad = Math.max(0, STRIP_LEN - states.length);
        el.innerHTML = Array(pad).fill('<span class="gap"></span>')
            .concat(states.map(s => `<span class="${s}" title="${s}"></span>`))
            .join('');
    }

    function apply(payload) {
        const rows = payload.rows || [];

        (Object.entries(payload.summary || {})).forEach(([k, v]) => {
            const el = document.querySelector(`#tw-summary [data-sum="${k}"]`);
            if (el) el.textContent = v;
        });

        rows.forEach(r => {
            const card = document.querySelector(`[data-tunnel="${r.id}"]`);
            if (!card) return;

            const set = (cell, html, isHtml) => {
                const el = card.querySelector(`[data-cell="${cell}"]`);
                if (!el) return;
                if (isHtml) el.innerHTML = html; else el.textContent = html;
            };

            set('state', badge(r.state, r.state_label), true);
            set('probes', r.probes_total ? `${r.probes_up}/${r.probes_total} probes` : 'no probes');
            set('latency', r.latency_ms != null ? `${r.latency_ms} ms` : '—');
            set('uptime', r.uptime_24h != null ? `${r.uptime_24h}% / 24h` : '—');
            set('since', r.since ? `${r.state_label} for ${r.since}` : '');

            const strip = card.querySelector('[data-cell="strip"]');
            if (strip) renderStrip(strip, r.strip || []);

            (r.probes || []).forEach(p => {
                const tr = card.querySelector(`[data-probe="${p.id}"]`);
                if (!tr) return;
                const sc = tr.querySelector('[data-cell="probe-status"]');
                if (sc) sc.innerHTML = badge(p.status);
                const lc = tr.querySelector('[data-cell="probe-latency"]');
                if (lc) lc.textContent = p.latency_ms != null ? `${p.latency_ms} ms` : '—';
                const cc = tr.querySelector('[data-cell="probe-checked"]');
                if (cc) cc.textContent = p.checked || 'never';
                const nc = tr.querySelector('[data-cell="probe-note"]');
                if (nc) nc.textContent = p.note || '';
            });
        });

        document.getElementById('tw-updated').textContent = 'Updated ' + new Date().toLocaleTimeString();
    }

    async function refresh() {
        try {
            const r = await fetch(DATA_URL, { headers: { 'Accept': 'application/json' } });
            apply(await r.json());
        } catch (e) { /* transient — next tick retries */ }
    }

    const btn = document.getElementById('tw-check-now');
    btn.addEventListener('click', async () => {
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Probing…';
        try {
            const r = await fetch(CHECK_URL, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } });
            apply(await r.json());
        } catch (e) { /* leave the board as-is */ }
        finally { btn.disabled = false; btn.innerHTML = orig; }
    });

    // ── Modal wiring ──────────────────────────────────────────────
    const portWrap = document.getElementById('tw-p-port-wrap');
    const portHelp = document.getElementById('tw-p-port-help');
    const portIn   = document.getElementById('tw-p-port');
    const typeSel  = document.getElementById('tw-p-type');

    // Voice is why these tunnels exist and it rides on UDP, so the two UDP types
    // get an explanation of what a pass actually proves — especially RTP, where
    // "port closed" is the healthy answer.
    const PORT_HELP = {
        tcp: 'TCP connect only — nothing is sent on the socket.',
        sip: 'A real SIP OPTIONS ping is sent over UDP (port 5060). Passes only when the far end answers SIP/2.0 — '
           + 'a Grandstream UCM must be told to accept SIP requests from the NOC first, or this stays red with the tunnel perfectly healthy.',
        udp: 'For RTP/media ports. Passes on a reply <em>or</em> an ICMP port-unreachable — either proves UDP crosses the tunnel. '
           + 'Silence means it does not. Keep to one media probe per host: the far kernel rate-limits ICMP replies.',
    };

    function syncPortVisibility() {
        if (!portWrap || !typeSel) return;
        const needsPort = typeSel.value !== 'icmp';
        portWrap.classList.toggle('d-none', !needsPort);
        if (needsPort && portHelp) portHelp.innerHTML = PORT_HELP[typeSel.value] || '';
        // Offer 5060 for SIP rather than making everyone type it.
        if (typeSel.value === 'sip' && portIn && !portIn.value) portIn.value = 5060;
    }
    if (typeSel) typeSel.addEventListener('change', syncPortVisibility);

    document.querySelectorAll('.tw-edit-tunnel').forEach(b => b.addEventListener('click', () => {
        document.getElementById('tw-t-name').value   = b.dataset.name;
        document.getElementById('tw-t-ip').value     = b.dataset.ip;
        document.getElementById('tw-t-branch').value = b.dataset.branch || '';
        document.getElementById('tw-t-active').checked = b.dataset.active === '1';
        document.getElementById('tw-tunnel-form').action = `${BASE}/${b.dataset.id}`;
    }));

    document.querySelectorAll('.tw-add-probe').forEach(b => b.addEventListener('click', () => {
        document.getElementById('tw-p-title').textContent = `Add probe — ${b.dataset.name}`;
        document.getElementById('tw-p-method').value = 'POST';
        document.getElementById('tw-p-label').value  = '';
        document.getElementById('tw-p-target').value = '';
        document.getElementById('tw-p-port').value   = '';
        typeSel.value = 'icmp';
        document.getElementById('tw-p-active').checked = true;
        document.getElementById('tw-probe-form').action = `${BASE}/${b.dataset.id}/probes`;
        syncPortVisibility();
    }));

    document.querySelectorAll('.tw-edit-probe').forEach(b => b.addEventListener('click', () => {
        document.getElementById('tw-p-title').textContent = 'Edit probe';
        document.getElementById('tw-p-method').value = 'PUT';
        document.getElementById('tw-p-label').value  = b.dataset.label;
        document.getElementById('tw-p-target').value = b.dataset.target;
        document.getElementById('tw-p-port').value   = b.dataset.port || '';
        typeSel.value = b.dataset.type || 'icmp';
        document.getElementById('tw-p-active').checked = b.dataset.active === '1';
        document.getElementById('tw-probe-form').action = `${BASE}/probes/${b.dataset.id}`;
        syncPortVisibility();
    }));

    apply({ rows: @json($rows), summary: {} });
    refresh();
    setInterval(refresh, 15000);
})();
</script>
@endsection
