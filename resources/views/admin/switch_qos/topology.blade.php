@extends('layouts.admin')
@section('title', 'Network Topology')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bounding-box me-2 text-primary"></i>Network Topology</h4>
        <small class="text-muted">Auto-discovered from CDP neighbors</small>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <div class="form-check form-switch me-2 mb-0">
            <input class="form-check-input" type="checkbox" id="hideEndUsers" checked>
            <label class="form-check-label small" for="hideEndUsers">
                Hide end-user devices
                <span class="badge bg-warning text-dark ms-1" id="endUserCount">{{ $stats['end_users'] ?? 0 }}</span>
            </label>
        </div>
        <span class="badge bg-info">Devices: <span id="deviceCount">{{ $stats['devices'] }}</span></span>
        <span class="badge bg-secondary">Links: <span id="linkCount">{{ $stats['links'] }}</span></span>
        <span class="badge bg-light text-dark border">Polled: {{ $stats['polled'] }}</span>
        <a href="{{ route('admin.switch-qos.cdp') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-list me-1"></i>Neighbor Table
        </a>
        <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>
</div>

@if(empty($nodes))
<div class="card border-0 shadow-sm">
    <div class="card-body text-center text-muted py-5">
        <i class="bi bi-diagram-3 display-4 d-block mb-2"></i>
        No CDP data yet. Run the poller on a switch that has CDP-enabled neighbors.
    </div>
</div>
@else
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div id="topology" style="height: 70vh; border: 1px solid #e9ecef; border-radius: 6px;"></div>
    </div>
</div>
<div class="mt-2 small text-muted d-flex flex-wrap gap-3 align-items-center">
    <span><i class="bi bi-info-circle me-1"></i>Drag to rearrange. Scroll to zoom. Click a node to open its detail page.</span>
    <span class="badge" style="background:#cfe2ff;color:#0d6efd;border:1px solid #0d6efd;">Polled Cisco</span>
    <span class="badge" style="background:#d1e7dd;color:#146c43;border:1px solid #146c43;">Meraki switch</span>
    <span class="badge" style="background:#fff3cd;color:#997404;border:1px solid #997404;">Internal device</span>
    <span class="badge" style="background:#e9ecef;color:#6c757d;border:1px solid #6c757d;">Unknown neighbor</span>
</div>
@endif
@endsection

@push('scripts')
@if(!empty($nodes))
<script src="https://cdnjs.cloudflare.com/ajax/libs/vis-network/9.1.9/standalone/umd/vis-network.min.js"></script>
<script>
const rawNodes = @json($nodes);
const rawEdges = @json($edges);

const palette = {
    polled:   { background: '#cfe2ff', border: '#0d6efd' },
    meraki:   { background: '#d1e7dd', border: '#146c43' },
    device:   { background: '#fff3cd', border: '#997404' },
    neighbor: { background: '#e9ecef', border: '#6c757d' },
};

const nodeUrls = {};
const nodeIsEndUser = {};
const nodes = new vis.DataSet(rawNodes.map(n => {
    if (n.url) nodeUrls[n.id] = n.url;
    nodeIsEndUser[n.id] = !!n.is_end_user;
    return {
        id: n.id,
        label: n.label,
        title: n.title,
        shape: 'box',
        color: palette[n.group] || palette.neighbor,
        font: { multi: true, size: 12 },
        margin: 8,
        hidden: !!n.is_end_user,   // default: hide end-user devices
    };
}));

const edges = new vis.DataSet(rawEdges.map((e, i) => ({
    id: 'e' + i,
    from: e.from,
    to:   e.to,
    label: e.label,
    title: e.title,
    font: { size: 9, align: 'middle', color: '#6c757d' },
    color: { color: '#adb5bd', highlight: '#0d6efd' },
    arrows: { to: { enabled: false }, from: { enabled: false } },
    smooth: { type: 'dynamic' },
    hidden: !!e.is_end_user,
})));

function applyEndUserFilter(hide) {
    const nodeUpdates = [];
    nodes.forEach(n => {
        if (nodeIsEndUser[n.id]) nodeUpdates.push({ id: n.id, hidden: hide });
    });
    if (nodeUpdates.length) nodes.update(nodeUpdates);

    const edgeUpdates = [];
    edges.forEach(e => {
        const endUserEdge = nodeIsEndUser[e.from] || nodeIsEndUser[e.to];
        if (endUserEdge) edgeUpdates.push({ id: e.id, hidden: hide });
    });
    if (edgeUpdates.length) edges.update(edgeUpdates);

    const visibleNodes = nodes.get().filter(n => !n.hidden).length;
    const visibleEdges = edges.get().filter(e => !e.hidden).length;
    document.getElementById('deviceCount').textContent = visibleNodes;
    document.getElementById('linkCount').textContent = visibleEdges;
}

document.getElementById('hideEndUsers').addEventListener('change', (ev) => {
    applyEndUserFilter(ev.target.checked);
});
// Initial count reflects the default-hidden state
applyEndUserFilter(document.getElementById('hideEndUsers').checked);

const network = new vis.Network(document.getElementById('topology'), { nodes, edges }, {
    physics: {
        enabled: true,
        solver: 'forceAtlas2Based',
        forceAtlas2Based: { gravitationalConstant: -80, springLength: 180, avoidOverlap: 0.5 },
        stabilization: { iterations: 400 }
    },
    interaction: { hover: true, tooltipDelay: 150, zoomView: true, dragView: true }
});

network.on('doubleClick', (params) => {
    const id = params.nodes[0];
    if (id && nodeUrls[id]) window.open(nodeUrls[id], '_blank');
});
network.on('hoverNode', (params) => {
    if (nodeUrls[params.node]) document.body.style.cursor = 'pointer';
});
network.on('blurNode', () => { document.body.style.cursor = 'default'; });
</script>
@endif
@endpush
