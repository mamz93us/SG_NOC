@extends('layouts.admin')

@push('head')
<style>
.result-row.alive td { color: #198754; }
.result-row.dead td { color: #6c757d; }
.scanner-result-ip { font-family: monospace; }
</style>
@endpush

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="h3 mb-1"><i class="bi bi-radar me-2 text-primary"></i>IP Scanner</h2>
        <p class="text-muted small mb-0">Scan a subnet or IP range to discover live hosts. Supports CIDR, ranges, and single IPs.</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form id="scanForm">
            <div class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label fw-bold">Subnet / Range / IP</label>
                    <input type="text" id="subnet" class="form-control form-control-lg" placeholder="e.g. 192.168.1.0/24 or 10.0.0.1-50 or 10.0.0.5">
                    <div class="form-text">CIDR: <code>192.168.1.0/24</code> · Range: <code>192.168.1.1-254</code> · Single: <code>10.0.0.5</code></div>
                </div>
                <div class="col-lg-3">
                    <button type="submit" class="btn btn-primary btn-lg w-100" id="scanBtn">
                        <i class="bi bi-radar me-2"></i>Start Scan
                    </button>
                </div>
                <div class="col-lg-4">
                    <div id="scanStatus" class="d-none alert alert-info py-2 mb-0">
                        <div class="d-flex align-items-center gap-2">
                            <div class="spinner-border spinner-border-sm" role="status"></div>
                            <span id="scanStatusText">Scanning…</span>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="scanResults" class="d-none">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-list-ul me-2"></i>Scan Results</h5>
            <div id="scanSummary" class="d-flex gap-3">
                <span class="badge bg-success px-3 py-2 fs-6" id="aliveCount">0 alive</span>
                <span class="badge bg-secondary px-3 py-2 fs-6" id="totalCount">0 total</span>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="d-flex gap-2 p-3 border-bottom align-items-center">
                <button class="btn btn-outline-secondary btn-sm" id="showAllBtn" onclick="filterResults('all')">All</button>
                <button class="btn btn-outline-success btn-sm" id="showAliveBtn" onclick="filterResults('alive')">Alive Only</button>
                <button class="btn btn-outline-secondary btn-sm" id="showDeadBtn" onclick="filterResults('dead')">Offline Only</button>
                <input type="text" class="form-control form-control-sm ms-auto" style="max-width:200px;" placeholder="Filter IP..." oninput="filterByIp(this.value)">
            </div>
            <div style="max-height:550px; overflow-y:auto;">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th class="ps-4">Status</th>
                            <th>IP Address</th>
                            <th>Hostname</th>
                            <th>Latency</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="resultsTable"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
let allResults = [];

document.getElementById('scanForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const subnet = document.getElementById('subnet').value.trim();
    if (!subnet) return;

    // Show loading state
    document.getElementById('scanBtn').disabled = true;
    document.getElementById('scanBtn').innerHTML = '<div class="spinner-border spinner-border-sm me-2" role="status"></div>Scanning…';
    document.getElementById('scanStatus').classList.remove('d-none');
    document.getElementById('scanStatusText').textContent = 'Scanning subnet…';
    document.getElementById('scanResults').classList.add('d-none');
    document.getElementById('resultsTable').innerHTML = '';
    allResults = [];

    try {
        const resp = await fetch('{{ route("admin.network.scanner.scan") }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': '{{ csrf_token() }}'
            },
            body: JSON.stringify({ subnet }),
        });

        const data = await resp.json();

        if (data.error) {
            alert(data.error);
            return;
        }

        allResults = data.results;
        renderResults(allResults);

        document.getElementById('aliveCount').textContent = data.alive + ' alive';
        document.getElementById('totalCount').textContent = data.total + ' total';
        document.getElementById('scanResults').classList.remove('d-none');

    } catch(err) {
        alert('Scan failed: ' + err.message);
    } finally {
        document.getElementById('scanBtn').disabled = false;
        document.getElementById('scanBtn').innerHTML = '<i class="bi bi-radar me-2"></i>Start Scan';
        document.getElementById('scanStatus').classList.add('d-none');
    }
});

function renderResults(results) {
    const tbody = document.getElementById('resultsTable');
    tbody.innerHTML = '';

    results.forEach(r => {
        const row = document.createElement('tr');
        row.className = 'result-row ' + (r.alive ? 'alive' : 'dead');
        row.dataset.ip = r.ip;
        row.dataset.alive = r.alive ? '1' : '0';

        row.innerHTML = `
            <td class="ps-4">
                ${r.alive 
                    ? '<span class="badge bg-success"><i class="bi bi-circle-fill me-1"></i>UP</span>' 
                    : '<span class="badge bg-secondary"><i class="bi bi-circle me-1"></i>DOWN</span>'}
            </td>
            <td class="scanner-result-ip fw-bold">${r.ip}</td>
            <td class="text-muted">${r.hostname || '<span class="text-muted fst-italic small">Unknown</span>'}</td>
            <td>${r.alive ? '<span class="text-success">' + r.latency_ms + ' ms</span>' : '<span class="text-muted">—</span>'}</td>
            <td>
                ${r.alive ? `<a href="{{ route('admin.network.monitoring.index') }}?prefill=${r.ip}" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-plus-circle me-1"></i>Add to Monitor</a>` : ''}
            </td>
        `;
        tbody.appendChild(row);
    });
}

function filterResults(type) {
    const filtered = type === 'all'   ? allResults
                   : type === 'alive' ? allResults.filter(r => r.alive)
                                      : allResults.filter(r => !r.alive);
    renderResults(filtered);
}

function filterByIp(val) {
    const filtered = allResults.filter(r => r.ip.includes(val) || (r.hostname && r.hostname.includes(val)));
    renderResults(filtered);
}
</script>
@endpush
