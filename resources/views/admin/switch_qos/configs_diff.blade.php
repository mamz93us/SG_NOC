@extends('layouts.admin')
@section('title', 'Config Diff — ' . $device->name)

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Running-Config Diff</h4>
        <small class="text-muted font-monospace">{{ $device->name }} · {{ $device->ip_address }}</small>
    </div>
    <a href="{{ route('admin.switch-qos.configs.show', $device->id) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Config
    </a>
</div>

@if($snapshots->count() < 2)
<div class="alert alert-info">
    <i class="bi bi-info-circle me-1"></i>At least two snapshots are needed to diff. Fetch the config again later to compare.
</div>
@else
<form method="GET" class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div>
            <label class="form-label small mb-0">From (older)</label>
            <select name="from" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach($snapshots as $s)
                <option value="{{ $s->id }}" @selected($from && $from->id === $s->id)>
                    {{ $s->captured_at->format('Y-m-d H:i') }} · {{ substr($s->config_hash, 0, 8) }}
                </option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-0">To (newer)</label>
            <select name="to" class="form-select form-select-sm" onchange="this.form.submit()">
                @foreach($snapshots as $s)
                <option value="{{ $s->id }}" @selected($to && $to->id === $s->id)>
                    {{ $s->captured_at->format('Y-m-d H:i') }} · {{ substr($s->config_hash, 0, 8) }}
                </option>
                @endforeach
            </select>
        </div>
        @php
            $added = collect($diff)->where('op', '+')->count();
            $removed = collect($diff)->where('op', '-')->count();
        @endphp
        <div class="ms-auto d-flex gap-2">
            <span class="badge bg-success">+{{ $added }} added</span>
            <span class="badge bg-danger">-{{ $removed }} removed</span>
        </div>
    </div>
</form>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <pre style="background:#111;color:#d4d4d4;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;padding:0;margin:0;max-height:75vh;overflow:auto;">@foreach($diff as $d)<span style="display:block;padding:0 16px;{{ $d['op'] === '+' ? 'background:#0d3017;color:#7ee787' : ($d['op'] === '-' ? 'background:#311;color:#ff9e9e' : '') }}">{{ $d['op'] }} {{ $d['line'] }}</span>@endforeach</pre>
    </div>
</div>
@endif
@endsection
