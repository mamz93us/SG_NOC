@extends('layouts.admin')
@section('title', 'RADIUS VLAN Policy')

@section('content')
<div class="container-fluid py-4">

    {{-- ── Header ────────────────────────────────────────────────────── --}}
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0"><i class="bi bi-diagram-3 me-2 text-primary"></i>RADIUS VLAN Policy</h4>
            <small class="text-muted">Default VLAN per branch (and optionally per adapter / device type). Returned in Access-Accept as <code>Tunnel-Private-Group-Id</code>.</small>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.radius.nas.index') }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-router me-1"></i>NAS Clients
            </a>
            <a href="{{ route('admin.radius.vlan.create') }}" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Policy
            </a>
        </div>
    </div>

    {{-- ── Resolution rules ──────────────────────────────────────────── --}}
    <div class="alert alert-info border-0 small mb-4">
        <i class="bi bi-info-circle me-1"></i>
        <strong>Resolution order on Access-Request:</strong>
        per-MAC override (set on the MAC registry page) → most-specific row below
        (lower <em>priority</em> wins on ties) → no VLAN attribute returned (switch
        falls back to its own default).
    </div>

    {{-- ── Preview panel ─────────────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-transparent">
            <strong><i class="bi bi-search me-1"></i>Preview Resolution</strong>
            <small class="text-muted ms-2">Enter a MAC to see what VLAN RADIUS would return.</small>
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">MAC Address</label>
                    <input type="text" id="previewMac" class="form-control form-control-sm font-monospace"
                           placeholder="aa:bb:cc:dd:ee:ff or AABBCCDDEEFF">
                </div>
                <div class="col-md-2">
                    <button id="previewBtn" type="button" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-play-fill me-1"></i>Resolve
                    </button>
                </div>
                <div class="col-md-5">
                    <div id="previewResult" class="small text-muted">No result yet.</div>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Table ─────────────────────────────────────────────────────── --}}
    <div class="card border-0 shadow-sm">
        <div class="card-body p-0">
            @if($policies->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-diagram-3 fs-1 d-block mb-2 opacity-25"></i>
                No policy rows yet.<br>
                <small>Without a matching policy, RADIUS returns Access-Accept without a VLAN — switch will use its configured default.</small>
            </div>
            @else
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:.88rem">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Branch</th>
                            <th>Adapter</th>
                            <th>Device Type</th>
                            <th>VLAN</th>
                            <th>Priority</th>
                            <th>Description</th>
                            <th style="width:130px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($policies as $p)
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $p->branch?->name ?: '—' }}</td>
                            <td>
                                @if($p->adapter_type === 'any')
                                    <span class="badge bg-secondary">Any</span>
                                @else
                                    <span class="badge bg-light text-dark border">{{ $p->adapter_type }}</span>
                                @endif
                            </td>
                            <td class="text-muted small">
                                {{ $p->device_type ?: '— any —' }}
                            </td>
                            <td class="fw-bold text-primary">{{ $p->vlan_id }}</td>
                            <td class="text-muted">{{ $p->priority }}</td>
                            <td class="text-muted small">{{ $p->description ?: '—' }}</td>
                            <td class="text-end pe-3">
                                <a href="{{ route('admin.radius.vlan.edit', $p) }}" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form action="{{ route('admin.radius.vlan.destroy', $p) }}" method="POST" class="d-inline"
                                      onsubmit="return confirm('Delete this VLAN policy row?');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if($policies->hasPages())
            <div class="px-3 py-2 border-top">{{ $policies->links() }}</div>
            @endif
            @endif
        </div>
    </div>

</div>

<script>
(function () {
    const btn    = document.getElementById('previewBtn');
    const input  = document.getElementById('previewMac');
    const out    = document.getElementById('previewResult');
    const url    = "{{ route('admin.radius.vlan.preview') }}";

    function render(data) {
        if (data.ok) {
            out.innerHTML = `<span class="badge bg-success me-1">VLAN ${data.vlan ?? '—'}</span>`
                          + `<code class="text-muted">${data.normalized}</code>`
                          + `<div class="text-muted">${data.source}: ${data.reason}</div>`;
        } else {
            const norm = data.normalized ? `<code class="text-muted">${data.normalized}</code> — ` : '';
            out.innerHTML = `<span class="badge bg-danger me-1">Reject</span> ${norm}${data.error}`;
        }
    }

    btn.addEventListener('click', async () => {
        out.textContent = 'Resolving…';
        try {
            const res = await fetch(`${url}?mac=${encodeURIComponent(input.value)}`, {
                headers: { 'Accept': 'application/json' },
            });
            render(await res.json());
        } catch (e) {
            out.textContent = 'Error: ' + e.message;
        }
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); btn.click(); }
    });
})();
</script>
@endsection
