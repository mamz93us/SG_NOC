@extends('layouts.admin')
@section('title', 'CDP Neighbors')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-diagram-3 me-2 text-primary"></i>CDP Neighbors</h4>
        <small class="text-muted">Cisco Discovery Protocol topology across all polled switches</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('admin.switch-qos.topology') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-bounding-box me-1"></i>Topology Map
        </a>
        <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Dashboard
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        @if($rows->isEmpty())
        <div class="text-muted text-center py-5 small">
            <i class="bi bi-diagram-3 display-4 d-block mb-2"></i>
            No CDP data collected yet. Run the poller to populate.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Switch</th>
                        <th>Local Iface</th>
                        <th>Neighbor</th>
                        <th>Neighbor IP</th>
                        <th>Neighbor Port</th>
                        <th>Platform</th>
                        <th>Capabilities</th>
                        <th>Polled</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                    <tr>
                        <td>
                            <a href="{{ route('admin.switch-qos.device', urlencode($r->device_ip)) }}" class="text-decoration-none">
                                <span class="fw-semibold">{{ $r->device_name }}</span>
                                <span class="text-muted font-monospace small ms-1">{{ $r->device_ip }}</span>
                            </a>
                        </td>
                        <td class="font-monospace">{{ $r->local_interface }}</td>
                        <td class="fw-semibold">{{ $r->neighbor_device_id }}</td>
                        <td class="font-monospace text-muted">{{ $r->neighbor_ip ?: '—' }}</td>
                        <td class="font-monospace text-muted">{{ $r->neighbor_port ?: '—' }}</td>
                        <td class="text-muted">{{ $r->platform ?: '—' }}</td>
                        <td class="text-muted">{{ $r->capabilities ?: '—' }}</td>
                        <td class="text-muted small">{{ $r->polled_at?->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
