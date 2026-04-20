@extends('layouts.admin')
@section('title', 'Network Topology')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-bounding-box me-2 text-primary"></i>Network Topology</h4>
        <small class="text-muted">Auto-discovered from CDP neighbors</small>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-info">Devices: {{ $stats['devices'] }}</span>
        <span class="badge bg-secondary">Links: {{ $stats['links'] }}</span>
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
<div class="mt-2 small text-muted">
    <i class="bi bi-info-circle me-1"></i>Drag nodes to rearrange. Scroll to zoom. Hover for details. Blue = polled switch (we manage it); grey = neighbor discovered via CDP.
</div>
@endif
@endsection

@push('scripts')
@if(!empty($nodes))
<script src="https://cdnjs.cloudflare.com/ajax/libs/vis-network/9.1.9/standalone/umd/vis-network.min.js"></script>
<script>
const rawNodes = @json($nodes);
const rawEdges = @json($edges);

const nodes = new vis.DataSet(rawNodes.map(n => ({
    id: n.id,
    label: n.label,
    title: n.title,
    shape: 'box',
    color: n.group === 'polled'
        ? { background: '#cfe2ff', border: '#0d6efd' }
        : { background: '#e9ecef', border: '#6c757d' },
    font: { multi: true, size: 12 },
    margin: 8,
})));

const edges = new vis.DataSet(rawEdges.map(e => ({
    from: e.from,
    to:   e.to,
    label: e.label,
    title: e.title,
    font: { size: 9, align: 'middle', color: '#6c757d' },
    color: { color: '#adb5bd', highlight: '#0d6efd' },
    arrows: { to: { enabled: false }, from: { enabled: false } },
    smooth: { type: 'dynamic' },
})));

new vis.Network(document.getElementById('topology'), { nodes, edges }, {
    physics: {
        enabled: true,
        solver: 'forceAtlas2Based',
        forceAtlas2Based: { gravitationalConstant: -80, springLength: 180, avoidOverlap: 0.5 },
        stabilization: { iterations: 400 }
    },
    interaction: { hover: true, tooltipDelay: 150, zoomView: true, dragView: true }
});
</script>
@endif
@endpush
