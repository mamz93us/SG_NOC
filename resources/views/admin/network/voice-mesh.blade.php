@extends('layouts.admin')

@section('title', 'Voice Mesh')

@section('content')
<div class="container-fluid py-3">

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
        <div>
            <h4 class="mb-1"><i class="bi bi-telephone-outbound me-2"></i>Voice Mesh</h4>
            <p class="text-muted small mb-0" style="max-width: 70ch;">
                Every branch dials every other branch's test IVR, and the returned audio is compared against
                the reference prompt. This measures <strong>the NOC's SIP registration to each UCM</strong> plus
                <strong>the media path between UCMs</strong> — not a branch's own LAN. A fault between a
                branch's phones and its own UCM is invisible here, and a problem on the NOC itself will paint
                the whole board red while every branch is fine.
            </p>
        </div>
        <div class="text-end">
            @can('manage-voice-mesh')
                <form method="POST" action="{{ route('admin.network.voice-mesh.sweep') }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary btn-sm" data-sweep-btn {{ $pendingSweep ? 'disabled' : '' }}>
                        <i class="bi bi-arrow-repeat me-1"></i>Run a sweep now
                    </button>
                </form>
            @endcan
            <a href="{{ route('admin.network.voice-mesh.runs') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-clock-history me-1"></i>Run log
            </a>
            <div class="small text-muted mt-2">
                Next sweep <span data-next-due>{{ $last_run['next_due'] ?? '—' }}</span>
            </div>
        </div>
    </div>

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2">
            {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if ($errors->any())
        <div class="alert alert-danger py-2">{{ $errors->first() }}</div>
    @endif

    @if (session('voice_mesh_secret'))
        <div class="alert alert-warning">
            <div class="fw-semibold mb-1"><i class="bi bi-key me-1"></i>New ingest secret — copy it now</div>
            <code class="user-select-all">{{ session('voice_mesh_secret') }}</code>
            <div class="small mt-2 mb-0">
                Put this in <code>NOC_SECRET</code> in <code>deployment/voice-mesh/config.conf</code> on this
                host and restart the timer. Until you do, every sweep will be rejected with a 401 and the
                board will go stale.
            </div>
        </div>
    @endif

    <div class="alert alert-info py-2 {{ $pendingSweep ? '' : 'd-none' }}" data-pending-sweep>
        <span class="spinner-border spinner-border-sm me-2"></span>
        <span data-pending-text>
            {{ $pendingSweep && $pendingSweep['scope']
                ? 'Retry of '.str_replace('>', ' → ', $pendingSweep['scope']).' requested '.$pendingSweep['requested'].'.'
                : 'Full sweep requested '.($pendingSweep['requested'] ?? '').'.' }}
        </span>
        The prober collects it on its next wake — a full mesh takes a few minutes to complete.
    </div>

    @if (! $secretIsSet)
        <div class="alert alert-danger py-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            No ingest secret is configured, so the prober cannot post results. Generate one below.
        </div>
    @endif

    @if (! empty($last_run['unknown_nodes']))
        <div class="alert alert-warning py-2">
            <i class="bi bi-question-circle me-1"></i>
            The last sweep named branch codes that don't exist here:
            <strong>{{ implode(', ', $last_run['unknown_nodes']) }}</strong>.
            Those legs were discarded — check for a typo, or add the branch below.
        </div>
    @endif

    {{-- ── Summary ─────────────────────────────────────────────── --}}
    <div class="row g-3 mb-3">
        @foreach ([
            ['ok', 'Legs OK', 'success', 'bi-check-circle'],
            ['fail', 'Legs failing', 'danger', 'bi-x-circle'],
            ['unknown', 'Not yet tested', 'secondary', 'bi-dash-circle'],
            ['nodes', 'Active branches', 'primary', 'bi-diagram-3'],
        ] as [$key, $label, $colour, $icon])
            <div class="col-6 col-lg-3">
                <div class="card h-100">
                    <div class="card-body py-3">
                        <div class="text-muted small text-uppercase">{{ $label }}</div>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi {{ $icon }} text-{{ $colour }} fs-4"></i>
                            <span class="fs-3 fw-semibold" data-sum="{{ $key }}">{{ $summary[$key] }}</span>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── Matrix ──────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Caller → destination</span>
            <span class="small text-muted">
                Last sweep <span data-last-age>{{ $last_run['age'] ?? 'never' }}</span>
            </span>
        </div>
        <div class="card-body p-0">
            @if (count($nodes) < 2)
                <p class="text-muted p-4 mb-0">
                    A mesh needs at least two branches. Add them below — each needs its own UCM address,
                    a probe extension to register as, and the IVR extension other branches will dial.
                </p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm vm-matrix mb-0">
                        <thead>
                            <tr>
                                <th class="vm-corner">from ╲ to</th>
                                @foreach ($nodes as $dest)
                                    <th class="text-center" title="IVR extension {{ $dest['ivr_ext'] }}">
                                        {{ $dest['code'] }}
                                        <div class="fw-normal text-muted small">{{ $dest['ivr_ext'] }}</div>
                                    </th>
                                @endforeach
                                <th class="text-center text-muted">can call out</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nodes as $caller)
                                <tr>
                                    <th class="vm-rowhead">
                                        {{ $caller['code'] }}
                                        <span class="badge {{ $caller['badge'] }} ms-1">{{ $caller['state_label'] }}</span>
                                    </th>
                                    @foreach ($nodes as $dest)
                                        @if ($caller['id'] === $dest['id'])
                                            <td class="vm-cell vm-self">—</td>
                                        @else
                                            @php $cell = $cells["{$caller['id']}:{$dest['id']}"] ?? null; @endphp
                                            <td class="vm-cell vm-{{ $cell['status'] ?? 'unknown' }}"
                                                data-cell="{{ $caller['id'] }}:{{ $dest['id'] }}"
                                                title="{{ $cell['title'] ?? 'Not yet tested' }}">
                                                @if ($cell)
                                                    <a href="{{ $cell['url'] }}" class="vm-link">
                                                        <span class="vm-glyph">{!! $cell['status'] === 'ok' ? '&check;' : ($cell['status'] === 'fail' ? '&cross;' : '&middot;') !!}</span>
                                                        @if ($cell['drift_pct'] !== null && $cell['status'] === 'ok')
                                                            <span class="vm-sub">{{ $cell['drift_pct'] }}%</span>
                                                        @endif
                                                    </a>
                                                @else
                                                    <span class="vm-glyph">&middot;</span>
                                                @endif
                                            </td>
                                        @endif
                                    @endforeach
                                    @php $rt = $row_totals[$caller['id']] ?? null; @endphp
                                    <td class="text-center small text-muted" data-rowtotal="{{ $caller['id'] }}">
                                        {{ $rt ? "{$rt['ok']}/{$rt['total']}" : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                            <tr>
                                <th class="vm-rowhead text-muted small">can be reached</th>
                                @foreach ($nodes as $dest)
                                    @php $ct = $col_totals[$dest['id']] ?? null; @endphp
                                    <td class="text-center small text-muted" data-coltotal="{{ $dest['id'] }}">
                                        {{ $ct ? "{$ct['ok']}/{$ct['total']}" : '—' }}
                                    </td>
                                @endforeach
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="px-3 py-2 border-top small text-muted">
                    A whole row red means that branch cannot place calls — usually its UCM refusing the NOC's
                    SIP registration. A whole column red means that branch's IVR is unreachable. One cell red
                    is a path problem between those two branches. Click a cell for its history.
                </div>
            @endif
        </div>
    </div>

    {{-- ── Branches ────────────────────────────────────────────── --}}
    <div class="card mb-4">
        <div class="card-header fw-semibold">Branches in the mesh</div>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th><th>Name</th><th>UCM</th><th>Registers as</th>
                        <th>IVR ext</th><th>State</th><th>Active</th>
                        @can('manage-voice-mesh')<th class="text-end">Actions</th>@endcan
                    </tr>
                </thead>
                <tbody>
                    @forelse ($nodeRows as $node)
                        <tr>
                            <td class="fw-semibold">{{ $node->code }}</td>
                            <td>{{ $node->name }}<br><span class="small text-muted">{{ $node->branch?->name }}</span></td>
                            <td class="font-monospace small">{{ $node->sip_server }}:{{ $node->sip_port }}</td>
                            <td class="font-monospace small">{{ $node->sip_user }}</td>
                            <td class="font-monospace small">{{ $node->ivr_ext }}</td>
                            <td><span class="badge {{ $node->stateBadgeClass() }}">{{ $node->stateLabel() }}</span></td>
                            <td>
                                @if ($node->is_active)
                                    <i class="bi bi-check-circle text-success"></i>
                                @else
                                    <i class="bi bi-pause-circle text-secondary" title="Excluded from sweeps"></i>
                                @endif
                            </td>
                            @can('manage-voice-mesh')
                                <td class="text-end text-nowrap">
                                    <button class="btn btn-sm btn-outline-secondary"
                                            data-bs-toggle="modal" data-bs-target="#vmNodeModal"
                                            data-node="{{ json_encode($node->only(['id','code','name','branch_id','ivr_ext','sip_server','sip_port','sip_user','is_active','sort_order','notes'])) }}">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <form method="POST" action="{{ route('admin.network.voice-mesh.nodes.destroy', $node) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Remove {{ $node->code }} from the mesh? Its legs go with it; the call history is kept.')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                                    </form>
                                </td>
                            @endcan
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-muted p-3">No branches configured yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @can('manage-voice-mesh')
            <div class="card-footer">
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#vmNodeModal" data-node="">
                    <i class="bi bi-plus-lg me-1"></i>Add branch
                </button>
            </div>
        @endcan
    </div>

    {{-- ── Prober ──────────────────────────────────────────────── --}}
    @can('manage-voice-mesh')
        <div class="card mb-4">
            <div class="card-header fw-semibold">Prober</div>
            <div class="card-body">
                <p class="small text-muted">
                    The prober is a systemd service on this host — it needs <code>pjsua</code> and takes several
                    minutes for a full sweep, so it cannot be triggered from here. To run one now:
                </p>
                <pre class="bg-body-tertiary border rounded p-2 small mb-3"><code>sudo systemctl start voice-mesh-verify.service</code></pre>
                <div class="d-flex align-items-center gap-3">
                    <form method="POST" action="{{ route('admin.network.voice-mesh.secret.rotate') }}"
                          onsubmit="return confirm('Generate a new ingest secret? The prober will be locked out until config.conf on this host is updated to match.')">
                        @csrf
                        <button class="btn btn-sm btn-outline-warning"><i class="bi bi-key me-1"></i>Rotate ingest secret</button>
                    </form>
                    <span class="small text-muted">
                        Secret is {{ $secretIsSet ? 'set' : 'NOT set' }}. Reference prompt checksum:
                        <code>{{ config('voice_mesh.reference_sha256') ? substr(config('voice_mesh.reference_sha256'), 0, 16).'…' : 'not configured' }}</code>
                    </span>
                </div>
            </div>
        </div>
    @endcan
</div>

@can('manage-voice-mesh')
<div class="modal fade" id="vmNodeModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content" id="vmNodeForm" action="{{ route('admin.network.voice-mesh.nodes.store') }}">
            @csrf
            <input type="hidden" name="_method" id="vmMethod" value="POST">
            <div class="modal-header">
                <h5 class="modal-title">Branch in the mesh</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-4">
                        <label class="form-label small">Code</label>
                        <input name="code" id="vmCode" class="form-control" required maxlength="16" placeholder="JED">
                        <div class="form-text">Must match what the prober reports.</div>
                    </div>
                    <div class="col-8">
                        <label class="form-label small">Name</label>
                        <input name="name" id="vmName" class="form-control" required maxlength="100">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Branch (optional)</label>
                        <select name="branch_id" id="vmBranch" class="form-select">
                            <option value="">— none —</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-8">
                        <label class="form-label small">UCM address</label>
                        <input name="sip_server" id="vmServer" class="form-control font-monospace" required placeholder="10.1.8.10">
                    </div>
                    <div class="col-4">
                        <label class="form-label small">SIP port</label>
                        <input name="sip_port" id="vmPort" type="number" class="form-control" value="5060">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Probe extension</label>
                        <input name="sip_user" id="vmUser" class="form-control font-monospace" required>
                        <div class="form-text">Registers as this. Must not be a real phone.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">SIP password</label>
                        <input name="sip_pass" id="vmPass" type="password" class="form-control" autocomplete="new-password">
                        <div class="form-text" id="vmPassHelp">Required.</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">IVR extension</label>
                        <input name="ivr_ext" id="vmIvr" class="form-control font-monospace" required>
                        <div class="form-text">What other branches dial.</div>
                    </div>
                    <div class="col-3">
                        <label class="form-label small">Order</label>
                        <input name="sort_order" id="vmOrder" type="number" class="form-control" value="0">
                    </div>
                    <div class="col-3 d-flex align-items-end">
                        <div class="form-check">
                            <input type="hidden" name="is_active" value="0">
                            <input class="form-check-input" type="checkbox" name="is_active" id="vmActive" value="1" checked>
                            <label class="form-check-label small" for="vmActive">Active</label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Notes</label>
                        <textarea name="notes" id="vmNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>
@endcan

<style>
    .vm-matrix th, .vm-matrix td { vertical-align: middle; }
    .vm-corner, .vm-rowhead { position: sticky; left: 0; background: var(--bs-body-bg); z-index: 2; white-space: nowrap; }
    .vm-cell { text-align: center; width: 5.5rem; }
    .vm-link { text-decoration: none; display: block; color: inherit; }
    /* Status is carried by a glyph as well as colour, so the board survives
       greyscale printing and colour-blindness. */
    .vm-glyph { font-size: 1.15rem; line-height: 1; display: block; }
    .vm-sub { font-size: .7rem; opacity: .75; }
    .vm-ok      { background: color-mix(in srgb, var(--bs-success) 18%, transparent); color: var(--bs-success-text-emphasis); }
    .vm-fail    { background: color-mix(in srgb, var(--bs-danger) 22%, transparent);  color: var(--bs-danger-text-emphasis); }
    .vm-unknown { background: var(--bs-secondary-bg); color: var(--bs-secondary-color); }
    .vm-stale   { background: repeating-linear-gradient(45deg, var(--bs-secondary-bg), var(--bs-secondary-bg) 5px, transparent 5px, transparent 10px);
                  color: var(--bs-secondary-color); }
    .vm-self    { background: var(--bs-tertiary-bg); color: var(--bs-secondary-color); }
</style>

<script>
(function () {
    const DATA_URL = @json(route('admin.network.voice-mesh.data'));

    // Server-rendered skeleton, JSON-patched cells — no client framework.
    function apply(payload) {
        for (const [key, value] of Object.entries(payload.summary || {})) {
            const el = document.querySelector(`[data-sum="${key}"]`);
            if (el) el.textContent = value;
        }

        for (const [key, cell] of Object.entries(payload.cells || {})) {
            const td = document.querySelector(`[data-cell="${CSS.escape(key)}"]`);
            if (!td) continue;
            td.className = 'vm-cell vm-' + cell.status;
            td.title = cell.title || '';
            const glyph = td.querySelector('.vm-glyph');
            if (glyph) glyph.innerHTML = cell.status === 'ok' ? '&check;' : (cell.status === 'fail' ? '&cross;' : '&middot;');
            const sub = td.querySelector('.vm-sub');
            if (sub) sub.textContent = (cell.status === 'ok' && cell.drift_pct !== null) ? cell.drift_pct + '%' : '';
        }

        for (const [id, t] of Object.entries(payload.row_totals || {})) {
            const el = document.querySelector(`[data-rowtotal="${id}"]`);
            if (el) el.textContent = `${t.ok}/${t.total}`;
        }
        for (const [id, t] of Object.entries(payload.col_totals || {})) {
            const el = document.querySelector(`[data-coltotal="${id}"]`);
            if (el) el.textContent = `${t.ok}/${t.total}`;
        }

        const age = document.querySelector('[data-last-age]');
        const due = document.querySelector('[data-next-due]');
        if (age) age.textContent = payload.last_run ? payload.last_run.age : 'never';
        if (due) due.textContent = payload.last_run ? payload.last_run.next_due : '—';

        // The banner clears itself once the requested sweep's report lands, so
        // the page stops claiming a run is pending after it has happened.
        const banner = document.querySelector('[data-pending-sweep]');
        if (banner) {
            const pending = payload.pending_sweep;
            banner.classList.toggle('d-none', !pending);
            const text = banner.querySelector('[data-pending-text]');
            if (pending && text) {
                text.textContent = pending.scope
                    ? `Retry of ${pending.scope.replace('>', ' → ')} requested ${pending.requested}.`
                    : `Full sweep requested ${pending.requested}.`;
            }
            document.querySelectorAll('[data-sweep-btn]').forEach(b => { b.disabled = !!pending; });
        }
    }

    function refresh() {
        fetch(DATA_URL, { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : null)
            .then(d => d && apply(d))
            .catch(() => {});
    }

    // Sweeps are half-hourly — a 15s poll would be 120 redundant round-trips
    // per data change. The "next sweep" countdown carries the sense of liveness.
    setInterval(refresh, 60000);

    const modal = document.getElementById('vmNodeModal');
    if (modal) {
        modal.addEventListener('show.bs.modal', function (event) {
            const raw = event.relatedTarget?.getAttribute('data-node');
            const form = document.getElementById('vmNodeForm');
            const node = raw ? JSON.parse(raw) : null;

            document.getElementById('vmMethod').value = node ? 'PUT' : 'POST';
            form.action = node
                ? @json(url('admin/network/voice-mesh/nodes')) + '/' + node.id
                : @json(route('admin.network.voice-mesh.nodes.store'));

            const set = (id, value) => { document.getElementById(id).value = value ?? ''; };
            set('vmCode',   node?.code);
            set('vmName',   node?.name);
            set('vmBranch', node?.branch_id);
            set('vmServer', node?.sip_server);
            set('vmPort',   node?.sip_port ?? 5060);
            set('vmUser',   node?.sip_user);
            set('vmIvr',    node?.ivr_ext);
            set('vmOrder',  node?.sort_order ?? 0);
            set('vmNotes',  node?.notes);
            set('vmPass',   '');

            document.getElementById('vmActive').checked = node ? !!node.is_active : true;
            // The plaintext is never sent to the browser, so an empty field on
            // an existing node means "leave it alone", not "blank it".
            document.getElementById('vmPassHelp').textContent = node
                ? 'Leave blank to keep the current password.'
                : 'Required.';
            document.getElementById('vmPass').required = !node;
        });
    }
})();
</script>
@endsection
