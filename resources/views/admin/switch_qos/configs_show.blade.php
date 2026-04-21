@extends('layouts.admin')
@section('title', 'Running Config — ' . $device->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-file-earmark-code me-2 text-primary"></i>{{ $device->name }}</h4>
        <small class="text-muted font-monospace">{{ $device->ip_address }} · captured {{ $snapshot->captured_at->format('Y-m-d H:i:s') }} ({{ $snapshot->captured_at->diffForHumans() }})</small>
    </div>
    <div class="d-flex gap-2">
        @can('manage-credentials')
        <form method="POST" action="{{ route('admin.switch-qos.configs.fetch', $device->id) }}" class="d-inline">
            @csrf
            <button class="btn btn-sm btn-success" type="submit" title="Capture a fresh snapshot now">
                <i class="bi bi-arrow-repeat me-1"></i>Fetch Now
            </button>
        </form>
        @endcan
        <a href="{{ route('admin.switch-qos.configs.download', [$device->id, $snapshot->id]) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-download me-1"></i>Download
        </a>
        @if($history->count() > 1)
        <a href="{{ route('admin.switch-qos.configs.diff', $device->id) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left-right me-1"></i>Diff
        </a>
        @endif
        <a href="{{ route('admin.switch-qos.configs.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>All Configs
        </a>
    </div>
</div>

@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
@if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

<div class="row g-3">
    <div class="col-lg-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent fw-semibold"><i class="bi bi-clock-history me-1"></i>History</div>
            <div class="list-group list-group-flush small" style="max-height:70vh;overflow:auto;">
                @foreach($history as $h)
                <a href="{{ route('admin.switch-qos.configs.snapshot', [$device->id, $h->id]) }}"
                   class="list-group-item list-group-item-action {{ $h->id === $snapshot->id ? 'active' : '' }}">
                    <div class="d-flex justify-content-between">
                        <span>{{ $h->captured_at->format('Y-m-d H:i') }}</span>
                        <span class="font-monospace small">{{ number_format($h->size_bytes) }} B</span>
                    </div>
                    <div class="small {{ $h->id === $snapshot->id ? '' : 'text-muted' }} font-monospace text-truncate">
                        {{ substr($h->config_hash, 0, 12) }}…
                    </div>
                </a>
                @endforeach
            </div>
        </div>
    </div>
    <div class="col-lg-9">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-dark text-light d-flex justify-content-between align-items-center">
                <span class="small font-monospace">
                    <i class="bi bi-hash me-1"></i>{{ substr($snapshot->config_hash, 0, 16) }}…
                    <span class="ms-3">{{ number_format($snapshot->size_bytes) }} bytes</span>
                </span>
                <button class="btn btn-sm btn-outline-light py-0" onclick="navigator.clipboard.writeText(document.getElementById('cfg').textContent).then(()=>this.innerHTML='<i class=\'bi bi-check\'></i> Copied')">
                    <i class="bi bi-clipboard"></i> Copy
                </button>
            </div>
            <div class="card-body p-0">
                <pre id="cfg" style="background:#111;color:#d4d4d4;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:14px 16px;margin:0;max-height:70vh;overflow:auto;white-space:pre;">{{ $snapshot->config_text }}</pre>
            </div>
        </div>
    </div>
</div>
@endsection
