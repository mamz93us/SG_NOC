@extends('layouts.admin')
@section('content')
<style>
    .ext-status { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
    .ext-idle { background: #198754; }
    .ext-inuse, .ext-busy, .ext-ringing { background: #ffc107; }
    .ext-unavailable { background: #dc3545; }
    .ext-default { background: #6c757d; }
    .filter-btn.active { background: #0d6efd !important; color: #fff !important; border-color: #0d6efd !important; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h3 class="mb-0 fw-bold"><i class="bi bi-telephone-fill me-2 text-primary"></i>Extension Grid</h3>
        <p class="text-muted small mb-0">Live UCM extension status with switch port correlation</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.noc.wallboard') }}" target="_blank" class="btn btn-outline-dark btn-sm shadow-sm">
            <i class="bi bi-display me-1"></i>Wallboard
        </a>
        <a href="{{ route('admin.noc.dashboard') }}" class="btn btn-outline-secondary btn-sm shadow-sm">
            <i class="bi bi-speedometer2 me-1"></i>Dashboard
        </a>
        <button class="btn btn-primary btn-sm shadow-sm" id="refreshBtn" onclick="loadData()">
            <i class="bi bi-arrow-repeat me-1"></i>Refresh
        </button>
    </div>
</div>

{{-- Stats Row --}}
<div class="row g-3 mb-4" id="statsRow">
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-primary" id="statTotal">-</div>
            <div class="small text-muted text-uppercase">Total Extensions</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-success" id="statIdle">-</div>
            <div class="small text-muted text-uppercase">Idle</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-warning" id="statInUse">-</div>
            <div class="small text-muted text-uppercase">In Use</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-danger" id="statUnavail">-</div>
            <div class="small text-muted text-uppercase">Unavailable</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-info" id="statCalls">-</div>
            <div class="small text-muted text-uppercase">Active Calls</div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm text-center p-3">
            <div class="fs-3 fw-bold text-secondary" id="statMapped">-</div>
            <div class="small text-muted text-uppercase">Port Mapped</div>
        </div>
    </div>
</div>

{{-- Active Calls Card --}}
<div class="card border-0 shadow-sm mb-4 d-none" id="callsCard">
    <div class="card-header bg-danger bg-opacity-10 border-0">
        <h6 class="mb-0 fw-semibold text-danger"><i class="bi bi-telephone-forward-fill me-2"></i>Active Calls <span class="badge bg-danger ms-1" id="callCount">0</span></h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="small fw-semibold ps-3">Caller</th>
                        <th class="small fw-semibold">Destination</th>
                        <th class="small fw-semibold">Duration</th>
                        <th class="small fw-semibold">Server</th>
                    </tr>
                </thead>
                <tbody id="callsBody"></tbody>
            </table>
        </div>
    </div>
</div>

{{-- Filters + Search --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-0 d-flex flex-wrap align-items-center gap-2">
        <div class="d-flex gap-1 flex-wrap">
            <button class="btn btn-sm btn-outline-secondary filter-btn active" data-filter="all">All</button>
            <button class="btn btn-sm btn-outline-success filter-btn" data-filter="idle">Idle</button>
            <button class="btn btn-sm btn-outline-warning filter-btn" data-filter="inuse">In Use</button>
            <button class="btn btn-sm btn-outline-danger filter-btn" data-filter="unavailable">Unavailable</button>
            <button class="btn btn-sm btn-outline-info filter-btn" data-filter="mapped">Port Mapped</button>
        </div>
        <div class="ms-auto" style="min-width:250px;">
            <input type="text" class="form-control form-control-sm" id="searchInput" placeholder="Search extension, name, IP, switch...">
        </div>
        <div class="small text-muted" id="resultCount"></div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light sticky-top">
                    <tr>
                        <th class="small fw-semibold ps-3" style="cursor:pointer" onclick="sortTable('extension')">Ext <i class="bi bi-chevron-expand text-muted"></i></th>
                        <th class="small fw-semibold" style="cursor:pointer" onclick="sortTable('name')">User <i class="bi bi-chevron-expand text-muted"></i></th>
                        <th class="small fw-semibold text-center">Status</th>
                        <th class="small fw-semibold">IP Address</th>
                        <th class="small fw-semibold" style="cursor:pointer" onclick="sortTable('switch_name')">Switch / Port <i class="bi bi-chevron-expand text-muted"></i></th>
                        <th class="small fw-semibold text-center">VLAN</th>
                        <th class="small fw-semibold">MAC</th>
                        <th class="small fw-semibold">Server</th>
                    </tr>
                </thead>
                <tbody id="extBody">
                    <tr><td colspan="8" class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>Loading extensions...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="text-muted small text-center mb-3">
    <i class="bi bi-info-circle me-1"></i>Data refreshes automatically every 30 seconds. UCM sync runs every 15 seconds. Port mapping updates every 60 seconds.
</div>

<script>
let allExtensions = [];
let allCalls = [];
let currentFilter = 'all';
let currentSort = { key: 'extension', dir: 'asc' };

function loadData() {
    const btn = document.getElementById('refreshBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';

    fetch('{{ route("admin.noc.extension-grid") }}')
        .then(r => r.json())
        .then(data => {
            allExtensions = data.extensions || [];
            allCalls = data.active_calls || [];
            updateStats();
            updateCalls();
            renderExtensions();
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Refresh';
        })
        .catch(() => {
            document.getElementById('extBody').innerHTML = '<tr><td colspan="8" class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle me-1"></i>Failed to load data</td></tr>';
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Refresh';
        });
}

function updateStats() {
    const total = allExtensions.length;
    const idle = allExtensions.filter(e => e.status === 'idle').length;
    const inuse = allExtensions.filter(e => ['inuse','busy','ringing'].includes(e.status)).length;
    const unavail = allExtensions.filter(e => e.status === 'unavailable').length;
    const mapped = allExtensions.filter(e => e.switch_name && e.switch_name !== '-').length;

    document.getElementById('statTotal').textContent = total;
    document.getElementById('statIdle').textContent = idle;
    document.getElementById('statInUse').textContent = inuse;
    document.getElementById('statUnavail').textContent = unavail;
    document.getElementById('statCalls').textContent = allCalls.length;
    document.getElementById('statMapped').textContent = mapped;
}

function updateCalls() {
    const card = document.getElementById('callsCard');
    const body = document.getElementById('callsBody');
    const count = document.getElementById('callCount');

    if (allCalls.length > 0) {
        card.classList.remove('d-none');
        count.textContent = allCalls.length;
        body.innerHTML = allCalls.map(c =>
            `<tr>
                <td class="ps-3 fw-semibold font-monospace small">${c.caller}</td>
                <td class="font-monospace small">${c.callee}</td>
                <td class="small">${c.duration}</td>
                <td class="small text-muted">${c.server}</td>
            </tr>`
        ).join('');
    } else {
        card.classList.add('d-none');
    }
}

function getFiltered() {
    let list = allExtensions;
    if (currentFilter === 'idle') list = list.filter(e => e.status === 'idle');
    else if (currentFilter === 'inuse') list = list.filter(e => ['inuse','busy','ringing'].includes(e.status));
    else if (currentFilter === 'unavailable') list = list.filter(e => e.status === 'unavailable');
    else if (currentFilter === 'mapped') list = list.filter(e => e.switch_name && e.switch_name !== '-');

    const q = document.getElementById('searchInput').value.toLowerCase().trim();
    if (q) {
        list = list.filter(e =>
            (e.extension || '').toLowerCase().includes(q) ||
            (e.name || '').toLowerCase().includes(q) ||
            (e.ip || '').toLowerCase().includes(q) ||
            (e.switch_name || '').toLowerCase().includes(q) ||
            (e.switch_port || '').toLowerCase().includes(q) ||
            (e.mac || '').toLowerCase().includes(q) ||
            (e.server || '').toLowerCase().includes(q) ||
            (e.vlan || '').toString().toLowerCase().includes(q)
        );
    }

    list.sort((a, b) => {
        let va = (a[currentSort.key] || '').toString().toLowerCase();
        let vb = (b[currentSort.key] || '').toString().toLowerCase();
        if (currentSort.key === 'extension') { va = parseInt(va) || 0; vb = parseInt(vb) || 0; }
        if (va < vb) return currentSort.dir === 'asc' ? -1 : 1;
        if (va > vb) return currentSort.dir === 'asc' ? 1 : -1;
        return 0;
    });

    return list;
}

function renderExtensions() {
    const list = getFiltered();
    const body = document.getElementById('extBody');
    const countEl = document.getElementById('resultCount');
    countEl.textContent = `${list.length} of ${allExtensions.length} extensions`;

    if (list.length === 0) {
        body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No extensions match your filter.</td></tr>';
        return;
    }

    body.innerHTML = list.map(e => {
        const dotClass = ['inuse','busy','ringing'].includes(e.status) ? 'ext-inuse' : (e.status === 'idle' ? 'ext-idle' : (e.status === 'unavailable' ? 'ext-unavailable' : 'ext-default'));
        const loc = (e.switch_name && e.switch_name !== '-') ? `${e.switch_name}${e.switch_port && e.switch_port !== '-' ? ' / ' + e.switch_port : ''}` : '<span class="text-muted">-</span>';
        const wifiIcon = e.wifi ? ' <i class="bi bi-wifi text-info" title="Connected via WiFi MAC"></i>' : '';
        const locDisplay = e.wifi && e.switch_name === '-' ? '<span class="badge bg-info bg-opacity-10 text-info border border-info"><i class="bi bi-wifi me-1"></i>WiFi</span>' : loc;
        return `<tr>
            <td class="ps-3 fw-semibold font-monospace small">${e.extension}</td>
            <td class="small">${e.name}</td>
            <td class="text-center"><span class="ext-status ${dotClass}" title="${e.status}"></span> <span class="badge ${e.status_badge} rounded-pill" style="font-size:.68rem">${e.status}</span></td>
            <td class="small font-monospace">${e.ip}</td>
            <td class="small">${locDisplay}</td>
            <td class="text-center small">${e.vlan}</td>
            <td class="small font-monospace text-muted">${e.mac}${wifiIcon}</td>
            <td class="small text-muted">${e.server}</td>
        </tr>`;
    }).join('');
}

function sortTable(key) {
    if (currentSort.key === key) {
        currentSort.dir = currentSort.dir === 'asc' ? 'desc' : 'asc';
    } else {
        currentSort = { key, dir: 'asc' };
    }
    renderExtensions();
}

// Filter buttons
document.querySelectorAll('.filter-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        currentFilter = this.dataset.filter;
        renderExtensions();
    });
});

// Search
document.getElementById('searchInput').addEventListener('input', renderExtensions);

// Load on page ready
document.addEventListener('DOMContentLoaded', loadData);

// Auto-refresh every 30 seconds
setInterval(loadData, 30000);
</script>
@endsection
