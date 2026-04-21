@extends('layouts.admin')
@section('title', 'Running-Config Archive')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-code me-2 text-primary"></i>Running-Config Archive</h4>
        <small class="text-muted">Captured snapshots of `show running-config` from polled switches</small>
    </div>
    <a href="{{ route('admin.switch-qos.dashboard') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Dashboard
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        @if($rows->isEmpty())
        <div class="text-center text-muted py-5 small">
            <i class="bi bi-file-earmark-code display-4 d-block mb-2"></i>
            No running-configs captured yet. Open a switch and click <strong>Fetch Config</strong>.
        </div>
        @else
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Device</th>
                        <th>IP</th>
                        <th class="text-end">Captures</th>
                        <th class="text-end">Last Size</th>
                        <th>Last Captured</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $r)
                    <tr>
                        <td class="fw-semibold">{{ $r->device_name }}</td>
                        <td class="font-monospace">{{ $r->device_ip }}</td>
                        <td class="text-end"><span class="badge bg-info">{{ $r->capture_count }}</span></td>
                        <td class="text-end font-monospace small">{{ number_format($r->last_size) }} B</td>
                        <td class="text-muted small">{{ \Carbon\Carbon::parse($r->last_captured_at)->diffForHumans() }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.switch-qos.configs.show', $r->device_id) }}" class="btn btn-sm btn-outline-primary py-0 px-2">
                                <i class="bi bi-eye me-1"></i>View
                            </a>
                            @if($r->capture_count > 1)
                            <a href="{{ route('admin.switch-qos.configs.diff', $r->device_id) }}" class="btn btn-sm btn-outline-secondary py-0 px-2">
                                <i class="bi bi-arrow-left-right me-1"></i>Diff
                            </a>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</div>
@endsection
