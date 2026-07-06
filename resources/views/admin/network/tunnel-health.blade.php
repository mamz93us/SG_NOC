@extends('layouts.admin')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h2 class="h3 mb-1">Branch Tunnel Health</h2>
        <p class="text-muted small mb-0">
            Add each branch and its firewall IP. Live ICMP ping confirms the Azure VPN tunnel is carrying traffic.
        </p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span id="th-updated" class="text-muted small"></span>
        <button id="th-ping-now" class="btn btn-primary">
            <i class="bi bi-arrow-repeat me-1"></i> Ping now
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

{{-- Add branch form --}}
@can('manage-network-settings')
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <form action="{{ route('admin.network.tunnel-health.store') }}" method="POST" class="row g-2 align-items-end">
            @csrf
            <div class="col-sm-5">
                <label class="form-label small mb-1">Branch</label>
                <input type="text" name="name" class="form-control" placeholder="e.g. CAI" required>
            </div>
            <div class="col-sm-5">
                <label class="form-label small mb-1">Firewall IP</label>
                <input type="text" name="firewall_ip" class="form-control" placeholder="e.g. 10.9.8.1" required>
            </div>
            <div class="col-sm-2">
                <button type="submit" class="btn btn-success w-100"><i class="bi bi-plus-lg me-1"></i>Add</button>
            </div>
        </form>
    </div>
</div>
@endcan

<div class="d-flex gap-2 mb-3">
    <span class="badge bg-success" id="th-count-up">0 up</span>
    <span class="badge bg-danger" id="th-count-down">0 down</span>
    <span class="badge bg-secondary" id="th-count-total">{{ $targets->count() }} total</span>
</div>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Branch</th>
                    <th>Firewall IP</th>
                    <th>Status</th>
                    <th>Latency</th>
                    <th>Last checked</th>
                    @can('manage-network-settings')<th class="text-end">Actions</th>@endcan
                </tr>
            </thead>
            <tbody>
                @forelse($targets as $t)
                    <tr data-id="{{ $t->id }}">
                        <td class="fw-semibold">{{ $t->name }}</td>
                        <td><code>{{ $t->firewall_ip }}</code></td>
                        <td data-cell="status">
                            @php $s = $t->ping_status ?? 'unknown'; @endphp
                            <span class="badge {{ $s === 'up' ? 'bg-success' : ($s === 'down' ? 'bg-danger' : 'bg-secondary') }}">{{ ucfirst($s) }}</span>
                        </td>
                        <td data-cell="latency">{{ $t->ping_status === 'up' && $t->ping_latency_ms !== null ? $t->ping_latency_ms.' ms' : '—' }}</td>
                        <td class="text-muted small" data-cell="checked">{{ $t->last_ping_at?->diffForHumans(short: true) ?? 'never' }}</td>
                        @can('manage-network-settings')
                        <td class="text-end text-nowrap">
                            <button type="button" class="btn btn-sm btn-outline-secondary th-edit"
                                    data-id="{{ $t->id }}" data-name="{{ $t->name }}" data-ip="{{ $t->firewall_ip }}"
                                    data-bs-toggle="modal" data-bs-target="#th-edit-modal">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form action="{{ route('admin.network.tunnel-health.destroy', $t) }}" method="POST" class="d-inline"
                                  onsubmit="return confirm('Remove {{ $t->name }}?');">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-center text-muted py-4">No branches yet. Add one above with its firewall IP.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Status auto-refreshes every 20&nbsp;seconds; a background job re-pings every minute. <strong>Down</strong> on all rows means the Azure tunnels aren't carrying traffic yet.
</p>

{{-- Edit modal --}}
@can('manage-network-settings')
<div class="modal fade" id="th-edit-modal" tabindex="-1">
    <div class="modal-dialog">
        <form id="th-edit-form" method="POST">
            @csrf @method('PUT')
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit branch</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label small mb-1">Branch</label>
                        <input type="text" name="name" id="th-edit-name" class="form-control" required>
                    </div>
                    <div class="mb-0">
                        <label class="form-label small mb-1">Firewall IP</label>
                        <input type="text" name="firewall_ip" id="th-edit-ip" class="form-control" required>
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

<script>
(function () {
    const DATA_URL     = @json(route('admin.network.tunnel-health.data'));
    const PING_URL     = @json(route('admin.network.tunnel-health.ping'));
    const UPDATE_BASE  = @json(url('admin/network/tunnel-health'));
    const CSRF         = @json(csrf_token());

    function badge(status) {
        const cls = status === 'up' ? 'bg-success' : (status === 'down' ? 'bg-danger' : 'bg-secondary');
        return `<span class="badge ${cls}">${status.charAt(0).toUpperCase() + status.slice(1)}</span>`;
    }

    function apply(rows) {
        let up = 0, down = 0;
        rows.forEach(r => {
            if (r.status === 'up') up++; else if (r.status === 'down') down++;
            const tr = document.querySelector(`tr[data-id="${r.id}"]`);
            if (!tr) return;
            tr.querySelector('[data-cell="status"]').innerHTML  = badge(r.status);
            tr.querySelector('[data-cell="latency"]').textContent = (r.status === 'up' && r.latency_ms != null) ? r.latency_ms + ' ms' : '—';
            tr.querySelector('[data-cell="checked"]').textContent = r.checked || 'never';
        });
        document.getElementById('th-count-up').textContent    = up + ' up';
        document.getElementById('th-count-down').textContent  = down + ' down';
        document.getElementById('th-count-total').textContent = rows.length + ' total';
        document.getElementById('th-updated').textContent     = 'Updated ' + new Date().toLocaleTimeString();
    }

    async function refresh() {
        try { const r = await fetch(DATA_URL, { headers: { 'Accept': 'application/json' } }); apply((await r.json()).rows); } catch (e) {}
    }

    const btn = document.getElementById('th-ping-now');
    btn.addEventListener('click', async () => {
        const orig = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Pinging…';
        try { const r = await fetch(PING_URL, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF } }); apply((await r.json()).rows); }
        catch (e) {}
        finally { btn.disabled = false; btn.innerHTML = orig; }
    });

    // Populate the edit modal + point the form at the right row.
    document.querySelectorAll('.th-edit').forEach(b => b.addEventListener('click', () => {
        document.getElementById('th-edit-name').value = b.dataset.name;
        document.getElementById('th-edit-ip').value   = b.dataset.ip;
        document.getElementById('th-edit-form').action = UPDATE_BASE + '/' + b.dataset.id;
    }));

    setInterval(refresh, 20000);
})();
</script>
@endsection
