@extends('layouts.admin')
@section('title', 'Switch QoS: Poll Compare')

@section('content')
<div class="mb-3">
    <a href="{{ route('admin.switch-qos.device', urlencode($deviceIp)) }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Device
    </a>
</div>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Poll Compare</h4>
        <small class="text-muted font-monospace">{{ $device?->name ?? $deviceIp }} <span class="text-secondary">({{ $deviceIp }})</span></small>
    </div>
</div>

@if(session('error'))
<div class="alert alert-warning alert-dismissible fade show">{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
@endif

<form method="GET" action="{{ route('admin.switch-qos.compare', urlencode($deviceIp)) }}" class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Previous poll</label>
                <select name="previous" class="form-select form-select-sm">
                    @foreach($timestamps as $t)
                    <option value="{{ $t->format('Y-m-d H:i:s') }}" {{ $t->format('Y-m-d H:i:s') === \Carbon\Carbon::parse($prevAt)->format('Y-m-d H:i:s') ? 'selected' : '' }}>
                        {{ $t->format('Y-m-d H:i:s') }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Current poll</label>
                <select name="current" class="form-select form-select-sm">
                    @foreach($timestamps as $t)
                    <option value="{{ $t->format('Y-m-d H:i:s') }}" {{ $t->format('Y-m-d H:i:s') === \Carbon\Carbon::parse($curAt)->format('Y-m-d H:i:s') ? 'selected' : '' }}>
                        {{ $t->format('Y-m-d H:i:s') }}
                    </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-arrow-repeat me-1"></i>Compare</button>
            </div>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Window</div>
                <div class="fs-5 fw-bold">{{ gmdate('H:i:s', $summary['window_seconds']) }}</div>
                <div class="text-muted small">{{ $summary['window_seconds'] }}s between polls</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 bg-{{ $summary['total_new_drops'] > 0 ? 'danger' : 'success' }} text-white">
            <div class="card-body">
                <div class="small opacity-75 mb-1">New drops in window</div>
                <div class="fs-3 fw-bold">{{ number_format($summary['total_new_drops']) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Interfaces with new drops</div>
                <div class="fs-3 fw-bold text-warning">{{ $summary['interfaces_with_new_drops'] }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Counters reset</div>
                <div class="fs-3 fw-bold text-secondary">{{ $summary['interfaces_reset'] }}</div>
                <div class="text-muted small">reboot or clear cmd</div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold">
        <i class="bi bi-table me-1"></i>Per-Interface Delta
        <small class="text-muted ms-2 fw-normal">{{ \Carbon\Carbon::parse($prevAt)->format('H:i:s') }} → {{ \Carbon\Carbon::parse($curAt)->format('H:i:s') }}</small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light">
                    <tr>
                        <th>Interface</th>
                        <th class="text-center">Q0 Δ</th>
                        <th class="text-center">Q1 Δ</th>
                        <th class="text-center">Q2 Δ</th>
                        <th class="text-center">Q3 Δ</th>
                        <th class="text-end">Prev Total</th>
                        <th class="text-end">Curr Total</th>
                        <th class="text-end">Δ (new drops)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                    @php
                        $q0 = ($r->per_queue['q0_t1_drop'] ?? 0) + ($r->per_queue['q0_t2_drop'] ?? 0) + ($r->per_queue['q0_t3_drop'] ?? 0);
                        $q1 = ($r->per_queue['q1_t1_drop'] ?? 0) + ($r->per_queue['q1_t2_drop'] ?? 0) + ($r->per_queue['q1_t3_drop'] ?? 0);
                        $q2 = ($r->per_queue['q2_t1_drop'] ?? 0) + ($r->per_queue['q2_t2_drop'] ?? 0) + ($r->per_queue['q2_t3_drop'] ?? 0);
                        $q3 = ($r->per_queue['q3_t1_drop'] ?? 0) + ($r->per_queue['q3_t2_drop'] ?? 0) + ($r->per_queue['q3_t3_drop'] ?? 0);
                        $rowClass = $r->reset ? 'table-secondary' : (($r->total_delta ?? 0) > 0 ? 'table-warning' : '');
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="fw-semibold font-monospace">{{ $r->interface_name }}</td>
                        <td class="text-center">{{ $r->reset ? '—' : number_format($q0) }}</td>
                        <td class="text-center">{{ $r->reset ? '—' : number_format($q1) }}</td>
                        <td class="text-center">{{ $r->reset ? '—' : number_format($q2) }}</td>
                        <td class="text-center">{{ $r->reset ? '—' : number_format($q3) }}</td>
                        <td class="text-end text-muted">{{ number_format($r->prev_total) }}</td>
                        <td class="text-end">{{ number_format($r->cur_total) }}</td>
                        <td class="text-end fw-bold">
                            @if($r->reset)
                                <span class="badge bg-secondary">reset</span>
                            @elseif(($r->total_delta ?? 0) === 0)
                                <span class="text-muted">0</span>
                            @else
                                <span class="badge bg-{{ $r->total_delta >= 500 ? 'danger' : ($r->total_delta >= 100 ? 'warning text-dark' : 'info') }}">
                                    +{{ number_format($r->total_delta) }}
                                </span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No matching interfaces between the two polls.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
