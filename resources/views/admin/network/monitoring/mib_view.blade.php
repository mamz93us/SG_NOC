@extends('layouts.admin')

@section('content')
<div class="mb-4">
    <a href="{{ route('admin.network.monitoring.mibs') }}" class="btn btn-link link-secondary ps-0">
        <i class="bi bi-arrow-left me-1"></i> Back to MIBs
    </a>
    <div class="d-flex justify-content-between align-items-center mt-2">
        <div>
            <h2 class="h3 mb-1">Preview MIB: {{ $mib->name }}</h2>
            <p class="text-muted small mb-0">Browsing raw content for identification of OIDs and syntax</p>
        </div>
        <div class="badge bg-light text-dark border p-2">
            <i class="bi bi-file-earmark-code me-1"></i> {{ basename($mib->file_path) }}
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <pre class="m-0 p-4 bg-dark text-light rounded-bottom" style="max-height: 70vh; overflow-y: auto; font-size: 0.85rem; line-height: 1.5; font-family: 'SFMono-Regular', Consolas, 'Liberation Mono', Menlo, monospace;">{{ $content }}</pre>
    </div>
</div>

<div class="mt-4 text-center">
    <p class="small text-muted">
        <i class="bi bi-info-circle me-1"></i> This MIB is used by the system to translate OIDs to human-readable names during discovery.
    </p>
</div>
@endsection
