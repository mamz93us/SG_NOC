@extends('layouts.admin')
@section('content')

@php
    // Render the param map as KEY=VALUE lines for the editor.
    $paramLines = collect($params ?? [])
        ->map(fn ($v, $k) => is_scalar($v) ? "{$k}={$v}" : "{$k}=".json_encode($v))
        ->implode("\n");
@endphp

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>{{ $template->name }}</h4>
        <small class="text-muted">{{ $template->type }} template · GDMS id {{ $template->gdms_template_id }}</small>
    </div>
    <a href="{{ route('admin.gdms.templates.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show py-2">{{ session('success') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if(session('error'))
<div class="alert alert-danger alert-dismissible fade show py-2">{{ session('error') }}<button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button></div>
@endif
@if($liveError)
<div class="alert alert-warning py-2"><i class="bi bi-cloud-slash me-1"></i>Live template fetch failed (showing cached params): {{ $liveError }}</div>
@endif

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-sliders me-1"></i>Parameters</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.gdms.templates.update', $template) }}">
                    @csrf
                    @method('PUT')
                    <textarea name="params" class="form-control font-monospace small" rows="14" placeholder="P271=1&#10;P47=ucm.example.com">{{ old('params', $paramLines) }}</textarea>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted">One <code>KEY=VALUE</code> per line. Lines starting with <code>#</code> are ignored.</small>
                        <button class="btn btn-sm btn-primary"><i class="bi bi-cloud-upload me-1"></i>Save &amp; Push to GDMS</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="bi bi-broadcast-pin me-1"></i>Assign to Devices</div>
            <div class="card-body">
                <form method="POST" action="{{ route('admin.gdms.templates.assign', $template) }}">
                    @csrf
                    <textarea name="macs" class="form-control font-monospace small" rows="8" placeholder="C0:74:AD:00:11:22&#10;C0:74:AD:00:11:23"></textarea>
                    <div class="d-flex justify-content-between mt-2">
                        <small class="text-muted">One MAC per line (or comma-separated).</small>
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-broadcast me-1"></i>Assign</button>
                    </div>
                </form>
                <hr>
                <small class="text-muted"><i class="bi bi-info-circle me-1"></i>Template endpoints are pending confirmation via <code>gdms:probe</code>; if a push errors, run the probe and adjust the EP_TEMPLATE_* paths in <code>GdmsService</code>.</small>
            </div>
        </div>
    </div>
</div>

@endsection
