@extends('layouts.admin')
@section('content')

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>Network Topology</h4>
        <small class="text-muted">Interactive map of branches, switches, VPN tunnels, hosts & ISPs</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <select id="branchFilter" class="form-select form-select-sm" style="width:200px">
            <option value="">All Branches</option>
            @foreach($branches as $b)
            <option value="{{ $b->id }}">{{ $b->name }}</option>
            @endforeach
        </select>
        <button id="refreshBtn" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-0 position-relative">
        <div id="cy" style="height:600px;width:100%;"></div>

        {{-- Legend --}}
        <div class="position-absolute bottom-0 start-0 m-3 bg-white bg-opacity-90 border rounded p-2 small" style="z-index:10;">
            <strong class="d-block mb-1">Legend</strong>
            <div class="d-flex flex-wrap gap-2">
                <span><span class="badge bg-primary">&nbsp;</span> Branch</span>
                <span><span class="badge bg-info">&nbsp;</span> Switch</span>
                <span><span class="badge bg-success">&nbsp;</span> VPN</span>
                <span><span class="badge bg-warning text-dark">&nbsp;</span> ISP</span>
                <span><span class="badge bg-secondary">&nbsp;</span> Host</span>
                <span><span class="badge bg-dark">&nbsp;</span> Device</span>
            </div>
            <div class="mt-1 text-muted" style="font-size:10px">
                <i class="bi bi-circle-fill text-success" style="font-size:7px"></i> Up/Online &nbsp;
                <i class="bi bi-circle-fill text-danger" style="font-size:7px"></i> Down/Offline
            </div>
        </div>
    </div>
</div>

{{-- Detail Panel --}}
<div id="detailPanel" class="card shadow-sm mt-3 d-none">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <strong id="detailTitle"></strong>
        <button class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('detailPanel').classList.add('d-none')"><i class="bi bi-x"></i></button>
    </div>
    <div class="card-body small" id="detailBody"></div>
</div>

@endsection

@push('scripts')
<script src="https://cdnjs.cloudflare.com/ajax/libs/cytoscape/3.28.1/cytoscape.min.js"></script>
<script>
const typeColors = {
    branch: '#0d6efd',
    switch: '#0dcaf0',
    vpn:    '#198754',
    isp:    '#ffc107',
    host:   '#6c757d',
    device: '#212529',
};

const statusColors = {
    up:        '#198754',
    online:    '#198754',
    down:      '#dc3545',
    offline:   '#dc3545',
    connecting:'#ffc107',
};

let cy;

function initCytoscape(elements) {
    if (cy) cy.destroy();

    cy = cytoscape({
        container: document.getElementById('cy'),
        elements: elements,
        style: [
            {
                selector: 'node',
                style: {
                    'label': 'data(label)',
                    'font-size': 11,
                    'text-valign': 'bottom',
                    'text-margin-y': 5,
                    'text-wrap': 'ellipsis',
                    'text-max-width': 100,
                    'width': 40,
                    'height': 40,
                    'shape': 'ellipse',
                    'background-color': function(ele) {
                        return typeColors[ele.data('type')] || '#adb5bd';
                    },
                    'border-width': function(ele) {
                        return ele.data('status') ? 3 : 1;
                    },
                    'border-color': function(ele) {
                        const s = ele.data('status');
                        return s ? (statusColors[s] || '#adb5bd') : '#dee2e6';
                    },
                    'color': '#333',
                }
            },
            {
                selector: 'node[type="branch"]',
                style: {
                    'width': 60,
                    'height': 60,
                    'font-size': 13,
                    'font-weight': 'bold',
                    'shape': 'round-rectangle',
                }
            },
            {
                selector: 'edge',
                style: {
                    'width': 2,
                    'line-color': '#dee2e6',
                    'curve-style': 'bezier',
                    'target-arrow-shape': 'none',
                }
            },
            {
                selector: 'edge[type="vpn"]',
                style: { 'line-color': '#198754', 'line-style': 'dashed' }
            },
            {
                selector: 'edge[type="isp"]',
                style: { 'line-color': '#ffc107' }
            },
        ],
        layout: {
            name: 'cose',
            padding: 40,
            nodeRepulsion: 8000,
            idealEdgeLength: 120,
            animate: true,
            animationDuration: 500,
        },
        wheelSensitivity: 0.3,
    });

    // Click handler
    cy.on('tap', 'node', function(evt) {
        const d = evt.target.data();
        const panel = document.getElementById('detailPanel');
        const title = document.getElementById('detailTitle');
        const body  = document.getElementById('detailBody');

        title.textContent = d.label;

        let html = `<div class="mb-1"><span class="badge" style="background:${typeColors[d.type] || '#adb5bd'}">${d.type}</span></div>`;

        if (d.status) {
            const sc = statusColors[d.status] || '#adb5bd';
            html += `<div class="mb-1">Status: <span class="badge" style="background:${sc}">${d.status}</span></div>`;
        }
        if (d.model) html += `<div>Model: ${d.model}</div>`;
        if (d.ip)    html += `<div>IP: <code>${d.ip}</code></div>`;
        if (d.subtype) html += `<div>Sub-type: ${d.subtype}</div>`;

        body.innerHTML = html;
        panel.classList.remove('d-none');
    });
}

function loadTopology() {
    const branchId = document.getElementById('branchFilter').value;
    const url = '{{ route("admin.network.topology.data") }}' + (branchId ? '?branch_id=' + branchId : '');

    fetch(url, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.json())
        .then(data => {
            const elements = [
                ...data.nodes,
                ...data.edges,
            ];
            initCytoscape(elements);
        })
        .catch(err => console.error('Topology load error:', err));
}

document.getElementById('branchFilter').addEventListener('change', loadTopology);
document.getElementById('refreshBtn').addEventListener('click', loadTopology);

// Initial load
loadTopology();
</script>
@endpush
